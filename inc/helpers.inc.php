<?php
/**
 * Fonctions utilitaires : config, échappement, jetons, CSRF, dates/fuseaux, flash.
 */

declare(strict_types=1);

/** Accès à une clé de configuration. */
function config(string $key, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require dirname(__DIR__) . '/config.php';
    }
    return $cfg[$key] ?? $default;
}

/** Échappement HTML systématique (sortie). */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redirection absolue puis arrêt. */
function redirect(string $path): void
{
    if (!preg_match('#^https?://#', $path)) {
        $path = rtrim(config('app_url'), '/') . '/' . ltrim($path, '/');
    }
    header('Location: ' . $path);
    exit;
}

/** Chemin effectif de la base (surchargé par LUNCHSPOT_DB pour les tests). */
function ls_db_path(): string
{
    $env = getenv('LUNCHSPOT_DB');
    return ($env !== false && $env !== '') ? $env : (string) config('db_path');
}

/** Dossier de données (base + journaux mail). */
function data_dir(): string
{
    return dirname(ls_db_path());
}

/** Horodatage UTC courant 'Y-m-d H:i:s'. */
function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Décale un UTC 'Y-m-d H:i:s' de N secondes. */
function utc_plus(int $seconds): string
{
    return gmdate('Y-m-d H:i:s', time() + $seconds);
}

/** Jeton aléatoire non devinable (URL-safe, ~192 bits par défaut). */
function random_token(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/** Hash de stockage d'un jeton (on ne stocke jamais le jeton en clair). */
function token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Normalisation email organisateur : minimale et sûre (trim + minuscules). */
function normalize_email(string $email): string
{
    return mb_strtolower(trim($email), 'UTF-8');
}

/** Validation email simple. */
function valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/* ----------------------------------------------------------------------
 * CSRF
 * ------------------------------------------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_token(16);
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(400);
        exit('Requête invalide (CSRF).');
    }
}

/* ----------------------------------------------------------------------
 * Fuseaux horaires — affichage local vs stockage/UTC
 * ------------------------------------------------------------------- */

function app_tz(): DateTimeZone
{
    return new DateTimeZone(config('timezone', 'Europe/Paris'));
}

/** Convertit une saisie locale (date + heure) en UTC 'Y-m-d H:i:s'. */
function local_to_utc(string $localDateTime): string
{
    $dt = new DateTime($localDateTime, app_tz());
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

/** DateTime UTC à partir d'un 'Y-m-d H:i:s' stocké. */
function utc_dt(string $utc): DateTime
{
    return new DateTime($utc, new DateTimeZone('UTC'));
}

/** Affichage humain d'un créneau (fuseau local, langue courante). */
function fmt_slot(string $startUtc, int $durationMin): string
{
    $en = (function_exists('current_locale') && current_locale() === 'en');
    $daysFr = ['Sunday' => 'dim', 'Monday' => 'lun', 'Tuesday' => 'mar', 'Wednesday' => 'mer',
        'Thursday' => 'jeu', 'Friday' => 'ven', 'Saturday' => 'sam'];
    $monthsFr = [1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.',
        'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $daysEn = ['Sunday' => 'Sun', 'Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed',
        'Thursday' => 'Thu', 'Friday' => 'Fri', 'Saturday' => 'Sat'];
    $monthsEn = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    $dt = utc_dt($startUtc);
    $dt->setTimezone(app_tz());
    $end = clone $dt;
    $end->modify('+' . $durationMin . ' minutes');
    $j = ($en ? $daysEn : $daysFr)[$dt->format('l')] ?? '';
    $m = ($en ? $monthsEn : $monthsFr)[(int) $dt->format('n')];

    if ($en) {
        return sprintf('%s, %s %s %s — %s→%s', $j, $m, $dt->format('j'), $dt->format('Y'),
            $dt->format('H:i'), $end->format('H:i'));
    }
    return sprintf('%s %s %s %s — %s→%s', $j, $dt->format('j'), $m, $dt->format('Y'),
        $dt->format('H\hi'), $end->format('H\hi'));
}

/** Format court date/heure locale (ex. pour deadline). */
function fmt_datetime(string $utc): string
{
    $dt = utc_dt($utc);
    $dt->setTimezone(app_tz());
    if (function_exists('current_locale') && current_locale() === 'en') {
        return $dt->format('Y-m-d \a\t H:i');
    }
    return $dt->format('d/m/Y à H\hi');
}

/* ----------------------------------------------------------------------
 * Messages flash (via session)
 * ------------------------------------------------------------------- */

function flash(string $msg, string $type = 'info'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** IP client (best effort). */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Détecte HTTPS, y compris derrière un proxy/load-balancer (Hostinger) qui
 * signale le schéma via X-Forwarded-Proto. Sert à poser le flag Secure des cookies.
 */
function request_is_https(): bool
{
    if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    $xf = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($xf === 'https') {
        return true;
    }
    // Repli : si app_url est en https, on considère la prod comme sécurisée.
    return str_starts_with((string) config('app_url', ''), 'https://');
}

/**
 * Mémorise (et retourne) la dernière erreur d'envoi d'email.
 * Sert au diagnostic : sans cela, un échec SMTP est invisible côté interface.
 */
function mail_error(?string $set = null): string
{
    static $err = '';
    if ($set !== null) {
        $err = $set;
        error_log('LunchSpot mail: ' . $set);
    }
    return $err;
}

/** Nettoie une saisie sur une seule ligne : trim + suppression des CR/LF et caractères de contrôle. */
function clean_line(string $s): string
{
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    $s = preg_replace('/[\x00-\x1f\x7f]/u', '', $s) ?? '';
    return trim($s);
}
