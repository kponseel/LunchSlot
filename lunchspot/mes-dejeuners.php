<?php
require_once __DIR__ . '/inc/bootstrap.inc.php';

$org = require_login();
$lunches = lunches_for_organizer($org['email']);

$nowUtc = now_utc();
$upcoming = [];
$past = [];
foreach ($lunches as $l) {
    $slots = lunch_slots((int) $l['id']);
    $maxStart = null;
    foreach ($slots as $s) {
        if ($maxStart === null || $s['start_utc'] > $maxStart) {
            $maxStart = $s['start_utc'];
        }
    }
    $l['_when'] = $maxStart;
    if ($l['status'] !== 'annule' && $maxStart !== null && $maxStart >= $nowUtc) {
        $upcoming[] = $l;
    } else {
        $past[] = $l;
    }
}

page_header(__('my.title'), $org);
echo '<h1>' . h(__('my.h1')) . '</h1>';
echo '<p><a class="btn" href="' . h(rtrim(config('app_url'), '/')) . '/creer.php">' . h(__('my.new_btn')) . '</a></p>';

function render_lunch_list(array $list): void
{
    if (!$list) {
        echo '<p class="muted">' . h(__('my.none')) . '</p>';
        return;
    }
    echo '<table><thead><tr><th>' . h(__('my.col_title')) . '</th><th>' . h(__('my.col_status'))
        . '</th><th>' . h(__('my.col_slots')) . '</th><th>' . h(__('my.col_participants')) . '</th><th></th></tr></thead><tbody>';
    foreach ($list as $l) {
        $parts = lunch_participants((int) $l['id']);
        $slots = lunch_slots((int) $l['id']);
        $url = h(rtrim(config('app_url'), '/')) . '/dashboard.php?t=' . h($l['admin_token']);
        echo '<tr>';
        echo '<td><strong>' . h($l['title']) . '</strong>';
        if ($l['location']) {
            echo '<br><span class="muted">' . h($l['location']) . '</span>';
        }
        echo '</td>';
        echo '<td>' . status_badge($l['status']) . '</td>';
        echo '<td>' . count($slots) . '</td>';
        echo '<td>' . count($parts) . '</td>';
        echo '<td><a class="btn btn-sec btn-small" href="' . $url . '">' . h(__('my.dashboard_btn')) . '</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

echo '<h2>' . h(__('my.upcoming')) . '</h2>';
render_lunch_list($upcoming);
echo '<h2>' . h(__('my.past')) . '</h2>';
render_lunch_list($past);

page_footer();
