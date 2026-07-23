<?php
require_once __DIR__ . '/inc/bootstrap.inc.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
csrf_check();

$email = (string) ($_POST['email'] ?? '');
issue_magic_link($email);

// Message identique quel que soit le résultat (anti-énumération).
flash(__('login.sent'), 'success');
redirect('index.php');
