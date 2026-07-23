<?php
require_once __DIR__ . '/inc/bootstrap.inc.php';

$token = (string) ($_GET['token'] ?? '');
$organizer = $token !== '' ? consume_magic_link($token) : null;

if (!$organizer) {
    page_header(__('verify.invalid_title'));
    echo '<h1>' . h(__('verify.invalid_h1')) . '</h1>';
    echo '<div class="card"><p>' . h(__('verify.invalid_body', ['min' => (int) config('magic_link_ttl_minutes', 15)])) . '</p>'
        . '<p><a class="btn" href="' . h(rtrim(config('app_url'), '/')) . '/index.php">' . h(__('verify.request_new')) . '</a></p></div>';
    page_footer();
    exit;
}

purge_expired();
flash(__('verify.connected'), 'success');
redirect('mes-dejeuners.php');
