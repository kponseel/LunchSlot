<?php
require_once __DIR__ . '/inc/bootstrap.inc.php';

$token = (string) ($_GET['t'] ?? '');
$participant = $token !== '' ? get_participant_by_token($token) : null;
if (!$participant) {
    http_response_code(404);
    page_header(__('resp.invalid_h1'));
    echo '<h1>' . h(__('resp.invalid_h1')) . '</h1><div class="card"><p>' . h(__('resp.invalid_body')) . '</p></div>';
    page_footer();
    exit;
}
$lunch = get_lunch((int) $participant['lunch_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? 'respond');

    if ($action === 'respond' && $lunch['status'] === 'en_attente') {
        $availability = [];
        foreach (lunch_slots((int) $lunch['id']) as $s) {
            $key = 'slot_' . $s['id'];
            if (isset($_POST[$key]) && ($_POST[$key] === 'yes' || $_POST[$key] === 'no')) {
                $availability[(int) $s['id']] = ($_POST[$key] === 'yes');
            }
        }
        process_response($participant, $availability);
        $lunch = get_lunch((int) $lunch['id']);
        flash($lunch['status'] === 'confirme' ? __('resp.confirmed_flash') : __('resp.saved'), 'success');
        redirect('repondre.php?t=' . $token);
    }

    if ($action === 'propose' && $lunch['status'] === 'en_attente') {
        $date = trim((string) ($_POST['pdate'] ?? ''));
        $time = trim((string) ($_POST['ptime'] ?? ''));
        $dur = (int) ($_POST['pduration'] ?? 60) ?: 60;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            flash(__('resp.invalid_slot'), 'error');
        } elseif (propose_slot($participant, $date, $time, $dur)) {
            flash(__('resp.proposed_flash'), 'success');
        } else {
            flash(__('join.lead_error', ['date' => fmt_date_only(min_slot_date($lunch))]), 'error');
        }
        redirect('repondre.php?t=' . $token);
    }

    if ($action === 'withdraw' && $lunch['status'] === 'confirme') {
        withdraw_participant($participant);
        flash(__('resp.withdraw_flash'), 'info');
        redirect('repondre.php?t=' . $token);
    }
    redirect('repondre.php?t=' . $token);
}

$slots = lunch_slots((int) $lunch['id']);
$minDate = min_slot_date($lunch);
$defaultDate = $minDate;

page_header(__('resp.title'));
echo '<h1>' . h($lunch['title']) . '</h1>';
echo '<p class="sub">' . status_badge($lunch['status']);
if ($lunch['location']) {
    echo ' &nbsp;·&nbsp; ' . h($lunch['location']);
}
echo '</p>';
echo '<p class="muted" style="margin-bottom:4px">' . h(__('resp.hello', ['name' => $participant['name']])) . '</p>';

if ($lunch['status'] === 'annule') {
    echo '<div class="card"><p>' . h(__('resp.canceled_body')) . '</p></div>';
    page_footer();
    exit;
}

if ($lunch['status'] === 'confirme') {
    $cs = get_slot((int) $lunch['confirmed_slot_id']);
    echo '<div class="card">';
    echo '<p style="margin-top:0"><span class="badge badge-ok">✓ ' . h(__('status.confirme')) . '</span></p>';
    echo '<p class="st" style="font-size:19px">' . h(fmt_slot($cs['start_utc'], (int) $cs['duration_min'])) . '</p>';
    echo '<p class="muted">' . h(__('resp.confirmed_email_note')) . '</p>';
    echo '<form method="post" onsubmit="return confirm(' . h(json_encode(__('resp.withdraw_confirm_js'))) . ');" style="margin-top:8px">';
    echo csrf_field() . '<input type="hidden" name="action" value="withdraw">';
    echo '<button type="submit" class="btn-danger btn-small">' . h(__('resp.withdraw_btn')) . '</button>';
    echo '</form></div>';
    page_footer();
    exit;
}

// État en attente : disponibilités (segmented control iOS).
echo '<form method="post"><input type="hidden" name="action" value="respond">' . csrf_field();
echo '<h2>' . h(__('resp.your_avail_h2')) . '</h2><div class="card">';
if (!$slots) {
    echo '<p class="muted">' . h(__('resp.no_slots')) . '</p>';
}
foreach ($slots as $s) {
    $sid = (int) $s['id'];
    $cur = get_response((int) $participant['id'], $sid);
    echo '<div class="slot-line"><span class="st">' . h(fmt_slot($s['start_utc'], (int) $s['duration_min']));
    if ($s['proposed_by']) {
        echo ' <span class="muted">' . h(__('resp.proposed_tag')) . '</span>';
    }
    echo '</span>';
    echo '<div class="seg">'
        . '<span class="seg-item seg-yes"><input type="radio" id="s' . $sid . 'y" name="slot_' . $sid . '" value="yes"' . ($cur === 1 ? ' checked' : '') . '><label for="s' . $sid . 'y">' . h(__('resp.available')) . '</label></span>'
        . '<span class="seg-item seg-no"><input type="radio" id="s' . $sid . 'n" name="slot_' . $sid . '" value="no"' . ($cur === 0 ? ' checked' : '') . '><label for="s' . $sid . 'n">' . h(__('resp.unavailable')) . '</label></span>'
        . '</div></div>';
}
echo '</div>';
if ($slots) {
    echo '<button type="submit" class="btn-block">' . h(__('resp.save_btn')) . '</button>';
}
echo '</form>';

// Proposer un créneau (défauts J+7 / 12h30).
echo '<h2>' . h(__('resp.propose_h2')) . '</h2>';
echo '<form method="post" class="card"><input type="hidden" name="action" value="propose">' . csrf_field();
echo '<p class="help" style="margin-top:0">' . h(__('join.min_date_note', ['date' => fmt_date_only($minDate)])) . '</p>';
echo '<div class="dyn-grid">'
    . '<input class="fdate" type="date" name="pdate" min="' . h($minDate) . '" value="' . h($defaultDate) . '">'
    . '<input type="time" name="ptime" value="12:30">'
    . '<input type="number" name="pduration" value="90" min="15" step="15"></div>';
echo '<p style="margin-top:12px;margin-bottom:0"><button type="submit" class="btn-sec btn-small">' . h(__('resp.propose_btn')) . '</button></p>';
echo '</form>';

page_footer();
