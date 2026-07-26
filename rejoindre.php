<?php
require_once __DIR__ . '/inc/bootstrap.inc.php';

$token = (string) ($_GET['j'] ?? '');
$lunch = $token !== '' ? get_lunch_by_join_token($token) : null;
if (!$lunch) {
    http_response_code(404);
    page_header(__('resp.invalid_h1'));
    echo '<h1>' . h(__('resp.invalid_h1')) . '</h1><div class="card"><p>' . h(__('resp.invalid_body')) . '</p></div>';
    page_footer();
    exit;
}

$slots = lunch_slots((int) $lunch['id']);
$minDate = min_slot_date($lunch);
$errors = [];
$vName = ''; $vEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && join_open($lunch)) {
    csrf_check();
    $vName = clean_line((string) ($_POST['name'] ?? ''));
    $vEmail = clean_line((string) ($_POST['email'] ?? ''));
    if ($vName === '') {
        $errors[] = __('join.err_name');
    }
    if (!valid_email($vEmail)) {
        $errors[] = __('join.err_email');
    }

    // Proposition éventuelle (validée avant de tout enregistrer).
    $pdate = trim((string) ($_POST['pdate'] ?? ''));
    $ptime = trim((string) ($_POST['ptime'] ?? ''));
    $wantsPropose = ($pdate !== '' || $ptime !== '');
    if ($wantsPropose) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pdate) || !preg_match('/^\d{1,2}:\d{2}$/', $ptime)) {
            $errors[] = __('resp.invalid_slot');
        } elseif (!slot_respects_lead($lunch, $pdate, $ptime)) {
            $errors[] = __('join.lead_error', ['date' => fmt_date_only($minDate)]);
        }
    }

    if (!$errors) {
        $participant = register_self_participant($lunch, $vName, $vEmail);

        $availability = [];
        foreach ($slots as $s) {
            $key = 'slot_' . $s['id'];
            if (isset($_POST[$key]) && ($_POST[$key] === 'yes' || $_POST[$key] === 'no')) {
                $availability[(int) $s['id']] = ($_POST[$key] === 'yes');
            }
        }
        if ($availability) {
            process_response($participant, $availability);
        }
        if ($wantsPropose) {
            propose_slot($participant, $pdate, $ptime, (int) ($_POST['pduration'] ?? 90) ?: 90);
        }

        $after = get_lunch((int) $lunch['id']);
        flash($after['status'] === 'confirme' ? __('join.confirmed_flash') : __('join.saved'), 'success');
        // On envoie l'invité sur sa page personnelle (lien à conserver pour modifier).
        redirect('repondre.php?t=' . $participant['token']);
    }
}

page_header(__('join.title'));
echo '<h1>' . h($lunch['title']) . '</h1>';
$who = organizer_display_name($lunch);
$meta = [];
if ($lunch['location']) {
    $meta[] = h($lunch['location']);
}
echo '<p class="sub">' . h($who !== 'Organisateur' ? $who : __('join.invited_intro'));
if ($meta) {
    echo ' &nbsp;·&nbsp; ' . implode(' · ', $meta);
}
echo '</p>';

if (!join_open($lunch)) {
    if ($lunch['status'] === 'confirme' && $lunch['confirmed_slot_id']) {
        $cs = get_slot((int) $lunch['confirmed_slot_id']);
        echo '<div class="card"><p><span class="badge badge-ok">✓ ' . h(__('join.confirmed_h1')) . '</span></p>';
        echo '<p class="st" style="font-size:19px">' . h(fmt_slot($cs['start_utc'], (int) $cs['duration_min'])) . '</p></div>';
    } else {
        echo '<div class="card"><h2 style="margin-top:0">' . h(__('join.closed_h1')) . '</h2><p>' . h(__('join.closed_body')) . '</p></div>';
    }
    page_footer();
    exit;
}

foreach ($errors as $e) { echo '<div class="flash error">' . h($e) . '</div>'; }
if ($lunch['deadline']) {
    echo '<p class="help">' . h(__('join.deadline_note', ['date' => fmt_datetime($lunch['deadline'])])) . '</p>';
}

echo '<form method="post">' . csrf_field();

// Vous.
echo '<h2>' . h(__('join.your_info')) . '</h2><div class="card">';
echo '<label for="name">' . h(__('join.name')) . '</label>';
echo '<input type="text" id="name" name="name" required autocomplete="name" value="' . h($vName) . '">';
echo '<label for="email">' . h(__('join.email')) . '</label>';
echo '<input type="email" id="email" name="email" required autocomplete="email" inputmode="email" value="' . h($vEmail) . '">';
echo '<p class="help">' . h(__('join.email_help')) . '</p></div>';

// Disponibilités.
echo '<h2>' . h(__('resp.your_avail_h2')) . '</h2><div class="card">';
if (!$slots) {
    echo '<p class="muted">' . h(__('resp.no_slots')) . '</p>';
}
foreach ($slots as $s) {
    $sid = (int) $s['id'];
    $sel = $_POST['slot_' . $sid] ?? '';
    echo '<div class="slot-line"><span class="st">' . h(fmt_slot($s['start_utc'], (int) $s['duration_min']));
    if ($s['proposed_by']) {
        echo ' <span class="muted">' . h(__('resp.proposed_tag')) . '</span>';
    }
    echo '</span><div class="seg">'
        . '<span class="seg-item seg-yes"><input type="radio" id="s' . $sid . 'y" name="slot_' . $sid . '" value="yes"' . ($sel === 'yes' ? ' checked' : '') . '><label for="s' . $sid . 'y">' . h(__('resp.available')) . '</label></span>'
        . '<span class="seg-item seg-no"><input type="radio" id="s' . $sid . 'n" name="slot_' . $sid . '" value="no"' . ($sel === 'no' ? ' checked' : '') . '><label for="s' . $sid . 'n">' . h(__('resp.unavailable')) . '</label></span>'
        . '</div></div>';
}
echo '</div>';

// Proposer une autre date.
echo '<h2>' . h(__('resp.propose_h2')) . '</h2><div class="card">';
echo '<p class="help" style="margin-top:0">' . h(__('join.min_date_note', ['date' => fmt_date_only($minDate)])) . '</p>';
echo '<div class="dyn-grid">'
    . '<input class="fdate" type="date" name="pdate" min="' . h($minDate) . '" value="' . h((string) ($_POST['pdate'] ?? '')) . '">'
    . '<input type="time" name="ptime" value="' . h((string) ($_POST['ptime'] ?? '12:30')) . '">'
    . '<input type="number" name="pduration" value="90" min="15" step="15"></div>';
echo '</div>';

echo '<button type="submit" class="btn-block">' . h(__('join.submit')) . '</button>';
echo '</form>';

page_footer();
