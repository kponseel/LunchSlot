<?php
/**
 * Suite de tests LunchSpot (cœur métier + cas limites), sans dépendance.
 *
 *   php lunchspot/tests/run.php
 *
 * Isolation : base SQLite + journaux mail dans un dossier temporaire dédié
 * (variable d'environnement LUNCHSPOT_DB) — n'altère jamais les données de dev.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/lunchspot-test-' . getmypid();
@mkdir($tmp, 0770, true);
putenv('LUNCHSPOT_DB=' . $tmp . '/test.sqlite');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Tampon de sortie : la suite écrit à l'écran ET exerce du code qui pose des
// cookies (setcookie). En CLI, bufferiser évite le warning « headers already
// sent » — sans incidence en web où le cookie est posé avant tout rendu.
ob_start();
register_shutdown_function(function () { while (ob_get_level() > 0) { ob_end_flush(); } });

require __DIR__ . '/../inc/bootstrap.inc.php';

$FAIL = 0;
function check(string $label, bool $cond): void
{
    global $FAIL;
    echo ($cond ? "  \033[32m✅\033[0m " : "  \033[31m❌\033[0m ") . $label . "\n";
    if (!$cond) {
        $FAIL++;
    }
}
function mfiles(): array
{
    $d = data_dir() . '/maillog';
    return is_dir($d) ? glob($d . '/*.eml') : [];
}
function dec(string $file): string
{
    $raw = file_get_contents($file);
    $out = $raw . "\n" . mb_decode_mimeheader($raw) . "\n" . quoted_printable_decode($raw);
    if (preg_match_all('/(?:^[A-Za-z0-9+\/=]{40,}\r?\n)+/m', $raw, $mm)) {
        foreach ($mm[0] as $b) {
            $x = base64_decode(preg_replace('/\s+/', '', $b), true);
            if ($x !== false) {
                $out .= "\n" . $x;
            }
        }
    }
    return $out;
}
function grepc(string $n): int
{
    $c = 0;
    foreach (mfiles() as $f) {
        if (strpos(dec($f), $n) !== false) {
            $c++;
        }
    }
    return $c;
}
function last_dec(): string
{
    $fs = mfiles();
    if (!$fs) {
        return '';
    }
    usort($fs, fn($a, $b) => filemtime($a) <=> filemtime($b));
    return dec(end($fs));
}
function reset_all(): void
{
    foreach (mfiles() as $f) {
        unlink($f);
    }
    foreach (['responses', 'slots', 'participants', 'lunches', 'ics_state', 'mail_events', 'magic_links', 'sessions', 'organizers', 'magic_ip_hits'] as $t) {
        db()->exec("DELETE FROM $t");
    }
}

/* ================= CŒUR MÉTIER ================= */
echo "== 1. Magic link ==\n";
reset_all();
issue_magic_link('orga@example.com');
$token = null;
foreach (mfiles() as $f) {
    if (preg_match('/verify\.php\?token=([A-Za-z0-9_-]+)/', quoted_printable_decode(file_get_contents($f)), $m)) {
        $token = $m[1];
    }
}
check('email magic link + token', $token !== null);
$org = consume_magic_link((string) $token);
check('connexion réussie', $org !== null && $org['email'] === 'orga@example.com');
check('lien rejoué → refusé', consume_magic_link((string) $token) === null);
check('token bidon → refusé', consume_magic_link('inexistant') === null);

echo "\n== 2-4. Création → réponses → placeholders → confirmation auto ==\n";
$res = create_lunch([
    'title' => 'Déjeuner Acme', 'location' => 'Le Bistrot', 'organizer_email' => 'orga@example.com',
    'organizer_name' => 'Orga', 'organizer_participates' => false, 'deadline_local' => null, 'locale' => 'fr',
    'participants' => [['name' => 'Alice', 'email' => 'alice@ex.com'], ['name' => 'Bob', 'email' => 'bob@ex.com'], ['name' => 'Carol', 'email' => 'carol@ex.com']],
    'slots' => [['date' => '2026-09-15', 'time' => '12:30', 'duration' => 90], ['date' => '2026-09-16', 'time' => '13:00', 'duration' => 90]],
]);
$lunch = $res['lunch'];
foreach ($res['participants'] as $p) {
    if ((int) $p['is_organizer'] === 0) {
        send_participant_invite($lunch, $p);
    }
}
[$A, $B, $C] = lunch_participants((int) $lunch['id']);
[$S1, $S2] = lunch_slots((int) $lunch['id']);
check('2 créneaux triés (S1 avant S2)', $S1['start_utc'] < $S2['start_utc']);
check('3 invitations', grepc('Invitation') >= 3);
process_response($A, [(int) $S1['id'] => true, (int) $S2['id'] => true]);
check('placeholder TENTATIVE', grepc('STATUS:TENTATIVE') >= 1);
check('lien Google', grepc('calendar.google.com/calendar/render') >= 1);
check('UID placeholder déterministe', grepc('ph-' . $lunch['id'] . '-' . $A['id'] . '-' . $S1['id']) >= 1);
process_response($B, [(int) $S1['id'] => true, (int) $S2['id'] => true]);
check('pas confirmé (Carol manque)', get_lunch((int) $lunch['id'])['status'] === 'en_attente');
process_response($C, [(int) $S1['id'] => true, (int) $S2['id'] => true]);
$lc = get_lunch((int) $lunch['id']);
check('confirmé automatiquement', $lc['status'] === 'confirme');
check('créneau le plus tôt retenu (S1)', (int) $lc['confirmed_slot_id'] === (int) $S1['id']);
check('invitation REQUEST', grepc('METHOD:REQUEST') >= 1);
check('event UID confirmé', grepc('ev-' . $lunch['id'] . '-' . $S1['id']) >= 1);
check('CANCEL des placeholders', grepc('METHOD:CANCEL') >= 1);

echo "\n== 5. Désistement → réouverture → re-confirmation ==\n";
withdraw_participant($A);
$lw = get_lunch((int) $lunch['id']);
check('re-confirmé sur un autre créneau', $lw['status'] === 'confirme');
check('nouveau créneau = S2', (int) $lw['confirmed_slot_id'] === (int) $S2['id']);
$seq = db()->prepare('SELECT sequence,last_method FROM ics_state WHERE uid = ?');
$seq->execute(['ev-' . $lunch['id'] . '-' . $S1['id'] . '@' . config('ics_domain')]);
$st = $seq->fetch();
check('CANCEL event S1 avec SEQUENCE+1', $st && $st['last_method'] === 'CANCEL' && (int) $st['sequence'] >= 1);

echo "\n== 6. Proposition de créneau (email EN) ==\n";
$res2 = create_lunch([
    'title' => 'Point projet', 'organizer_email' => 'orga@example.com', 'organizer_name' => 'Orga',
    'organizer_participates' => false, 'deadline_local' => null, 'locale' => 'en',
    'participants' => [['name' => 'Dan', 'email' => 'dan@ex.com'], ['name' => 'Eve', 'email' => 'eve@ex.com']],
    'slots' => [['date' => '2026-10-01', 'time' => '12:00', 'duration' => 60]],
]);
[$D] = lunch_participants((int) $res2['lunch']['id']);
$before = grepc('New slot');
propose_slot($D, '2026-10-02', '12:30', 60);
check('email « nouveau créneau » envoyé', grepc('New slot') > $before);
check('email en anglais (déjeuner EN)', grepc('Weigh in') >= 1 || grepc('proposed a new slot') >= 1);

echo "\n== 7. Cron : rapport d'échéance unique ==\n";
$res3 = create_lunch([
    'title' => 'Retard', 'organizer_email' => 'orga@example.com', 'organizer_name' => 'Orga',
    'organizer_participates' => false, 'deadline_local' => null, 'locale' => 'fr',
    'participants' => [['name' => 'Fay', 'email' => 'fay@ex.com']],
    'slots' => [['date' => '2026-12-01', 'time' => '12:00', 'duration' => 60]],
]);
$l3 = $res3['lunch'];
db()->prepare('UPDATE lunches SET deadline = ? WHERE id = ?')->execute([utc_plus(-3600), (int) $l3['id']]);
// Simule un passage de cron (logique du script).
$missing = array_filter(lunch_participants((int) $l3['id']), fn($p) => (int) $p['is_organizer'] === 0 && !participant_complete((int) $p['id'], lunch_slots((int) $l3['id'])));
send_deadline_report($l3, array_values($missing));
log_mail_event((int) $l3['id'], 'deadline_report');
check('rapport d\'échéance envoyé', grepc('Échéance') >= 1);
check('marqué émis (pas de doublon)', mail_event_exists((int) $l3['id'], 'deadline_report'));

/* ================= CAS LIMITES ================= */
echo "\n== A. Fuseau Europe/Paris → UTC exact ==\n";
reset_all();
$r = create_lunch(['title' => 'TZ', 'organizer_email' => 'o@ex.com', 'organizer_name' => 'O', 'organizer_participates' => false,
    'deadline_local' => null, 'locale' => 'fr', 'participants' => [['name' => 'A', 'email' => 'a@ex.com']],
    'slots' => [['date' => '2026-09-15', 'time' => '12:30', 'duration' => 90]]]);
$l = $r['lunch']; [$A] = lunch_participants((int) $l['id']); [$S] = lunch_slots((int) $l['id']);
process_response($A, [(int) $S['id'] => true]);
check('n=1 → confirmé', get_lunch((int) $l['id'])['status'] === 'confirme');
$d = last_dec();
check('DTSTART = 10:30Z (12:30 Paris CEST)', strpos($d, 'DTSTART:20260915T103000Z') !== false);
check('DTEND = 12:00Z (+90 min)', strpos($d, 'DTEND:20260915T120000Z') !== false);
check('lien Google en UTC', strpos($d, '20260915T103000Z%2F20260915T120000Z') !== false);

echo "\n== B. Idempotence confirmation ==\n";
check('2e confirm_lunch → false', confirm_lunch((int) $l['id'], (int) $S['id'], false) === false);

echo "\n== C. Organisateur votant ==\n";
reset_all();
$r = create_lunch(['title' => 'Vote', 'organizer_email' => 'boss@ex.com', 'organizer_name' => 'Boss', 'organizer_participates' => true,
    'deadline_local' => null, 'locale' => 'fr', 'participants' => [['name' => 'A', 'email' => 'a@ex.com']],
    'slots' => [['date' => '2026-09-20', 'time' => '12:00', 'duration' => 60]]]);
$l = $r['lunch']; $ps = lunch_participants((int) $l['id']); [$S] = lunch_slots((int) $l['id']);
check('organisateur = 2e votant', count($ps) === 2);
$Aa = null; $Boss = null;
foreach ($ps as $p) { if ($p['email'] === 'a@ex.com') $Aa = $p; if ((int) $p['is_organizer'] === 1) $Boss = $p; }
process_response($Aa, [(int) $S['id'] => true]);
check('pas confirmé sans le vote organisateur', get_lunch((int) $l['id'])['status'] === 'en_attente');
process_response($Boss, [(int) $S['id'] => true]);
check('confirmé après vote organisateur', get_lunch((int) $l['id'])['status'] === 'confirme');

echo "\n== D. Désistement sans autre unanimité → reste rouvert ==\n";
reset_all();
$r = create_lunch(['title' => 'Solo', 'organizer_email' => 'o@ex.com', 'organizer_name' => 'O', 'organizer_participates' => false,
    'deadline_local' => null, 'locale' => 'fr', 'participants' => [['name' => 'A', 'email' => 'a@ex.com'], ['name' => 'B', 'email' => 'b@ex.com']],
    'slots' => [['date' => '2026-09-25', 'time' => '12:00', 'duration' => 60]]]);
$l = $r['lunch']; [$A, $B] = lunch_participants((int) $l['id']); [$S] = lunch_slots((int) $l['id']);
process_response($A, [(int) $S['id'] => true]); process_response($B, [(int) $S['id'] => true]);
check('confirmé', get_lunch((int) $l['id'])['status'] === 'confirme');
withdraw_participant($A);
$lw = get_lunch((int) $l['id']);
check('rouvert, pas de re-confirmation', $lw['status'] === 'en_attente' && $lw['confirmed_slot_id'] === null);

echo "\n== E. Confirmation manuelle (refus vs sans refus) ==\n";
reset_all();
$r = create_lunch(['title' => 'Manuel', 'organizer_email' => 'o@ex.com', 'organizer_name' => 'O', 'organizer_participates' => false,
    'deadline_local' => null, 'locale' => 'fr', 'participants' => [['name' => 'A', 'email' => 'a@ex.com'], ['name' => 'B', 'email' => 'b@ex.com']],
    'slots' => [['date' => '2026-10-05', 'time' => '12:00', 'duration' => 60], ['date' => '2026-10-06', 'time' => '12:00', 'duration' => 60]]]);
$l = $r['lunch']; [$A, $B] = lunch_participants((int) $l['id']); [$S1, $S2] = lunch_slots((int) $l['id']);
process_response($A, [(int) $S1['id'] => true, (int) $S2['id'] => false]);
check('S2 a un refus', slot_has_refusal((int) $l['id'], (int) $S2['id']) === true);
check('S1 sans refus', slot_has_refusal((int) $l['id'], (int) $S1['id']) === false);
check('confirmation manuelle S1 OK', confirm_lunch((int) $l['id'], (int) $S1['id'], true) === true);

echo "\n== F. Retrait d'une dispo → CANCEL placeholder ==\n";
reset_all();
$r = create_lunch(['title' => 'Modif', 'organizer_email' => 'o@ex.com', 'organizer_name' => 'O', 'organizer_participates' => false,
    'deadline_local' => null, 'locale' => 'fr', 'participants' => [['name' => 'A', 'email' => 'a@ex.com'], ['name' => 'B', 'email' => 'b@ex.com']],
    'slots' => [['date' => '2026-11-05', 'time' => '12:00', 'duration' => 60]]]);
$l = $r['lunch']; [$A, $B] = lunch_participants((int) $l['id']); [$S] = lunch_slots((int) $l['id']);
process_response($A, [(int) $S['id'] => true]);
$before = grepc('METHOD:CANCEL');
process_response($A, [(int) $S['id'] => false]);
check('CANCEL placeholder émis au retrait', grepc('METHOD:CANCEL') > $before);

echo "\n== G. Annulation déjeuner confirmé → CANCEL à tous ==\n";
reset_all();
$r = create_lunch(['title' => 'Annul', 'organizer_email' => 'o@ex.com', 'organizer_name' => 'O', 'organizer_participates' => false,
    'deadline_local' => null, 'locale' => 'fr', 'participants' => [['name' => 'A', 'email' => 'a@ex.com']],
    'slots' => [['date' => '2026-11-20', 'time' => '12:00', 'duration' => 60]]]);
$l = $r['lunch']; [$A] = lunch_participants((int) $l['id']); [$S] = lunch_slots((int) $l['id']);
process_response($A, [(int) $S['id'] => true]);
$before = grepc('METHOD:CANCEL');
cancel_lunch(get_lunch((int) $l['id']));
check('statut annulé', get_lunch((int) $l['id'])['status'] === 'annule');
check('CANCEL calendrier envoyé', grepc('METHOD:CANCEL') > $before);

/* ================= Invitation portée par l'organisateur ================= */
echo "\n== I. Invitation finale : organisateur + tous les convives ==\n";
reset_all();
$r = create_lunch(['title' => 'Invit', 'organizer_email' => 'marie@acme.fr', 'organizer_name' => 'Marie Dupont',
    'organizer_participates' => false, 'deadline_local' => null, 'locale' => 'fr',
    'participants' => [['name' => 'Alice', 'email' => 'alice@ex.com'], ['name' => 'Bob', 'email' => 'bob@ex.com']],
    'slots' => [['date' => '2026-09-15', 'time' => '12:30', 'duration' => 90]]]);
$l = $r['lunch'];
check('nom de l\'organisateur mémorisé', ($l['organizer_name'] ?? '') === 'Marie Dupont');
check('pré-remplissage au prochain déjeuner', last_organizer_name('marie@acme.fr') === 'Marie Dupont');
foreach (mfiles() as $f) { unlink($f); }
$slotI = lunch_slots((int) $l['id'])[0];
foreach (lunch_participants((int) $l['id']) as $p) {
    process_response($p, [(int) $slotI['id'] => true]);
}
check('déjeuner confirmé', get_lunch((int) $l['id'])['status'] === 'confirme');

$orgMail = null; $partMail = null;
foreach (mfiles() as $f) {
    $raw = file_get_contents($f);
    $content = quoted_printable_decode($raw) . dec($f);
    if (strpos($content, 'CONFIRMED') === false || !preg_match('/^To: (.*)$/m', $raw, $to)) {
        continue;
    }
    $dest = trim($to[1]);
    if ($dest === 'marie@acme.fr') { $orgMail = $content; }
    if ($dest === 'alice@ex.com')  { $partMail = $content; }
}
/** Extrait les URL Google Calendar d'un email (évite les faux positifs des blocs base64). */
$gcalLinks = function (?string $mail): array {
    if ($mail === null) {
        return [];
    }
    preg_match_all('#https://calendar\.google\.com/calendar/render\?[^"\s<>]+#', $mail, $m);
    return $m[0];
};
$orgLinks = $gcalLinks($orgMail);
$partLinks = $gcalLinks($partMail);
$orgLinkWithGuests = implode(' ', array_filter($orgLinks, fn($u) => strpos($u, 'add=') !== false));

check('organisateur : lien Google avec invités pré-remplis (add=)', $orgLinkWithGuests !== '');
check('organisateur : les 2 convives dans le lien', $orgLinkWithGuests !== ''
    && strpos($orgLinkWithGuests, 'alice%40ex.com') !== false
    && strpos($orgLinkWithGuests, 'bob%40ex.com') !== false);
check('participant : pas de add= (agenda personnel)', $partLinks !== []
    && implode(' ', array_filter($partLinks, fn($u) => strpos($u, 'add=') !== false)) === '');
check('participant : expéditeur au nom de l\'organisateur', $partMail !== null && strpos($partMail, 'Marie Dupont (via') !== false);
check('.ics : ORGANIZER = nom réel', $partMail !== null && strpos($partMail, 'CN=Marie Dupont') !== false);
check('.ics : tous les convives en ATTENDEE', $partMail !== null && substr_count($partMail, 'ATTENDEE') >= 2);

/* ================= .ics bien formé ================= */
echo "\n== H. Structure iCalendar (RFC 5545) ==\n";
// Validation directe de la sortie du générateur (déterministe, sans décodage d'email).
$ics = ics_build([
    'method' => 'REQUEST', 'uid' => 'test-uid@' . config('ics_domain'), 'sequence' => 0, 'status' => 'CONFIRMED',
    'start_utc' => '2026-09-15 10:30:00', 'duration_min' => 90,
    'summary' => 'Test, déjeuner; caractères RFC', 'description' => 'ligne longue à replier ' . str_repeat('x', 80),
    'location' => 'Paris', 'organizer_email' => 'o@ex.com', 'organizer_name' => 'O',
    'attendees' => [['email' => 'a@ex.com', 'name' => 'A']],
]);
check('BEGIN/END VCALENDAR appariés (1)', substr_count($ics, 'BEGIN:VCALENDAR') === 1 && substr_count($ics, 'END:VCALENDAR') === 1);
check('BEGIN/END VEVENT appariés (1)', substr_count($ics, 'BEGIN:VEVENT') === 1 && substr_count($ics, 'END:VEVENT') === 1);
check('VERSION:2.0 présent', strpos($ics, 'VERSION:2.0') !== false);
check('DTSTAMP présent', strpos($ics, 'DTSTAMP:') !== false);
check('DTSTART en UTC (Z)', strpos($ics, 'DTSTART:20260915T103000Z') !== false);
check('lignes en CRLF', strpos($ics, "\r\n") !== false);

/* ================= Nettoyage ================= */
array_map('unlink', glob($tmp . '/maillog/*') ?: []);
@array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp . '/maillog');
@rmdir($tmp);

echo "\n" . ($FAIL === 0 ? "\033[32m✅ TOUS LES TESTS PASSENT\033[0m\n" : "\033[31m❌ $FAIL test(s) en échec\033[0m\n");
exit($FAIL === 0 ? 0 : 1);
