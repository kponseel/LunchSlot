<?php
require_once __DIR__ . '/inc/bootstrap.inc.php';

$lang = (string) ($_GET['lang'] ?? 'fr');
if (!in_array($lang, SUPPORTED_LOCALES, true)) {
    $lang = 'fr';
}
$https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443');
setcookie('ls_lang', $lang, [
    'expires'  => time() + 365 * 86400,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => false,
    'samesite' => 'Lax',
]);

$ref = $_SERVER['HTTP_REFERER'] ?? '';
$base = rtrim(config('app_url'), '/');
if ($ref && strpos($ref, $base) === 0) {
    header('Location: ' . $ref);
} else {
    header('Location: ' . $base . '/index.php');
}
exit;
