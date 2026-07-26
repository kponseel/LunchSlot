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
        $joinToken = random_token(18);
        $minLead = max(0, (int) ($data['min_lead_days'] ?? 0));

        // Date limite : explicite, sinon déduite des créneaux (veille du plus tôt à 12h).
        $deadlineUtc = !empty($data['deadline_local'])
            ? local_to_utc($data['deadline_local'])
            : default_deadline_from_slots($data['slots'] ?? []);

        $st = $pdo->prepare('INSERT INTO lunches (title, location, organizer_email, organizer_name, admin_token, join_token, min_lead_days, status, timezone, deadline, locale, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([
            $data['title'],
            $data['location'] ?? null,
            normalize_email($data['organizer_email']),
            ($data['organizer_name'] ?? '') !== '' ? $data['organizer_name'] : null,
            $adminToken,
            $joinToken,
            $minLead,
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

/* ----------------------------------------------------------------------
 * Lien de participation public (inscription libre) & délais
 * ------------------------------------------------------------------- */

function get_lunch_by_join_token(string $token): ?array
{
    $st = db()->prepare('SELECT * FROM lunches WHERE join_token = ?');
    $st->execute([$token]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Retourne le jeton public, en le générant pour les anciens déjeuners si besoin. */
function ensure_join_token(array $lunch): string
{
    if (!empty($lunch['join_token'])) {
        return $lunch['join_token'];
    }
    $tok = random_token(18);
    db()->prepare('UPDATE lunches SET join_token = ? WHERE id = ?')->execute([$tok, (int) $lunch['id']]);
    return $tok;
}

/** URL publique de participation. */
function join_url(array $lunch): string
{
    return rtrim(config('app_url'), '/') . '/rejoindre.php?j=' . ensure_join_token($lunch);
}

/** L'inscription/réponse est-elle encore ouverte ? */
function join_open(array $lunch): bool
{
    if ($lunch['status'] !== 'en_attente') {
        return false;
    }
    if (!empty($lunch['deadline']) && $lunch['deadline'] <= now_utc()) {
        return false;
    }
    return true;
}

function get_participant_by_email(int $lunchId, string $email): ?array
{
    $st = db()->prepare('SELECT * FROM participants WHERE lunch_id = ? AND email = ? LIMIT 1');
    $st->execute([$lunchId, normalize_email($email)]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Inscrit (ou retrouve) un participant via le lien public.
 * @return array le participant.
 */
function register_self_participant(array $lunch, string $name, string $email): array
{
    $existing = get_participant_by_email((int) $lunch['id'], $email);
    if ($existing) {
        // Met à jour le nom si l'invité en fournit un.
        if (trim($name) !== '' && $name !== $existing['name']) {
            db()->prepare('UPDATE participants SET name = ? WHERE id = ?')->execute([$name, (int) $existing['id']]);
            $existing['name'] = $name;
        }
        return $existing;
    }
    $ins = db()->prepare('INSERT INTO participants (lunch_id, name, email, token, is_organizer, self_registered, created_at) VALUES (?,?,?,?,0,1,?)');
    $ins->execute([(int) $lunch['id'], $name, normalize_email($email), random_token(24), now_utc()]);
    return get_participant((int) db()->lastInsertId());
}

/**
 * Début minimal autorisé pour un créneau (maintenant + délai mini), en UTC.
 * min_lead_days = 0 → seul le passé est interdit.
 */
function slot_min_start_utc(array $lunch): string
{
    $days = max(0, (int) ($lunch['min_lead_days'] ?? 0));
    if ($days <= 0) {
        return now_utc();
    }
    // Minuit local dans N jours, converti en UTC.
    $dt = new DateTime('today +' . $days . ' days', app_tz());
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

/** Un créneau (date+heure locales) respecte-t-il le délai mini ? */
function slot_respects_lead(array $lunch, string $localDate, string $localTime): bool
{
    return local_to_utc($localDate . ' ' . $localTime) >= slot_min_start_utc($lunch);
}

/** Première date locale sélectionnable côté formulaire (YYYY-MM-DD). */
function min_slot_date(array $lunch): string
{
    $dt = utc_dt(slot_min_start_utc($lunch));
    $dt->setTimezone(app_tz());
    return $dt->format('Y-m-d');
}

/**
 * Date limite par défaut déduite des créneaux : la veille du créneau le plus tôt,
 * à 12h locale (jamais dans le passé). Retourne un 'Y-m-d H:i:s' UTC ou null.
 */
function default_deadline_from_slots(array $slots): ?string
{
    $earliest = null;
    foreach ($slots as $s) {
        if (empty($s['date']) || empty($s['time'])) {
            continue;
        }
        $utc = local_to_utc($s['date'] . ' ' . $s['time']);
        if ($earliest === null || $utc < $earliest) {
            $earliest = $utc;
        }
    }
    if ($earliest === null) {
        return null;
    }
    $dl = utc_dt($earliest);
    $dl->setTimezone(app_tz());
    $dl->modify('-1 day')->setTime(12, 0);
    $dlUtc = (clone $dl)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    return $dlUtc > now_utc() ? $dlUtc : null;
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

/** Dernier nom utilisé par cet organisateur (pré-remplissage du formulaire). */
function last_organizer_name(string $email): string
{
    $st = db()->prepare("SELECT organizer_name FROM lunches
        WHERE organizer_email = ? AND organizer_name IS NOT NULL AND organizer_name <> ''
        ORDER BY id DESC LIMIT 1");
    $st->execute([normalize_email($email)]);
    $v = $st->fetchColumn();
    return $v === false ? '' : (string) $v;
}

/** Nom affiché de l'organisateur d'un déjeuner (repli sur un libellé neutre). */
function organizer_display_name(array $lunch): string
{
    $n = trim((string) ($lunch['organizer_name'] ?? ''));
    return $n !== '' ? $n : 'Organisateur';
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
