<?php
/**
 * Client SMTP minimal (sans dépendance) : STARTTLS ou SSL implicite, AUTH LOGIN.
 * Suffisant pour un hébergement mutualisé ; pour un usage intensif, préférer un
 * vrai relais SMTP.
 */

declare(strict_types=1);

function smtp_send(string $from, string $to, string $subject, array $headers, string $body, array $opts = []): bool
{
    $host = config('smtp_host');
    $port = (int) config('smtp_port', 587);
    $security = config('smtp_security', 'tls');
    $user = config('smtp_user');
    $pass = config('smtp_pass');

    if ($host === '') {
        error_log('LunchSlot SMTP: smtp_host non configuré');
        return false;
    }

    $remote = ($security === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $ctx = stream_context_create();
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        error_log("LunchSlot SMTP: connexion échouée $errstr ($errno)");
        return false;
    }

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = function (string $c) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        return $read();
    };

    $read(); // bannière
    $ehloName = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $cmd('EHLO ' . $ehloName);

    if ($security === 'tls') {
        $cmd('STARTTLS');
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('LunchSlot SMTP: STARTTLS échoué');
            fclose($fp);
            return false;
        }
        $cmd('EHLO ' . $ehloName);
    }

    if ($user !== '') {
        $cmd('AUTH LOGIN');
        $cmd(base64_encode($user));
        $resp = $cmd(base64_encode($pass));
        if (strncmp($resp, '235', 3) !== 0) {
            error_log('LunchSlot SMTP: authentification refusée');
            fclose($fp);
            return false;
        }
    }

    $cmd('MAIL FROM:<' . $from . '>');
    $rcpt = $cmd('RCPT TO:<' . $to . '>');
    if (strncmp($rcpt, '25', 2) !== 0) {
        error_log('LunchSlot SMTP: RCPT refusé: ' . trim($rcpt));
        fclose($fp);
        return false;
    }

    $cmd('DATA');
    $data = 'To: ' . $to . "\r\n" . 'Subject: ' . $subject . "\r\n"
        . implode("\r\n", $headers) . "\r\n\r\n" . $body;
    // Point-stuffing.
    $data = preg_replace('/^\./m', '..', $data);
    $resp = $cmd($data . "\r\n.");
    $cmd('QUIT');
    fclose($fp);

    return strncmp($resp, '250', 3) === 0;
}
