<?php
require_once __DIR__ . '/inc/bootstrap.inc.php';

$org = require_login();
$errors = [];
$old = ['title' => '', 'location' => '', 'organizer_name' => '', 'deadline' => ''];
$pRows = [['name' => '', 'email' => '']];      // lignes participants
$sRows = [['date' => '', 'time' => '', 'duration' => '90']]; // lignes créneaux

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($old as $k => $_) {
        // Champs sur une seule ligne : neutralise CR/LF (anti-injection d'en-têtes email).
        $old[$k] = ($k === 'deadline') ? trim((string) ($_POST[$k] ?? '')) : clean_line((string) ($_POST[$k] ?? ''));
    }
    $participates = !empty($_POST['organizer_participates']);

    // Reconstruit les lignes soumises (pour ré-affichage en cas d'erreur).
    $pn = $_POST['pname'] ?? [];
    $pe = $_POST['pemail'] ?? [];
    $sd = $_POST['sdate'] ?? [];
    $stime = $_POST['stime'] ?? [];
    $sdur = $_POST['sduration'] ?? [];
    $pRows = [];
    for ($i = 0; $i < max(count($pn), count($pe)); $i++) {
        $pRows[] = ['name' => clean_line((string) ($pn[$i] ?? '')), 'email' => clean_line((string) ($pe[$i] ?? ''))];
    }
    $sRows = [];
    for ($i = 0; $i < max(count($sd), count($stime)); $i++) {
        $sRows[] = ['date' => trim((string) ($sd[$i] ?? '')), 'time' => trim((string) ($stime[$i] ?? '')), 'duration' => trim((string) ($sdur[$i] ?? '90'))];
    }

    if ($old['title'] === '') {
        $errors[] = __('create.err_title');
    }

    // Participants valides.
    $participants = [];
    foreach ($pRows as $row) {
        if ($row['name'] === '' && $row['email'] === '') {
            continue; // ligne vide ignorée
        }
        if ($row['name'] === '' || !valid_email($row['email'])) {
            $errors[] = __('create.err_participant', ['line' => trim($row['name'] . ' ' . $row['email'])]);
            continue;
        }
        $participants[] = ['name' => $row['name'], 'email' => $row['email']];
    }
    if (!$participants) {
        $errors[] = __('create.err_no_participant');
    }

    // Créneaux valides.
    $slots = [];
    foreach ($sRows as $row) {
        if ($row['date'] === '' && $row['time'] === '') {
            continue;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['date']) || !preg_match('/^\d{1,2}:\d{2}$/', $row['time'])) {
            $errors[] = __('create.err_slot', ['line' => trim($row['date'] . ' ' . $row['time'])]);
            continue;
        }
        $slots[] = ['date' => $row['date'], 'time' => $row['time'], 'duration' => (int) $row['duration'] ?: 60];
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

// Garantit au moins une ligne vide à l'affichage.
if (!$pRows) {
    $pRows = [['name' => '', 'email' => '']];
}
if (!$sRows) {
    $sRows = [['date' => '', 'time' => '', 'duration' => '90']];
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

  <h2><?= h(__('create.participants_label')) ?> <span class="muted">(<?= h(__('create.participants_hint')) ?>)</span></h2>
  <div id="participants">
    <?php foreach ($pRows as $r): ?>
    <div class="dyn-row">
      <input type="text" name="pname[]" placeholder="<?= h(__('create.p_name')) ?>" value="<?= h($r['name']) ?>">
      <input type="email" name="pemail[]" placeholder="<?= h(__('create.p_email')) ?>" value="<?= h($r['email']) ?>">
      <button type="button" class="btn-sec btn-small dyn-del" title="<?= h(__('create.remove')) ?>">✕</button>
    </div>
    <?php endforeach; ?>
  </div>
  <p><button type="button" class="btn-sec btn-small" id="add-participant"><?= h(__('create.add_participant')) ?></button></p>

  <h2><?= h(__('create.slots_label')) ?> <span class="muted">(<?= h(__('create.slots_hint')) ?>)</span></h2>
  <div id="slots">
    <?php foreach ($sRows as $r): ?>
    <div class="dyn-row">
      <input type="date" name="sdate[]" value="<?= h($r['date']) ?>">
      <input type="time" name="stime[]" value="<?= h($r['time']) ?>">
      <input type="number" name="sduration[]" min="15" step="15" value="<?= h($r['duration'] ?: '90') ?>" title="<?= h(__('resp.duration')) ?>">
      <button type="button" class="btn-sec btn-small dyn-del" title="<?= h(__('create.remove')) ?>">✕</button>
    </div>
    <?php endforeach; ?>
  </div>
  <p><button type="button" class="btn-sec btn-small" id="add-slot"><?= h(__('create.add_slot')) ?></button></p>

  <label style="font-weight:400;margin-top:14px;">
    <input type="checkbox" name="organizer_participates" value="1" style="width:auto;display:inline;" <?= !empty($_POST['organizer_participates']) ? 'checked' : '' ?>>
    <?= h(__('create.i_participate')) ?>
  </label>
  <label for="organizer_name"><?= h(__('create.my_name_label')) ?></label>
  <input type="text" id="organizer_name" name="organizer_name" value="<?= h($old['organizer_name']) ?>" placeholder="<?= h(__('create.my_name_ph')) ?>">

  <p style="margin-top:16px;"><button type="submit"><?= h(__('create.submit')) ?></button></p>
</form>
<script>
(function () {
  function clone(container) {
    var rows = container.querySelectorAll('.dyn-row');
    var last = rows[rows.length - 1];
    var copy = last.cloneNode(true);
    copy.querySelectorAll('input').forEach(function (i) {
      if (i.type !== 'number') { i.value = ''; }
    });
    container.appendChild(copy);
    var first = copy.querySelector('input');
    if (first) { first.focus(); }
  }
  document.getElementById('add-participant').addEventListener('click', function () {
    clone(document.getElementById('participants'));
  });
  document.getElementById('add-slot').addEventListener('click', function () {
    clone(document.getElementById('slots'));
  });
  document.addEventListener('click', function (e) {
    if (e.target && e.target.classList.contains('dyn-del')) {
      var container = e.target.closest('.dyn-row').parentNode;
      if (container.querySelectorAll('.dyn-row').length > 1) {
        e.target.closest('.dyn-row').remove();
      }
    }
  });
})();
</script>
<?php
page_footer();
