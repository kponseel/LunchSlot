<?php
/**
 * Authentification organisateur sans mot de passe : magic link + session durable.
 * Connexion = inscription (l'organisateur est créé au premier login réussi).
 */

declare(strict_types=1);

const SESSION_COOKIE = 'ls_session';

/**
 * Émet un magic link. Ne révèle jamais si l'adresse est connue : l'appelant
 * affiche toujours le même message, quel que soit le résultat ici.
 */
function issue_magic_link(string $rawEmail): void
{
    $email = normalize_email($rawEmail);
    if (!valid_email($email)) {
        return; // silencieux : pas de fuite
    }
    if (magic_rate_exceeded($email, client_ip())) {
        return; // silencieux
    }

    $token = random_token(32);
    $ttl = (int) config('magic_link_ttl_minutes', 15) * 60;
    $ins = db()->prepare('INSERT INTO magic_links (email, token_hash, expires_at, created_ip, created_at) VALUES (?,?,?,?,?)');
    $ins->execute([$email, token_hash($token), utc_plus($ttl), client_ip(), now_utc()]);

    $ipIns = db()->prepare('INSERT INTO magic_ip_hits (ip, created_at) VALUES (?,?)');
    $ipIns->execute([client_ip(), now_utc()]);

    $link = rtrim(config('app_url'), '/') . '/verify.php?token=' . $token;
    send_magic_link($email, $link);
}

/** Vrai si le quota d'envoi (par email OU par IP) est dépassé. */
function magic_rate_exceeded(string $email, string $ip): bool
{
    $perEmail = (int) config('magic_per_email_per_15min', 3);
    $perIp = (int) config('magic_per_ip_per_hour', 10);

    $st = db()->prepare("SELECT COUNT(*) FROM magic_links WHERE email = ? AND created_at > ?");
    $st->execute([$email, utc_plus(-15 * 60)]);
    if ((int) $st->fetchColumn() >= $perEmail) {
        return true;
    }

    $st2 = db()->prepare("SELECT COUNT(*) FROM magic_ip_hits WHERE ip = ? AND created_at > ?");
    $st2->execute([$ip, utc_plus(-60 * 60)]);
    if ((int) $st2->fetchColumn() >= $perIp) {
        return true;
    }
    return false;
}

/**
 * Consomme un magic link : le valide, l'invalide (usage unique), ouvre une session.
 * @return array|null organisateur connecté, ou null si lien invalide/expiré/déjà utilisé.
 */
function consume_magic_link(string $token): ?array
{
    $hash = token_hash($token);
    $st = db()->prepare('SELECT * FROM magic_links WHERE token_hash = ? LIMIT 1');
    $st->execute([$hash]);
    $link = $st->fetch();

    if (!$link || $link['used_at'] !== null || $link['expires_at'] <= now_utc()) {
        return null;
    }

    // Invalidation immédiate (usage unique).
    db()->prepare('UPDATE magic_links SET used_at = ? WHERE id = ?')->execute([now_utc(), $link['id']]);

    ensure_organizer($link['email']);
    $org = db()->prepare('SELECT * FROM organizers WHERE email = ?');
    $org->execute([$link['email']]);
    $organizer = $org->fetch();

    db()->prepare('UPDATE organizers SET last_login_at = ? WHERE id = ?')->execute([now_utc(), $organizer['id']]);

    start_session_for_organizer((int) $organizer['id']);
    return $organizer;
}

function start_session_for_organizer(int $organizerId): void
{
    $token = random_token(32);
    $ttl = (int) config('session_ttl_days', 30) * 86400;
    $ins = db()->prepare('INSERT INTO sessions (organizer_id, token_hash, expires_at, created_at, last_seen_at) VALUES (?,?,?,?,?)');
    $ins->execute([$organizerId, token_hash($token), utc_plus($ttl), now_utc(), now_utc()]);

    setcookie(SESSION_COOKIE, $token, [
        'expires'  => time() + $ttl,
        'path'     => '/',
        'secure'   => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Organisateur courant (via cookie de session), ou null. */
function current_organizer(): ?array
{
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token === '') {
        return null;
    }
    $st = db()->prepare('SELECT s.id AS sid, o.* FROM sessions s
        JOIN organizers o ON o.id = s.organizer_id
        WHERE s.token_hash = ? AND s.expires_at > ? LIMIT 1');
    $st->execute([token_hash($token), now_utc()]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    db()->prepare('UPDATE sessions SET last_seen_at = ? WHERE id = ?')->execute([now_utc(), $row['sid']]);
    unset($row['sid']);
    return $row;
}

function logout_organizer(): void
{
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token !== '') {
        db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([token_hash($token)]);
    }
    setcookie(SESSION_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
}

/** Exige une session ; redirige vers l'accueil sinon. */
function require_login(): array
{
    $org = current_organizer();
    if (!$org) {
        flash(__('auth.please_login'), 'info');
        redirect('index.php');
    }
    return $org;
}

/** Purge légère des jetons expirés/consommés (best effort). */
function purge_expired(): void
{
    db()->prepare("DELETE FROM magic_links WHERE used_at IS NOT NULL OR expires_at < ?")->execute([utc_plus(-86400)]);
    db()->prepare("DELETE FROM sessions WHERE expires_at < ?")->execute([now_utc()]);
    db()->prepare("DELETE FROM magic_ip_hits WHERE created_at < ?")->execute([utc_plus(-3600)]);
}
