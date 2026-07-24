<?php
/**
 * Routeur pour le serveur PHP intégré (dév/test), l'appli étant à la racine :
 *   php -S localhost:8000 router.php
 *
 * Reproduit les protections du .htaccess (data/, config.php, *.inc.php, .git,
 * tests/, docs/) que le serveur intégré n'applique pas. En production Apache,
 * ce fichier est inutile.
 */

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// Bloque l'accès aux ressources sensibles / non destinées au web.
if (preg_match('#(^|/)(data/|config\.php|config\.example\.php)#', $uri)
    || preg_match('#(^|/)(\.git|\.github|tests|docs)(/|$)#', $uri)
    || preg_match('#\.inc\.php$#', $uri)
    || preg_match('#\.sqlite#', $uri)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false; // sert le fichier tel quel (statique ou PHP)
}

// Défaut : page d'accueil.
require __DIR__ . '/index.php';
return true;
