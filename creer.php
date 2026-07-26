<?php
require_once __DIR__ . '/inc/bootstrap.inc.php';

$org = require_login();
$errors = [];
$old = ['title' => '', 'location' => '', 'organizer_name' => last_organizer_name($org['email']), 'deadline' => ''];

$defaultDate = date('Y-m-d', strtotime('+7 days'));
$defaultTime = '12:30';
$pRows = [['name' => '', 'email' => '']];
$sRows = [['date' => $defaultDate, 'time' => $defaultTime, 'duration' => '90']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($old as $k => $_) {
        $old[$k] = ($k === 'deadline') ? trim((string) ($_POST[$k] ?? '')) : clean_line((string) ($_POST[$k] ?? ''));
    }
    $participates = !empty($_POST['organizer_participates']);

    $pn = $_POST['pname'] ?? []; $pe = $_POST['pemail'] ?? [];
    $sd = $_POST['sdate'] ?? []; $stime = $_POST['stime'] ?? []; $sdur = $_POST['sduration'] ?? [];
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

    $participants = [];
    foreach ($pRows as $row) {
        if ($row['name'] === '' && $row['email'] === '') {
            continue;
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

    $slots = [];
    $seen = [];
    foreach ($sRows as $row) {
        if ($row['date'] === '' && $row['time'] === '') {
            continue;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['date']) || !preg_match('/^\d{1,2}:\d{2}$/', $row['time'])) {
            $errors[] = __('create.err_slot', ['line' => trim($row['date'] . ' ' . $row['time'])]);
            continue;
        }
        $key = $row['date'] . ' ' . $row['time'];
        if (isset($seen[$key])) {
            $errors[] = __('create.err_duplicate', ['line' => $key]);
            continue;
        }
        $seen[$key] = true;
        $slots[] = ['date' => $row['date'], 'time' => $row['time'], 'duration' => (int) $row['duration'] ?: 60];
    }
    if (!$slots) {
        $errors[] = __('create.err_no_slot');
    }

    if (!$errors) {
        $res = create_lunch([
            'title' => $old['title'], 'location' => $old['location'] ?: null,
            'organizer_email' => $org['email'], 'organizer_name' => $old['organizer_name'],
            'organizer_participates' => $participates,
            'deadline_local' => $old['deadline'] ? str_replace('T', ' ', $old['deadline']) : null,
            'participants' => $participants, 'slots' => $slots, 'locale' => current_locale(),
        ]);
        $lunch = $res['lunch'];
        foreach ($res['participants'] as $p) {
            if ((int) $p['is_organizer'] === 1) { continue; }
            send_participant_invite($lunch, $p);
        }
        send_organizer_created($lunch, $res['participants']);
        flash(__('create.created_flash'), 'success');
        redirect('dashboard.php?t=' . $lunch['admin_token']);
    }
}

if (!$pRows) { $pRows = [['name' => '', 'email' => '']]; }
if (!$sRows) { $sRows = [['date' => $defaultDate, 'time' => $defaultTime, 'duration' => '90']]; }

$rm = h(__('create.remove')); $dup = h(__('create.duplicate'));

page_header(__('create.title'), $org);
echo '<h1>' . h(__('create.h1')) . '</h1>';
foreach ($errors as $e) { echo '<div class="flash error">' . h($e) . '</div>'; }
?>
<form method="post" id="createForm">
  <?= csrf_field() ?>
  <div class="card">
    <label for="organizer_name"><?= h(__('create.my_name_label')) ?></label>
    <input type="text" id="organizer_name" name="organizer_name" value="<?= h($old['organizer_name']) ?>" placeholder="<?= h(__('create.my_name_ph')) ?>">
    <p class="help"><?= h(__('create.my_name_help')) ?></p>
    <label for="title"><?= h(__('create.title_label')) ?></label>
    <input type="text" id="title" name="title" required value="<?= h($old['title']) ?>" placeholder="<?= h(__('create.title_ph')) ?>">
    <label for="location"><?= h(__('create.location_label')) ?></label>
    <input type="text" id="location" name="location" value="<?= h($old['location']) ?>" placeholder="<?= h(__('create.location_ph')) ?>">
    <label for="deadline"><?= h(__('create.deadline_label')) ?></label>
    <input type="datetime-local" id="deadline" name="deadline" value="<?= h($old['deadline']) ?>">
  </div>

  <h2><?= h(__('create.slots_label')) ?></h2>
  <div class="card">
    <div id="slots">
      <?php foreach ($sRows as $r): ?>
      <div class="dyn-row">
        <div class="dyn-grid">
          <input class="fdate" type="date" name="sdate[]" value="<?= h($r['date']) ?>">
          <input type="time" name="stime[]" value="<?= h($r['time'] ?: $defaultTime) ?>">
          <input type="number" name="sduration[]" min="15" step="15" value="<?= h($r['duration'] ?: '90') ?>" aria-label="min">
        </div>
        <div class="rowbtns">
          <button type="button" class="btn-icon dup-slot" title="<?= $dup ?>">⧉</button>
          <button type="button" class="btn-icon del-slot" title="<?= $rm ?>">✕</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div id="dupWarn" class="warn-inline"><?= h(__('create.dup_warning')) ?></div>
    <button type="button" class="btn-sec btn-small" id="add-slot"><?= h(__('create.add_slot')) ?></button>
  </div>

  <h2><?= h(__('create.participants_label')) ?></h2>
  <div class="card">
    <div id="participants">
      <?php foreach ($pRows as $r): ?>
      <div class="dyn-row">
        <input class="name" type="text" name="pname[]" placeholder="<?= h(__('create.p_name')) ?>" value="<?= h($r['name']) ?>">
        <input class="email" type="email" name="pemail[]" placeholder="<?= h(__('create.p_email')) ?>" value="<?= h($r['email']) ?>">
        <div class="rowbtns"><button type="button" class="btn-icon del-part" title="<?= $rm ?>">✕</button></div>
      </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn-sec btn-small" id="add-participant"><?= h(__('create.add_participant')) ?></button>
  </div>

  <div class="card">
    <div class="switch-row">
      <span class="lbl"><?= h(__('create.i_participate')) ?></span>
      <label class="switch"><input type="checkbox" name="organizer_participates" value="1" id="participate" <?= !empty($_POST['organizer_participates']) ? 'checked' : '' ?>><span class="track"></span></label>
    </div>
  </div>

  <button type="submit" class="btn-block"><?= h(__('create.submit')) ?></button>
</form>

<script>
(function(){
  var DEF_DATE=<?= json_encode($defaultDate) ?>, DEF_TIME=<?= json_encode($defaultTime) ?>;
  var RM=<?= json_encode(__('create.remove')) ?>, DUP=<?= json_encode(__('create.duplicate')) ?>;

  function slotRow(date, time, dur){
    var d=document.createElement('div'); d.className='dyn-row';
    d.innerHTML='<div class="dyn-grid">'+
      '<input class="fdate" type="date" name="sdate[]" value="'+date+'">'+
      '<input type="time" name="stime[]" value="'+time+'">'+
      '<input type="number" name="sduration[]" min="15" step="15" value="'+(dur||90)+'"></div>'+
      '<div class="rowbtns"><button type="button" class="btn-icon dup-slot" title="'+DUP+'">⧉</button>'+
      '<button type="button" class="btn-icon del-slot" title="'+RM+'">✕</button></div>';
    return d;
  }
  function partRow(){
    var d=document.createElement('div'); d.className='dyn-row';
    d.innerHTML='<input class="name" type="text" name="pname[]" placeholder="<?= h(__('create.p_name')) ?>">'+
      '<input class="email" type="email" name="pemail[]" placeholder="<?= h(__('create.p_email')) ?>">'+
      '<div class="rowbtns"><button type="button" class="btn-icon del-part" title="'+RM+'">✕</button></div>';
    return d;
  }
  var slots=document.getElementById('slots'), parts=document.getElementById('participants');
  document.getElementById('add-slot').addEventListener('click',function(){var r=slotRow(DEF_DATE,DEF_TIME,90);slots.appendChild(r);r.querySelector('input').focus();checkDup();});
  document.getElementById('add-participant').addEventListener('click',function(){var r=partRow();parts.appendChild(r);r.querySelector('input').focus();});

  document.addEventListener('click',function(e){
    var b=e.target.closest('button'); if(!b)return;
    if(b.classList.contains('del-slot')){ if(slots.querySelectorAll('.dyn-row').length>1) b.closest('.dyn-row').remove(); checkDup(); }
    if(b.classList.contains('del-part')){ if(parts.querySelectorAll('.dyn-row').length>1) b.closest('.dyn-row').remove(); }
    if(b.classList.contains('dup-slot')){
      var row=b.closest('.dyn-row');
      var date=row.querySelector('input[name="sdate[]"]').value;
      var time=row.querySelector('input[name="stime[]"]').value;
      var dur=row.querySelector('input[name="sduration[]"]').value;
      var nr=slotRow(date,time,dur); row.after(nr); checkDup();
    }
  });

  function checkDup(){
    var rows=[].slice.call(slots.querySelectorAll('.dyn-row')); var seen={}, dup=false;
    rows.forEach(function(r){r.classList.remove('dup');});
    rows.forEach(function(r){
      var d=r.querySelector('input[name="sdate[]"]').value, t=r.querySelector('input[name="stime[]"]').value;
      if(!d||!t)return; var k=d+' '+t;
      if(seen[k]){ dup=true; r.classList.add('dup'); seen[k].classList.add('dup'); } else { seen[k]=r; }
    });
    document.getElementById('dupWarn').classList.toggle('show',dup);
  }
  slots.addEventListener('input',checkDup); checkDup();

})();
</script>
<?php
page_footer();
