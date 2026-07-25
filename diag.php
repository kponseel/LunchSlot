<?php
/**
 * Diagnostic d'installation (accès protégé).
 *
 * Activation : ajoutez dans config.php une clé secrète, par ex.
 *     'diag_key' => 'une-chaine-secrete',
 * puis ouvrez https://…/diag.php?key=une-chaine-secrete
 *
 * Sans clé configurée (ou clé fausse) la page répond 404 : rien n'est exposé.
 *
 * Actions :
 *   &test=adresse@exemple.com   envoie un email de test et affiche l'erreur exacte
 *   &unblock=adresse@exemple.com  remet à zéro l'anti-abus (quota magic link)
 */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.inc.php';

$key = (string) config('diag_key', '');
$given = (string) ($_GET['key'] ?? '');
if ($key === '' || !hash_equals($key, $given)) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

function line(string $label, string $value): void
{
    printf("%-22s %s\n", $label, $value);
}

echo "=== LunchSpot — diagnostic ===\n\n";

/* --- Environnement --- */
line('PHP', PHP_VERSION);
line('pdo_sqlite', extension_loaded('pdo_sqlite') ? 'OK' : 'MANQUANT');
line('openssl', extension_loaded('openssl') ? 'OK' : 'MANQUANT');
line('mbstring', extension_loaded('mbstring') ? 'OK' : 'MANQUANT');

/* --- Configuration (sans secrets) --- */
echo "\n--- Configuration ---\n";
line('app_url', (string) config('app_url'));
line('URL réellement vue', (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? (request_is_https() ? 'https' : 'http'))) . '://' . ($_SERVER['HTTP_HOST'] ?? '?'));
line('HTTPS détecté', request_is_https() ? 'oui' : 'non');
line('timezone', (string) config('timezone'));
line('mail_transport', (string) config('mail_transport'));
line('mail_from', (string) config('mail_from'));
if (config('mail_transport') === 'smtp') {
    line('smtp_host', (string) config('smtp_host'));
    line('smtp_port', (string) config('smtp_port'));
    line('smtp_security', (string) config('smtp_security'));
    line('smtp_user', (string) config('smtp_user'));
    line('smtp_pass', config('smtp_pass') !== '' ? '(défini, ' . strlen((string) config('smtp_pass')) . ' caractères)' : '(VIDE !)');
}

/* --- Stockage --- */
echo "\n--- Stockage ---\n";
$dir = data_dir();
line('dossier data', $dir);
line('inscriptible', is_writable($dir) ? 'OK' : 'NON — corrigez les permissions (755/775)');
line('base', is_file(ls_db_path()) ? 'présente (' . number_format((float) filesize(ls_db_path()) / 1024, 1) . ' Ko)' : 'sera créée au 1er usage');
try {
    $n = (int) db()->query('SELECT COUNT(*) FROM lunches')->fetchColumn();
    line('déjeuners en base', (string) $n);
    line('organisateurs', (string) (int) db()->query('SELECT COUNT(*) FROM organizers')->fetchColumn());
} catch (Throwable $e) {
    line('base', 'ERREUR : ' . $e->getMessage());
}

/* --- Anti-abus magic link --- */
$email = normalize_email((string) ($_GET['unblock'] ?? $_GET['email'] ?? ''));
if ($email !== '' && valid_email($email)) {
    echo "\n--- Anti-abus (" . $email . ") ---\n";
    if (isset($_GET['unblock'])) {
        db()->prepare('DELETE FROM magic_links WHERE email = ?')->execute([$email]);
        db()->prepare('DELETE FROM magic_ip_hits WHERE ip = ?')->execute([client_ip()]);
        line('réinitialisation', 'FAITE — vous pouvez redemander un lien immédiatement');
    }
    $st = db()->prepare('SELECT COUNT(*) FROM magic_links WHERE email = ? AND created_at > ?');
    $st->execute([$email, utc_plus(-15 * 60)]);
    $used = (int) $st->fetchColumn();
    $max = (int) config('magic_per_email_per_15min', 3);
    line('envois (15 dern. min)', $used . ' / ' . $max . ($used >= $max ? '  ← BLOQUÉ' : ''));
    $st2 = db()->prepare('SELECT COUNT(*) FROM magic_ip_hits WHERE ip = ? AND created_at > ?');
    $st2->execute([client_ip(), utc_plus(-3600)]);
    line('envois IP (1 h)', (int) $st2->fetchColumn() . ' / ' . (int) config('magic_per_ip_per_hour', 10));
}

/* --- Test d'envoi réel --- */
$test = trim((string) ($_GET['test'] ?? ''));
if ($test !== '') {
    echo "\n--- Test d'envoi vers " . $test . " ---\n";
    if (!valid_email($test)) {
        line('résultat', 'adresse invalide');
    } else {
        $ok = send_mail(
            $test,
            'LunchSpot — email de test',
            '<p>Ceci est un email de test envoyé depuis le diagnostic LunchSpot.</p>',
            "Ceci est un email de test envoyé depuis le diagnostic LunchSpot."
        );
        line('résultat', $ok ? 'ENVOYÉ ✔ (vérifiez la boîte, et les spams)' : 'ÉCHEC ✘');
        if (!$ok) {
            line('erreur', mail_error() !== '' ? mail_error() : '(aucun détail)');
        }
    }
}

echo "\n--- Aide ---\n";
echo "Test d'envoi   : ?key=…&test=votre@email.fr\n";
echo "Débloquer quota: ?key=…&unblock=votre@email.fr\n";
echo "Pensez à retirer 'diag_key' de config.php une fois le diagnostic terminé.\n";
