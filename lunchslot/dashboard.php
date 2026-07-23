<?php
require_once __DIR__ . '/inc/bootstrap.inc.php';

// Accès : jeton d'administration, ou session organisateur propriétaire.
$token = (string) ($_GET['t'] ?? '');
$lunch = $token !== '' ? get_lunch_by_admin_token($token) : null;

$org = current_organizer();
if (!$lunch && $org && isset($_GET['id'])) {
    $candidate = get_lunch((int) $_GET['id']);
    if ($candidate && normalize_email($candidate['organizer_email']) === normalize_email($org['email'])) {
        $lunch = $candidate;
    }
}
// Un organisateur connecté ne peut ouvrir que ses déjeuners via jeton.
if ($lunch && $org && normalize_email($lunch['organizer_email']) !== normalize_email($org['email'])) {
    // jeton d'admin : accès autorisé même sans session correspondante
}

if (!$lunch) {
    http_response_code(404);
    page_header(__('dash.title'), $org);
    echo '<h1>' . h(__('dash.title')) . '</h1><div class="card"><p>' . h(__('dash.not_found')) . '</p></div>';
    page_footer();
    exit;
}

$adminToken = $lunch['admin_token'];
$self = rtrim(config('app_url'), '/') . '/dashboard.php?t=' . $adminToken;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'addslot' && $lunch['status'] !== 'annule') {
        $date = trim((string) ($_POST['date'] ?? ''));
        $time = trim((string) ($_POST['time'] ?? ''));
        $dur = (int) ($_POST['duration'] ?? 90) ?: 90;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            $sid = add_slot_to_lunch((int) $lunch['id'], $date, $time, $dur, null);
            $slot = get_slot($sid);
            send_new_slot_notice($lunch, $slot, lunch_participants((int) $lunch['id']), 'Organisateur');
            evaluate_and_confirm((int) $lunch['id']);
            flash(__('dash.flash_added'), 'success');
        } else {
            flash(__('resp.invalid_slot'), 'error');
        }
        redirect('dashboard.php?t=' . $adminToken);
    }

    if ($do === 'resend') {
        $p = get_participant((int) ($_POST['pid'] ?? 0));
        if ($p && (int) $p['lunch_id'] === (int) $lunch['id'] && (int) $p['is_organizer'] === 0) {
            send_participant_invite($lunch, $p);
            flash(__('dash.flash_resent'), 'success');
        }
        redirect('dashboard.php?t=' . $adminToken);
    }

    if ($do === 'remind') {
        $p = get_participant((int) ($_POST['pid'] ?? 0));
        if ($p && (int) $p['lunch_id'] === (int) $lunch['id']) {
            $minH = (int) config('reminder_min_interval_hours', 20);
            $ok = empty($p['last_reminded_at']) || $p['last_reminded_at'] < utc_plus(-$minH * 3600);
            if ($ok) {
                send_reminder($lunch, $p);
                db()->prepare('UPDATE participants SET last_reminded_at = ? WHERE id = ?')->execute([now_utc(), $p['id']]);
                flash(__('dash.flash_reminded'), 'success');
            } else {
                flash(__('dash.flash_remind_skip'), 'info');
            }
        }
        redirect('dashboard.php?t=' . $adminToken);
    }

    if ($do === 'confirm' && $lunch['status'] === 'en_attente') {
        $sid = (int) ($_POST['sid'] ?? 0);
        $slot = get_slot($sid);
        if ($slot && (int) $slot['lunch_id'] === (int) $lunch['id']) {
            if (slot_has_refusal((int) $lunch['id'], $sid)) {
                flash(__('dash.flash_confirm_refused'), 'error');
            } else {
                confirm_lunch((int) $lunch['id'], $sid, true);
                flash(__('dash.flash_confirmed'), 'success');
            }
        }
        redirect('dashboard.php?t=' . $adminToken);
    }

    if ($do === 'cancel' && $lunch['status'] !== 'annule') {
        cancel_lunch($lunch);
        flash(__('dash.flash_canceled'), 'info');
        redirect('dashboard.php?t=' . $adminToken);
    }
    redirect('dashboard.php?t=' . $adminToken);
}

$participants = lunch_participants((int) $lunch['id']);
$slots = lunch_slots((int) $lunch['id']);
$matrix = response_matrix((int) $lunch['id']);
$n = count($participants);

page_header(__('dash.title'), $org);
echo '<h1>' . h($lunch['title']) . ' ' . status_badge($lunch['status']) . '</h1>';
$meta = [];
if ($lunch['location']) {
    $meta[] = h($lunch['location']);
}
$meta[] = h(__('dash.deadline')) . ' : ' . ($lunch['deadline'] ? h(fmt_datetime($lunch['deadline'])) : h(__('dash.no_deadline')));
echo '<p class="muted">' . implode(' · ', $meta) . '</p>';

if ($lunch['status'] === 'confirme' && $lunch['confirmed_slot_id']) {
    $cs = get_slot((int) $lunch['confirmed_slot_id']);
    echo '<div class="flash success">' . h(__('dash.confirmed_banner', ['slot' => fmt_slot($cs['start_utc'], (int) $cs['duration_min'])])) . '</div>';
}
if ($lunch['status'] === 'annule') {
    echo '<div class="flash error">' . h(__('dash.canceled_banner')) . '</div>';
}

/* ---- Matrice créneaux × participants ---- */
echo '<h2>' . h(__('dash.matrix_h2')) . '</h2>';
echo '<div style="overflow-x:auto;"><table><thead><tr><th>' . h(__('dash.slot')) . '</th>';
foreach ($participants as $p) {
    echo '<th>' . h($p['name']) . ((int) $p['is_organizer'] === 1 ? ' *' : '') . '</th>';
}
echo '<th>' . h(__('dash.summary')) . '</th><th>' . h(__('dash.action')) . '</th></tr></thead><tbody>';

foreach ($slots as $s) {
    $sid = (int) $s['id'];
    echo '<tr><td><strong>' . h(fmt_slot($s['start_utc'], (int) $s['duration_min'])) . '</strong>';
    if ($s['proposed_by']) {
        echo ' <span class="muted">' . h(__('resp.proposed_tag')) . '</span>';
    }
    echo '</td>';
    $avail = 0;
    $refus = 0;
    foreach ($participants as $p) {
        $v = $matrix[(int) $p['id']][$sid] ?? null;
        if ($v === 1) {
            echo '<td class="yes">✓</td>';
            $avail++;
        } elseif ($v === 0) {
            echo '<td class="no">✗</td>';
            $refus++;
        } else {
            echo '<td class="na">—</td>';
        }
    }
    // Bilan.
    if ((int) $lunch['confirmed_slot_id'] === $sid) {
        echo '<td>' . status_badge('confirme') . '</td>';
    } elseif ($refus > 0) {
        echo '<td class="no">' . h(__('dash.impossible')) . '</td>';
    } else {
        echo '<td>' . h(__('dash.possible', ['x' => $avail, 'n' => $n])) . '</td>';
    }
    // Action : confirmation manuelle si en attente et aucun refus.
    echo '<td>';
    if ($lunch['status'] === 'en_attente' && $refus === 0 && $n > 0) {
        echo '<form method="post" style="margin:0;">' . csrf_field()
            . '<input type="hidden" name="do" value="confirm"><input type="hidden" name="sid" value="' . $sid . '">'
            . '<button class="btn-small" type="submit">' . h(__('dash.confirm_btn')) . '</button></form>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div>';

/* ---- Qui a répondu ---- */
echo '<h2>' . h(__('dash.responded_h2')) . '</h2>';
echo '<table><thead><tr><th>' . h(__('my.col_participants')) . '</th><th></th><th>' . h(__('dash.copy_link')) . '</th><th></th></tr></thead><tbody>';
foreach ($participants as $p) {
    $answered = 0;
    foreach ($slots as $s) {
        if (isset($matrix[(int) $p['id']][(int) $s['id']])) {
            $answered++;
        }
    }
    $state = $answered === 0 ? __('dash.pending') : ($answered >= count($slots) && count($slots) > 0 ? __('dash.complete') : __('dash.partial'));
    $badgeCls = $answered === 0 ? 'badge-cancel' : ($answered >= count($slots) ? 'badge-ok' : 'badge-wait');
    $purl = participant_url($p);
    echo '<tr><td><strong>' . h($p['name']) . '</strong>' . ((int) $p['is_organizer'] === 1 ? ' *' : '')
        . '<br><span class="muted">' . h($p['email']) . '</span>';
    if (!empty($p['last_reminded_at'])) {
        echo '<br><span class="muted">' . h(__('dash.last_reminded', ['when' => fmt_datetime($p['last_reminded_at'])])) . '</span>';
    }
    echo '</td>';
    echo '<td><span class="badge ' . $badgeCls . '">' . h($state) . '</span></td>';
    echo '<td><span class="copy">' . h($purl) . '</span></td>';
    echo '<td>';
    if ((int) $p['is_organizer'] === 0) {
        echo '<form method="post" class="inline" style="margin:0 4px 4px 0;">' . csrf_field()
            . '<input type="hidden" name="do" value="resend"><input type="hidden" name="pid" value="' . $p['id'] . '">'
            . '<button class="btn-small btn-sec" type="submit">' . h(__('dash.resend')) . '</button></form>';
        if ($lunch['status'] === 'en_attente') {
            echo '<form method="post" class="inline" style="margin:0;">' . csrf_field()
                . '<input type="hidden" name="do" value="remind"><input type="hidden" name="pid" value="' . $p['id'] . '">'
                . '<button class="btn-small btn-sec" type="submit">' . h(__('dash.remind')) . '</button></form>';
        }
    }
    echo '</td></tr>';
}
echo '</tbody></table>';
echo '<p class="muted">* ' . h(__('dash.you_participate')) . '</p>';

/* ---- Ajouter un créneau + annuler ---- */
if ($lunch['status'] !== 'annule') {
    echo '<h2>' . h(__('dash.addslot_h2')) . '</h2>';
    echo '<form method="post" class="card">' . csrf_field() . '<input type="hidden" name="do" value="addslot">';
    echo '<div class="row"><div><label>' . h(__('resp.date')) . '</label><input type="date" name="date"></div>'
        . '<div><label>' . h(__('resp.time')) . '</label><input type="time" name="time"></div>'
        . '<div><label>' . h(__('resp.duration')) . '</label><input type="number" name="duration" value="90" min="15" step="15"></div></div>';
    echo '<p style="margin-top:12px;"><button type="submit">' . h(__('dash.addslot_btn')) . '</button></p></form>';

    echo '<h2>' . h(__('dash.cancel_h2')) . '</h2>';
    echo '<form method="post" onsubmit="return confirm(' . h(json_encode(__('dash.cancel_confirm_js'))) . ');">' . csrf_field()
        . '<input type="hidden" name="do" value="cancel">'
        . '<button type="submit" class="btn-danger">' . h(__('dash.cancel_btn')) . '</button></form>';
}

page_footer();
