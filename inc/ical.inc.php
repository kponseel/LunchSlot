<?php
/**
 * Génération iCalendar (RFC 5545) : placeholders TENTATIVE, invitations REQUEST,
 * annulations CANCEL. UID déterministes + suivi de séquence par UID.
 */

declare(strict_types=1);

/** UID stable d'un placeholder (couple participant × créneau). */
function placeholder_uid(int $lunchId, int $participantId, int $slotId): string
{
    return sprintf('ph-%d-%d-%d@%s', $lunchId, $participantId, $slotId, config('ics_domain'));
}

/** UID stable de l'événement confirmé (couple déjeuner × créneau). */
function event_uid(int $lunchId, int $slotId): string
{
    return sprintf('ev-%d-%d@%s', $lunchId, $slotId, config('ics_domain'));
}

/**
 * Calcule et enregistre la SEQUENCE à utiliser pour cette émission.
 * Première émission d'un UID = 0 ; chaque émission suivante = +1.
 */
function ics_bump_sequence(string $uid, string $method): int
{
    $pdo = db();
    $row = $pdo->prepare('SELECT sequence FROM ics_state WHERE uid = ?');
    $row->execute([$uid]);
    $current = $row->fetchColumn();

    if ($current === false) {
        $seq = 0;
        $ins = $pdo->prepare('INSERT INTO ics_state (uid, sequence, last_method, updated_at) VALUES (?,?,?,?)');
        $ins->execute([$uid, $seq, $method, now_utc()]);
    } else {
        $seq = ((int) $current) + 1;
        $up = $pdo->prepare('UPDATE ics_state SET sequence = ?, last_method = ?, updated_at = ? WHERE uid = ?');
        $up->execute([$seq, $method, now_utc(), $uid]);
    }
    return $seq;
}

/** Liste les UID de placeholders encore actifs (non annulés) pour un déjeuner. */
function active_placeholder_uids(int $lunchId): array
{
    $pdo = db();
    $prefix = sprintf('ph-%d-', $lunchId);
    $st = $pdo->prepare("SELECT uid FROM ics_state WHERE uid LIKE ? AND (last_method IS NULL OR last_method <> 'CANCEL')");
    $st->execute([$prefix . '%']);
    return array_column($st->fetchAll(), 'uid');
}

/** Échappement d'une valeur texte iCalendar. */
function ics_escape(string $text): string
{
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace(["\r\n", "\n", "\r"], '\\n', $text);
    $text = str_replace(',', '\\,', $text);
    $text = str_replace(';', '\\;', $text);
    return $text;
}

/** Pliage des lignes à 75 octets (RFC 5545). */
function ics_fold(string $line): string
{
    $out = '';
    $len = 0;
    $limit = 73; // marge pour l'espace de continuation
    $chars = str_split($line);
    foreach ($chars as $ch) {
        if ($len >= $limit) {
            $out .= "\r\n ";
            $len = 1;
        }
        $out .= $ch;
        $len++;
    }
    return $out;
}

/**
 * Construit un VCALENDAR complet (un VEVENT).
 *
 * @param array $p Clés : method, uid, sequence, status, start_utc, duration_min,
 *                 summary, description, location, organizer_email, organizer_name,
 *                 attendees (liste de ['email'=>, 'name'=>]).
 */
function ics_build(array $p): string
{
    $tzUtc = new DateTimeZone('UTC');
    $start = new DateTime($p['start_utc'], $tzUtc);
    $end = clone $start;
    $end->modify('+' . (int) $p['duration_min'] . ' minutes');

    $lines = [];
    $lines[] = 'BEGIN:VCALENDAR';
    $lines[] = 'PRODID:-//LunchSpot//FR';
    $lines[] = 'VERSION:2.0';
    $lines[] = 'CALSCALE:GREGORIAN';
    $lines[] = 'METHOD:' . $p['method'];
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:' . $p['uid'];
    $lines[] = 'SEQUENCE:' . (int) $p['sequence'];
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
    $lines[] = 'DTSTART:' . $start->format('Ymd\THis\Z');
    $lines[] = 'DTEND:' . $end->format('Ymd\THis\Z');
    $lines[] = 'STATUS:' . $p['status'];
    $lines[] = 'SUMMARY:' . ics_escape($p['summary']);
    if (!empty($p['description'])) {
        $lines[] = 'DESCRIPTION:' . ics_escape($p['description']);
    }
    if (!empty($p['location'])) {
        $lines[] = 'LOCATION:' . ics_escape($p['location']);
    }

    $orgName = $p['organizer_name'] ?? 'Organisateur';
    $lines[] = 'ORGANIZER;CN=' . ics_escape($orgName) . ':mailto:' . $p['organizer_email'];

    foreach ($p['attendees'] ?? [] as $a) {
        $partstat = ($p['status'] === 'CONFIRMED') ? 'ACCEPTED'
            : (($p['status'] === 'TENTATIVE') ? 'TENTATIVE' : 'DECLINED');
        $lines[] = 'ATTENDEE;CN=' . ics_escape($a['name'] ?? $a['email'])
            . ';ROLE=REQ-PARTICIPANT;PARTSTAT=' . $partstat
            . ';RSVP=TRUE:mailto:' . $a['email'];
    }

    if ($p['method'] === 'CANCEL' || $p['status'] === 'CANCELLED') {
        $lines[] = 'TRANSP:TRANSPARENT';
    } else {
        $lines[] = 'TRANSP:OPAQUE';
    }
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    $folded = array_map('ics_fold', $lines);
    return implode("\r\n", $folded) . "\r\n";
}
