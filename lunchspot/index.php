<?php
require_once __DIR__ . '/inc/bootstrap.inc.php';

if (current_organizer()) {
    redirect('mes-dejeuners.php');
}

page_header(__('index.title'));
?>
<h1><?= h(__('index.h1')) ?></h1>
<div class="card"><p><?= __('index.pitch') ?></p></div>

<div class="card">
  <h2><?= h(__('index.login_h2')) ?></h2>
  <p class="muted"><?= h(__('index.login_help', ['min' => (int) config('magic_link_ttl_minutes', 15)])) ?></p>
  <form method="post" action="<?= h(rtrim(config('app_url'), '/')) ?>/login.php">
    <?= csrf_field() ?>
    <label for="email"><?= h(__('index.email_label')) ?></label>
    <input type="email" id="email" name="email" required autofocus placeholder="prenom.nom@exemple.com">
    <p style="margin-top:14px;"><button type="submit"><?= h(__('index.login_btn')) ?></button></p>
  </form>
</div>
<?php
page_footer();
