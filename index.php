<?php
require_once __DIR__ . '/inc/bootstrap.inc.php';

if (current_organizer()) {
    redirect('mes-dejeuners.php');
}

page_header(__('index.title'));
?>
<div class="hero">
  <h1><?= h(__('index.h1')) ?></h1>
  <p class="sub"><?= __('index.pitch') ?></p>
</div>

<div class="card">
  <h2 style="margin-top:4px"><?= h(__('index.login_h2')) ?></h2>
  <p class="help" style="margin-bottom:6px"><?= h(__('index.login_help', ['min' => (int) config('magic_link_ttl_minutes', 15)])) ?></p>
  <form method="post" action="<?= h(rtrim(config('app_url'), '/')) ?>/login.php">
    <?= csrf_field() ?>
    <label for="email"><?= h(__('index.email_label')) ?></label>
    <input type="email" id="email" name="email" required autofocus autocomplete="email" inputmode="email" placeholder="prenom.nom@exemple.com">
    <p style="margin-top:16px"><button type="submit" class="btn-block"><?= h(__('index.login_btn')) ?></button></p>
  </form>
</div>
<?php
page_footer();
