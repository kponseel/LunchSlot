<?php
require_once __DIR__ . '/inc/bootstrap.inc.php';

$org = require_login();
$errors = [];
$old = ['title' => '', 'location' => '', 'organizer_name' => '', 'deadline' => '', 'participants' => '', 'slots' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($old as $k => $_) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $participates = !empty($_POST['organizer_participates']);

    if ($old['title'] === '') {
        $errors[] = __('create.err_title');
    }

    $participants = [];
    foreach (preg_split('/\r\n|\r|\n/', $old['participants']) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', preg_split('/[,;]/', $line, 2));
        if (count($parts) < 2 || !valid_email($parts[1])) {
            $errors[] = __('create.err_participant', ['line' => $line]);
            continue;
        }
        $participants[] = ['name' => $parts[0], 'email' => $parts[1]];
    }
    if (!$participants) {
        $errors[] = __('create.err_no_participant');
    }

    $slots = [];
    foreach (preg_split('/\r\n|\r|\n/', $old['slots']) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', preg_split('/[,;]/', $line));
        if (count($parts) < 2 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[0]) || !preg_match('/^\d{1,2}:\d{2}$/', $parts[1])) {
            $errors[] = __('create.err_slot', ['line' => $line]);
            continue;
        }
        $slots[] = ['date' => $parts[0], 'time' => $parts[1], 'duration' => (int) ($parts[2] ?? 60) ?: 60];
    }
    if (!$slots) {
        $errors[] = __('create.err_no_slot');
    }

    if (!$errors) {
        $res = create_lunch([
            'title' => $old['title'],
            'location' => $old['location'] ?: null,
            'organizer_email' => $org['email'],
            'organizer_name' => $old['organizer_name'] ?: 'Organisateur',
            'organizer_participates' => $participates,
            'deadline_local' => $old['deadline'] ? str_replace('T', ' ', $old['deadline']) : null,
            'participants' => $participants,
            'slots' => $slots,
            'locale' => current_locale(),
        ]);
        $lunch = $res['lunch'];
        foreach ($res['participants'] as $p) {
            if ((int) $p['is_organizer'] === 1) {
                continue;
            }
            send_participant_invite($lunch, $p);
        }
        send_organizer_created($lunch, $res['participants']);

        flash(__('create.created_flash'), 'success');
        redirect('dashboard.php?t=' . $lunch['admin_token']);
    }
}

page_header(__('create.title'), $org);
echo '<h1>' . h(__('create.h1')) . '</h1>';
foreach ($errors as $e) {
    echo '<div class="flash error">' . h($e) . '</div>';
}
?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <label for="title"><?= h(__('create.title_label')) ?></label>
  <input type="text" id="title" name="title" required value="<?= h($old['title']) ?>" placeholder="<?= h(__('create.title_ph')) ?>">

  <label for="location"><?= h(__('create.location_label')) ?></label>
  <input type="text" id="location" name="location" value="<?= h($old['location']) ?>" placeholder="<?= h(__('create.location_ph')) ?>">

  <label for="deadline"><?= h(__('create.deadline_label')) ?></label>
  <input type="datetime-local" id="deadline" name="deadline" value="<?= h($old['deadline']) ?>">

  <label for="participants"><?= h(__('create.participants_label')) ?> <span class="muted"><?= h(__('create.participants_hint')) ?></span></label>
  <textarea id="participants" name="participants" rows="5" placeholder="Marie Durand, marie@client.com&#10;Paul Martin, paul@client.com"><?= h($old['participants']) ?></textarea>

  <label for="slots"><?= h(__('create.slots_label')) ?> <span class="muted"><?= h(__('create.slots_hint')) ?></span></label>
  <textarea id="slots" name="slots" rows="4" placeholder="2026-09-15, 12:30, 90&#10;2026-09-16, 13:00, 90"><?= h($old['slots']) ?></textarea>

  <label style="font-weight:400;margin-top:14px;">
    <input type="checkbox" name="organizer_participates" value="1" style="width:auto;display:inline;" <?= !empty($_POST['organizer_participates']) ? 'checked' : '' ?>>
    <?= h(__('create.i_participate')) ?>
  </label>
  <label for="organizer_name"><?= h(__('create.my_name_label')) ?></label>
  <input type="text" id="organizer_name" name="organizer_name" value="<?= h($old['organizer_name']) ?>" placeholder="<?= h(__('create.my_name_ph')) ?>">

  <p style="margin-top:16px;"><button type="submit"><?= h(__('create.submit')) ?></button></p>
</form>
<?php
page_footer();
