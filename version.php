<?php
/**
 * Petit point de contrôle de déploiement : indique quelle version du code est
 * réellement servie. Aucune donnée sensible n'est exposée.
 *
 *   https://…/version.php
 */

declare(strict_types=1);

// À incrémenter à chaque évolution notable de l'interface.
const LS_VERSION = '2.0-ui-mobile';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$layout = __DIR__ . '/inc/layout.inc.php';
$newUi = is_file($layout) && strpos((string) file_get_contents($layout), 'prefers-color-scheme') !== false;

echo "LunchSpot\n";
echo "version   : " . LS_VERSION . "\n";
echo "interface : " . ($newUi ? "nouvelle (mobile-first) ✔" : "ANCIENNE ✘ — les fichiers servis ne sont pas à jour") . "\n";
echo "index.php : " . (is_file(__DIR__ . '/index.php') ? gmdate('Y-m-d H:i:s', (int) filemtime(__DIR__ . '/index.php')) . ' UTC' : 'absent') . "\n";
echo "depot git : " . (is_dir(__DIR__ . '/.git') ? "présent (déploiement Git)" : "absent (déploiement manuel ?)") . "\n";
echo "php       : " . PHP_VERSION . "\n";
echo "opcache   : " . (function_exists('opcache_get_status') ? 'disponible' : 'absent') . "\n";
