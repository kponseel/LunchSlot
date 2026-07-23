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
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            propose_slot($participant, $date, $time, $dur);
            flash(__('resp.proposed_flash'), 'success');
        } else {
            flash(__('resp.invalid_slot'), 'error');
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

page_header(__('resp.title'));
echo '<h1>' . h($lunch['title']) . ' ' . status_badge($lunch['status']) . '</h1>';
if ($lunch['location']) {
    echo '<p class="muted">' . h($lunch['location']) . '</p>';
}
echo '<p>' . h(__('resp.hello', ['name' => $participant['name']])) . '</p>';

if ($lunch['status'] === 'annule') {
    echo '<div class="card"><p>' . h(__('resp.canceled_body')) . '</p></div>';
    page_footer();
    exit;
}

if ($lunch['status'] === 'confirme') {
    $cs = get_slot((int) $lunch['confirmed_slot_id']);
    echo '<div class="card"><p>' . h(__('resp.confirmed_intro')) . '</p>';
    echo '<p style="font-size:16px;"><strong>' . h(fmt_slot($cs['start_utc'], (int) $cs['duration_min'])) . '</strong></p>';
    echo '<p class="muted">' . h(__('resp.confirmed_email_note')) . '</p>';
    echo '<form method="post" onsubmit="return confirm(' . h(json_encode(__('resp.withdraw_confirm_js'))) . ');">';
    echo csrf_field() . '<input type="hidden" name="action" value="withdraw">';
    echo '<button type="submit" class="btn-danger btn-small">' . h(__('resp.withdraw_btn')) . '</button>';
    echo '</form></div>';
    page_footer();
    exit;
}

echo '<form method="post" class="card"><input type="hidden" name="action" value="respond">' . csrf_field();
echo '<h2>' . h(__('resp.your_avail_h2')) . '</h2>';
if (!$slots) {
    echo '<p class="muted">' . h(__('resp.no_slots')) . '</p>';
}
foreach ($slots as $s) {
    $cur = get_response((int) $participant['id'], (int) $s['id']);
    echo '<div class="slot-line"><div style="flex:1;min-width:200px;"><strong>'
        . h(fmt_slot($s['start_utc'], (int) $s['duration_min'])) . '</strong>';
    if ($s['proposed_by']) {
        echo ' <span class="muted">' . h(__('resp.proposed_tag')) . '</span>';
    }
    echo '</div><label class="inline"><input type="radio" style="width:auto;display:inline;" name="slot_' . $s['id']
        . '" value="yes"' . ($cur === 1 ? ' checked' : '') . '> ' . h(__('resp.available')) . '</label> ';
    echo '<label class="inline"><input type="radio" style="width:auto;display:inline;" name="slot_' . $s['id']
        . '" value="no"' . ($cur === 0 ? ' checked' : '') . '> ' . h(__('resp.unavailable')) . '</label></div>';
}
if ($slots) {
    echo '<p style="margin-top:14px;"><button type="submit">' . h(__('resp.save_btn')) . '</button></p>';
}
echo '</form>';

echo '<form method="post" class="card"><input type="hidden" name="action" value="propose">' . csrf_field();
echo '<h2>' . h(__('resp.propose_h2')) . '</h2>';
echo '<div class="row"><div><label>' . h(__('resp.date')) . '</label><input type="date" name="pdate"></div>'
    . '<div><label>' . h(__('resp.time')) . '</label><input type="time" name="ptime"></div>'
    . '<div><label>' . h(__('resp.duration')) . '</label><input type="number" name="pduration" value="90" min="15" step="15"></div></div>';
echo '<p style="margin-top:12px;"><button type="submit" class="btn-sec">' . h(__('resp.propose_btn')) . '</button></p>';
echo '</form>';

page_footer();
