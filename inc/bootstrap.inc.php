<?php
/**
 * Amorçage commun à toutes les pages : session, config, includes, fuseau.
 */

declare(strict_types=1);

mb_internal_encoding('UTF-8');

require_once __DIR__ . '/helpers.inc.php';
require_once __DIR__ . '/i18n.inc.php';
require_once __DIR__ . '/db.inc.php';
require_once __DIR__ . '/ical.inc.php';
require_once __DIR__ . '/gcal.inc.php';
require_once __DIR__ . '/mailer.inc.php';
require_once __DIR__ . '/emails.inc.php';
require_once __DIR__ . '/model.inc.php';
require_once __DIR__ . '/engine.inc.php';
require_once __DIR__ . '/auth.inc.php';
require_once __DIR__ . '/layout.inc.php';

// Gestion des erreurs : jamais affichées en production (évite pages blanches
// verbeuses et fuite de chemins) ; activables via 'debug' => true dans config.php.
$ls_debug = (bool) config('debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $ls_debug ? '1' : '0');
ini_set('log_errors', '1');

// Fuseau interne = UTC ; l'affichage local se fait explicitement via helpers.
date_default_timezone_set('UTC');

// Session PHP (CSRF, flash, verrou de connexion). Cookie durci.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('ls_php');
    session_start();
}

// Ouvre la base + applique les migrations dès le premier appel.
db();
