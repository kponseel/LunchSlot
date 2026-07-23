<?php
/**
 * Tâche cron : relances des participants sans réponse complète (dans la limite
 * de l'intervalle anti-spam), et rapport d'échéance unique une fois la deadline
 * dépassée.
 *
 * hPanel : commande « php /chemin/lunchslot/cron_relance.php ».
 * En web (optionnel) : protéger par ?key=... si souhaité.
 */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.inc.php';

$isCli = (PHP_SAPI === 'cli');

$minIntervalH = (int) config('reminder_min_interval_hours', 20);
$now = now_utc();

$remCount = 0;
$reportCount = 0;

$lunches = db()->query("SELECT * FROM lunches WHERE status = 'en_attente'")->fetchAll();
foreach ($lunches as $lunch) {
    $slots = lunch_slots((int) $lunch['id']);
    if (!$slots) {
        continue;
    }
    $participants = lunch_participants((int) $lunch['id']);
    $deadlinePassed = $lunch['deadline'] && $lunch['deadline'] <= $now;

    if ($deadlinePassed) {
        // Rapport unique.
        if (!mail_event_exists((int) $lunch['id'], 'deadline_report')) {
            $missing = [];
            foreach ($participants as $p) {
                if ((int) $p['is_organizer'] === 1) {
                    continue;
                }
                if (!participant_complete((int) $p['id'], $slots)) {
                    $missing[] = $p;
                }
            }
            send_deadline_report($lunch, $missing);
            log_mail_event((int) $lunch['id'], 'deadline_report');
            $reportCount++;
        }
        continue; // plus de relances après la deadline
    }

    // Relances (deadline non dépassée / absente).
    foreach ($participants as $p) {
        if ((int) $p['is_organizer'] === 1) {
            continue;
        }
        if (participant_complete((int) $p['id'], $slots)) {
            continue;
        }
        $due = empty($p['last_reminded_at']) || $p['last_reminded_at'] < utc_plus(-$minIntervalH * 3600);
        if ($due) {
            send_reminder($lunch, $p);
            db()->prepare('UPDATE participants SET last_reminded_at = ? WHERE id = ?')->execute([$now, $p['id']]);
            $remCount++;
        }
    }
}

purge_expired();

$summary = "LunchSlot cron: {$remCount} relance(s), {$reportCount} rapport(s) d'échéance.";
if ($isCli) {
    echo $summary . PHP_EOL;
} else {
    header('Content-Type: text/plain; charset=UTF-8');
    echo $summary;
}
