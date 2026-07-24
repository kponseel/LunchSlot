<?php
require_once __DIR__ . '/inc/bootstrap.inc.php';

$org = require_login();
$lunches = lunches_for_organizer($org['email']);

$nowUtc = now_utc();
$upcoming = [];
$past = [];
foreach ($lunches as $l) {
    $maxStart = null;
    foreach (lunch_slots((int) $l['id']) as $s) {
        if ($maxStart === null || $s['start_utc'] > $maxStart) {
            $maxStart = $s['start_utc'];
        }
    }
    if ($l['status'] !== 'annule' && $maxStart !== null && $maxStart >= $nowUtc) {
        $upcoming[] = $l;
    } else {
        $past[] = $l;
    }
}

page_header(__('my.title'), $org);
echo '<h1>' . h(__('my.h1')) . '</h1>';
echo '<p><a class="btn btn-block" href="' . h(rtrim(config('app_url'), '/')) . '/creer.php">' . h(__('my.new_btn')) . '</a></p>';

function render_lunch_list(array $list): void
{
    if (!$list) {
        echo '<p class="muted" style="padding:4px 2px">' . h(__('my.none')) . '</p>';
        return;
    }
    $base = h(rtrim(config('app_url'), '/'));
    echo '<div class="list">';
    foreach ($list as $l) {
        $nP = count(lunch_participants((int) $l['id']));
        $nS = count(lunch_slots((int) $l['id']));
        $url = $base . '/dashboard.php?t=' . h($l['admin_token']);
        echo '<a class="item" href="' . $url . '">';
        echo '<div class="grow"><div class="t">' . h($l['title']) . '</div>';
        echo '<div class="d">' . status_badge($l['status']) . ' &nbsp; '
            . $nS . ' ' . h(strtolower(__('my.col_slots'))) . ' · ' . $nP . ' ' . h(strtolower(__('my.col_participants')));
        if ($l['location']) {
            echo ' · ' . h($l['location']);
        }
        echo '</div></div><span class="chev">›</span></a>';
    }
    echo '</div>';
}

echo '<h2>' . h(__('my.upcoming')) . '</h2>';
render_lunch_list($upcoming);
echo '<h2>' . h(__('my.past')) . '</h2>';
render_lunch_list($past);

page_footer();
