<?php
/**
 * Logique métier : réponses, unanimité, confirmation automatique, propositions,
 * désistement / réouverture / re-confirmation, annulation.
 */

declare(strict_types=1);

/** Participants d'un déjeuner sous forme d'attendees iCalendar. */
function participants_as_attendees(int $lunchId): array
{
    $out = [];
    foreach (lunch_participants($lunchId) as $p) {
        $out[] = ['email' => $p['email'], 'name' => $p['name']];
    }
    return $out;
}

/**
 * Enregistre les réponses d'un participant, envoie le récap des placeholders
 * (TENTATIVE pour les dispos, CANCEL pour les créneaux retirés), puis évalue
 * la confirmation automatique.
 *
 * @param array $availability [slot_id => bool] disponibilités soumises
 */
function process_response(array $participant, array $availability): void
{
    $lunch = get_lunch((int) $participant['lunch_id']);
    if (!$lunch || $lunch['status'] === 'annule') {
        return;
    }
    $slots = lunch_slots((int) $lunch['id']);

    // État antérieur (pour détecter les créneaux retirés).
    $prior = [];
    foreach ($slots as $s) {
        $prior[(int) $s['id']] = get_response((int) $participant['id'], (int) $s['id']);
    }

    $availableSlots = [];
    $canceledSlots = [];
    foreach ($slots as $s) {
        $sid = (int) $s['id'];
        if (!array_key_exists($sid, $availability)) {
            continue; // pas soumis : on ne touche pas
        }
        $now = $availability[$sid];
        set_response((int) $participant['id'], $sid, $now);
        if ($now) {
            $availableSlots[] = $s;
        } elseif (($prior[$sid] ?? null) === 1) {
            $canceledSlots[] = $s; // était bloqué, on l'annule
        }
    }

    if ($availableSlots || $canceledSlots) {
        send_placeholders($lunch, $participant, $availableSlots, $canceledSlots);
    }

    evaluate_and_confirm((int) $lunch['id']);
}

/**
 * Un participant propose un nouveau créneau ; notifie les autres + organisateur.
 * @return bool false si le créneau ne respecte pas le délai mini (rien créé).
 */
function propose_slot(array $participant, string $date, string $time, int $duration): bool
{
    $lunch = get_lunch((int) $participant['lunch_id']);
    if (!$lunch || $lunch['status'] !== 'en_attente') {
        return false;
    }
    if (!slot_respects_lead($lunch, $date, $time)) {
        return false;
    }
    // Doublon exact : on ne recrée pas, on marque juste le proposeur disponible.
    foreach (lunch_slots((int) $lunch['id']) as $ex) {
        if ($ex['start_utc'] === local_to_utc($date . ' ' . $time)) {
            set_response((int) $participant['id'], (int) $ex['id'], true);
            send_placeholders($lunch, $participant, [$ex], []);
            evaluate_and_confirm((int) $lunch['id']);
            return true;
        }
    }
    $slotId = add_slot_to_lunch((int) $lunch['id'], $date, $time, $duration, (int) $participant['id']);
    // Le proposeur est auto-marqué disponible.
    set_response((int) $participant['id'], $slotId, true);
    $slot = get_slot($slotId);
    send_placeholders($lunch, $participant, [$slot], []);

    // Destinataires = autres participants + organisateur pur.
    $recipients = [];
    foreach (lunch_participants((int) $lunch['id']) as $p) {
        if ((int) $p['id'] !== (int) $participant['id']) {
            $recipients[] = $p;
        }
    }
    if (!organizer_is_participant($lunch)) {
        $recipients[] = ['name' => 'Organisateur', 'email' => $lunch['organizer_email'], 'token' => null];
    }
    if ($recipients) {
        send_new_slot_notice($lunch, $slot, $recipients, $participant['name']);
    }

    // Un nouveau créneau ne peut pas rendre l'unanimité tout seul, mais on réévalue par sûreté.
    evaluate_and_confirm((int) $lunch['id']);
    return true;
}

function organizer_is_participant(array $lunch): bool
{
    $st = db()->prepare('SELECT 1 FROM participants WHERE lunch_id = ? AND email = ? LIMIT 1');
    $st->execute([(int) $lunch['id'], normalize_email($lunch['organizer_email'])]);
    return (bool) $st->fetchColumn();
}

/** Renvoie l'id du créneau unanime le plus tôt, ou null. */
function find_unanimous_slot(int $lunchId): ?int
{
    $participants = lunch_participants($lunchId);
    $n = count($participants);
    if ($n === 0) {
        return null;
    }
    $slots = lunch_slots($lunchId); // déjà triés par start_utc
    $matrix = response_matrix($lunchId);
    foreach ($slots as $s) {
        $sid = (int) $s['id'];
        $ok = true;
        foreach ($participants as $p) {
            if (($matrix[(int) $p['id']][$sid] ?? 0) !== 1) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return $sid;
        }
    }
    return null;
}

/** Évalue l'unanimité et confirme automatiquement le créneau le plus tôt. */
function evaluate_and_confirm(int $lunchId): bool
{
    $lunch = get_lunch($lunchId);
    if (!$lunch || $lunch['status'] !== 'en_attente') {
        return false;
    }
    $slotId = find_unanimous_slot($lunchId);
    if ($slotId === null) {
        return false;
    }
    return confirm_lunch($lunchId, $slotId, false);
}

/**
 * Confirme un déjeuner sur un créneau. Bascule atomique et idempotente :
 * l'UPDATE gardé sur status='en_attente' garantit une seule confirmation.
 */
function confirm_lunch(int $lunchId, int $slotId, bool $manual): bool
{
    $pdo = db();
    $st = $pdo->prepare("UPDATE lunches SET status = 'confirme', confirmed_slot_id = ?
        WHERE id = ? AND status = 'en_attente'");
    $st->execute([$slotId, $lunchId]);
    if ($st->rowCount() === 0) {
        return false; // déjà confirmé/annulé par un autre appel
    }

    $lunch = get_lunch($lunchId);
    $slot = get_slot($slotId);
    $participants = lunch_participants($lunchId);
    $attendees = participants_as_attendees($lunchId);

    foreach ($participants as $p) {
        $cancels = build_placeholder_cancels($lunch, $p);
        send_confirmation($lunch, $slot, $p, $attendees, false, $cancels);
    }
    // Organisateur destinataire (s'il n'est pas déjà participant).
    if (!organizer_is_participant($lunch)) {
        send_confirmation($lunch, $slot, ['name' => 'Organisateur', 'email' => $lunch['organizer_email']], $attendees, true, []);
    }
    log_mail_event($lunchId, $manual ? 'confirm_manual' : 'confirm_auto');
    return true;
}

/** Construit les CANCEL des placeholders encore actifs d'un participant. */
function build_placeholder_cancels(array $lunch, array $participant): array
{
    $cancels = [];
    foreach (lunch_slots((int) $lunch['id']) as $s) {
        $uid = placeholder_uid((int) $lunch['id'], (int) $participant['id'], (int) $s['id']);
        // Actif seulement s'il a déjà été émis et non annulé.
        $chk = db()->prepare("SELECT last_method FROM ics_state WHERE uid = ?");
        $chk->execute([$uid]);
        $lm = $chk->fetchColumn();
        if ($lm === false || $lm === 'CANCEL') {
            continue;
        }
        $seq = ics_bump_sequence($uid, 'CANCEL');
        $ics = ics_build([
            'method' => 'CANCEL',
            'uid' => $uid,
            'sequence' => $seq,
            'status' => 'CANCELLED',
            'start_utc' => $s['start_utc'],
            'duration_min' => (int) $s['duration_min'],
            'summary' => '[Provisoire] ' . $lunch['title'],
            'location' => $lunch['location'] ?? '',
            'organizer_email' => $lunch['organizer_email'],
            'organizer_name' => organizer_display_name($lunch),
            'attendees' => [['email' => $participant['email'], 'name' => $participant['name']]],
        ]);
        $cancels[] = [
            'slot_id' => (int) $s['id'],
            'ics' => $ics,
            'label' => fmt_slot($s['start_utc'], (int) $s['duration_min']),
        ];
    }
    return $cancels;
}

/**
 * Désistement d'un participant après confirmation :
 * CANCEL (même UID, SEQUENCE+1) à tous, réouverture, puis re-confirmation auto.
 */
function withdraw_participant(array $participant): bool
{
    $lunch = get_lunch((int) $participant['lunch_id']);
    if (!$lunch || $lunch['status'] !== 'confirme' || !$lunch['confirmed_slot_id']) {
        return false;
    }
    $slot = get_slot((int) $lunch['confirmed_slot_id']);

    // Le participant devient indisponible sur le créneau confirmé (empêche toute re-confirmation dessus).
    set_response((int) $participant['id'], (int) $slot['id'], false);

    // CANCEL de l'événement confirmé (même UID, SEQUENCE+1) — identique pour tous.
    $uid = event_uid((int) $lunch['id'], (int) $slot['id']);
    $seq = ics_bump_sequence($uid, 'CANCEL');
    $cancelIcs = ics_build([
        'method' => 'CANCEL',
        'uid' => $uid,
        'sequence' => $seq,
        'status' => 'CANCELLED',
        'start_utc' => $slot['start_utc'],
        'duration_min' => (int) $slot['duration_min'],
        'summary' => $lunch['title'],
        'location' => $lunch['location'] ?? '',
        'organizer_email' => $lunch['organizer_email'],
        'organizer_name' => organizer_display_name($lunch),
        'attendees' => participants_as_attendees((int) $lunch['id']),
    ]);

    // Réouverture.
    db()->prepare("UPDATE lunches SET status = 'en_attente', confirmed_slot_id = NULL WHERE id = ?")
        ->execute([(int) $lunch['id']]);
    $lunch = get_lunch((int) $lunch['id']);

    // Notifie tout le monde (annulation jointe).
    foreach (lunch_participants((int) $lunch['id']) as $p) {
        send_reopen_notice($lunch, $p, $participant['name'], $cancelIcs, false);
    }
    if (!organizer_is_participant($lunch)) {
        send_reopen_notice($lunch, ['name' => 'Organisateur', 'email' => $lunch['organizer_email']], $participant['name'], $cancelIcs, true);
    }
    log_mail_event((int) $lunch['id'], 'withdraw', $participant['email']);

    // Re-confirmation immédiate si un autre créneau est déjà unanime.
    evaluate_and_confirm((int) $lunch['id']);
    return true;
}

/** Annulation du déjeuner par l'organisateur : CANCEL calendrier à tous. */
function cancel_lunch(array $lunch): void
{
    $wasConfirmed = ($lunch['status'] === 'confirme' && $lunch['confirmed_slot_id']);
    $cancelIcs = '';
    if ($wasConfirmed) {
        $slot = get_slot((int) $lunch['confirmed_slot_id']);
        $uid = event_uid((int) $lunch['id'], (int) $slot['id']);
        $seq = ics_bump_sequence($uid, 'CANCEL');
        $cancelIcs = ics_build([
            'method' => 'CANCEL',
            'uid' => $uid,
            'sequence' => $seq,
            'status' => 'CANCELLED',
            'start_utc' => $slot['start_utc'],
            'duration_min' => (int) $slot['duration_min'],
            'summary' => $lunch['title'],
            'location' => $lunch['location'] ?? '',
            'organizer_email' => $lunch['organizer_email'],
            'organizer_name' => organizer_display_name($lunch),
            'attendees' => participants_as_attendees((int) $lunch['id']),
        ]);
    }
    db()->prepare("UPDATE lunches SET status = 'annule' WHERE id = ?")->execute([(int) $lunch['id']]);

    foreach (lunch_participants((int) $lunch['id']) as $p) {
        send_cancellation($lunch, $p, $cancelIcs, false);
    }
    if (!organizer_is_participant($lunch)) {
        send_cancellation($lunch, ['name' => 'Organisateur', 'email' => $lunch['organizer_email']], $cancelIcs, true);
    }
    log_mail_event((int) $lunch['id'], 'cancel');
}

/** Confirmation manuelle possible seulement si personne n'a refusé ce créneau. */
function slot_has_refusal(int $lunchId, int $slotId): bool
{
    $st = db()->prepare('SELECT 1 FROM responses r JOIN participants p ON p.id = r.participant_id
        WHERE p.lunch_id = ? AND r.slot_id = ? AND r.available = 0 LIMIT 1');
    $st->execute([$lunchId, $slotId]);
    return (bool) $st->fetchColumn();
}
