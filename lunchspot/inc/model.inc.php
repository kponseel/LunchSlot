<?php
/**
 * Accès aux données (lecture/écriture). Aucune logique d'email ici.
 */

declare(strict_types=1);

function get_lunch(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM lunches WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function get_lunch_by_admin_token(string $token): ?array
{
    $st = db()->prepare('SELECT * FROM lunches WHERE admin_token = ?');
    $st->execute([$token]);
    $row = $st->fetch();
    return $row ?: null;
}

function get_participant_by_token(string $token): ?array
{
    $st = db()->prepare('SELECT * FROM participants WHERE token = ?');
    $st->execute([$token]);
    $row = $st->fetch();
    return $row ?: null;
}

function get_participant(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM participants WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function get_slot(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM slots WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Tous les participants (= votants) d'un déjeuner. */
function lunch_participants(int $lunchId): array
{
    $st = db()->prepare('SELECT * FROM participants WHERE lunch_id = ? ORDER BY id');
    $st->execute([$lunchId]);
    return $st->fetchAll();
}

/** Créneaux d'un déjeuner, triés par date de début. */
function lunch_slots(int $lunchId): array
{
    $st = db()->prepare('SELECT * FROM slots WHERE lunch_id = ? ORDER BY start_utc, id');
    $st->execute([$lunchId]);
    return $st->fetchAll();
}

/** Matrice des réponses : [participant_id][slot_id] => 0|1 (absent si non répondu). */
function response_matrix(int $lunchId): array
{
    $st = db()->prepare('SELECT r.participant_id, r.slot_id, r.available
        FROM responses r JOIN participants p ON p.id = r.participant_id
        WHERE p.lunch_id = ?');
    $st->execute([$lunchId]);
    $m = [];
    foreach ($st->fetchAll() as $r) {
        $m[(int) $r['participant_id']][(int) $r['slot_id']] = (int) $r['available'];
    }
    return $m;
}

/** Enregistre/mets à jour une réponse (disponible = true/false). */
function set_response(int $participantId, int $slotId, bool $available): void
{
    $st = db()->prepare('INSERT INTO responses (participant_id, slot_id, available, updated_at)
        VALUES (?,?,?,?)
        ON CONFLICT(participant_id, slot_id) DO UPDATE SET available = excluded.available, updated_at = excluded.updated_at');
    $st->execute([$participantId, $slotId, $available ? 1 : 0, now_utc()]);
}

function get_response(int $participantId, int $slotId): ?int
{
    $st = db()->prepare('SELECT available FROM responses WHERE participant_id = ? AND slot_id = ?');
    $st->execute([$participantId, $slotId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

/** Un participant a-t-il répondu à TOUS les créneaux ? */
function participant_complete(int $participantId, array $slots): bool
{
    if (!$slots) {
        return false;
    }
    $st = db()->prepare('SELECT COUNT(*) FROM responses WHERE participant_id = ?');
    $st->execute([$participantId]);
    return ((int) $st->fetchColumn()) >= count($slots);
}

/**
 * Crée un déjeuner complet.
 *
 * @param array $data title, location, organizer_email, organizer_name,
 *                    organizer_participates(bool), deadline_local(string|null),
 *                    participants (liste ['name','email']),
 *                    slots (liste ['date','time','duration']).
 * @return array ['lunch'=>row, 'participants'=>rows]
 */
function create_lunch(array $data): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $adminToken = random_token(24);
        $deadlineUtc = !empty($data['deadline_local']) ? local_to_utc($data['deadline_local']) : null;

        $st = $pdo->prepare('INSERT INTO lunches (title, location, organizer_email, admin_token, status, timezone, deadline, locale, created_at)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([
            $data['title'],
            $data['location'] ?? null,
            normalize_email($data['organizer_email']),
            $adminToken,
            'en_attente',
            config('timezone', 'Europe/Paris'),
            $deadlineUtc,
            $data['locale'] ?? 'fr',
            now_utc(),
        ]);
        $lunchId = (int) $pdo->lastInsertId();

        // Créneaux.
        foreach ($data['slots'] as $s) {
            $startUtc = local_to_utc($s['date'] . ' ' . $s['time']);
            $ins = $pdo->prepare('INSERT INTO slots (lunch_id, start_utc, duration_min, proposed_by, created_at) VALUES (?,?,?,?,?)');
            $ins->execute([$lunchId, $startUtc, (int) ($s['duration'] ?? 60), null, now_utc()]);
        }

        // Participants.
        foreach ($data['participants'] as $p) {
            $ins = $pdo->prepare('INSERT INTO participants (lunch_id, name, email, token, is_organizer, created_at) VALUES (?,?,?,?,0,?)');
            $ins->execute([$lunchId, $p['name'], normalize_email($p['email']), random_token(24), now_utc()]);
        }

        // L'organisateur participe aussi ?
        if (!empty($data['organizer_participates'])) {
            $ins = $pdo->prepare('INSERT INTO participants (lunch_id, name, email, token, is_organizer, created_at) VALUES (?,?,?,?,1,?)');
            $ins->execute([
                $lunchId,
                $data['organizer_name'] ?: 'Organisateur',
                normalize_email($data['organizer_email']),
                random_token(24),
                now_utc(),
            ]);
        }

        // Rattache/assure l'organisateur.
        ensure_organizer(normalize_email($data['organizer_email']));

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'lunch'        => get_lunch($lunchId),
        'participants' => lunch_participants($lunchId),
    ];
}

/** Ajoute un créneau à un déjeuner existant. */
function add_slot_to_lunch(int $lunchId, string $localDate, string $localTime, int $duration, ?int $proposedBy = null): int
{
    $startUtc = local_to_utc($localDate . ' ' . $localTime);
    $ins = db()->prepare('INSERT INTO slots (lunch_id, start_utc, duration_min, proposed_by, created_at) VALUES (?,?,?,?,?)');
    $ins->execute([$lunchId, $startUtc, $duration, $proposedBy, now_utc()]);
    return (int) db()->lastInsertId();
}

function ensure_organizer(string $email): void
{
    $email = normalize_email($email);
    $st = db()->prepare('SELECT id FROM organizers WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetchColumn() === false) {
        $ins = db()->prepare('INSERT INTO organizers (email, created_at) VALUES (?,?)');
        $ins->execute([$email, now_utc()]);
    }
}

/** Déjeuners d'un organisateur (par email normalisé). */
function lunches_for_organizer(string $email): array
{
    $st = db()->prepare('SELECT * FROM lunches WHERE organizer_email = ? ORDER BY created_at DESC');
    $st->execute([normalize_email($email)]);
    return $st->fetchAll();
}

function log_mail_event(?int $lunchId, string $kind, ?string $recipient = null): void
{
    $ins = db()->prepare('INSERT INTO mail_events (lunch_id, kind, recipient, created_at) VALUES (?,?,?,?)');
    $ins->execute([$lunchId, $kind, $recipient, now_utc()]);
}

function mail_event_exists(?int $lunchId, string $kind): bool
{
    $st = db()->prepare('SELECT 1 FROM mail_events WHERE lunch_id = ? AND kind = ? LIMIT 1');
    $st->execute([$lunchId, $kind]);
    return (bool) $st->fetchColumn();
}
