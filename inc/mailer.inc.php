<?php
/**
 * Envoi d'emails : transport 'log' (dev/test), 'mail' (mail()), 'smtp'.
 * Construit un MIME multipart (texte + HTML) avec pièces jointes .ics.
 */

declare(strict_types=1);

require_once __DIR__ . '/smtp.inc.php';

/**
 * @param array $opts Clés facultatives :
 *   - reply_to : string
 *   - attachments : liste de ['filename'=>, 'content'=>, 'mime'=>]
 * @return bool succès
 */
function send_mail(string $to, string $subject, string $html, string $text, array $opts = []): bool
{
    $fromEmail = config('mail_from');
    $fromName  = config('mail_from_name', 'LunchSpot');
    $boundaryMixed = 'mix_' . bin2hex(random_bytes(12));
    $boundaryAlt   = 'alt_' . bin2hex(random_bytes(12));

    $headers = [];
    $headers[] = 'From: ' . mime_name($fromName) . ' <' . $fromEmail . '>';
    if (!empty($opts['reply_to'])) {
        $headers[] = 'Reply-To: ' . $opts['reply_to'];
    }
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundaryMixed . '"';

    // Corps.
    $body = "--$boundaryMixed\r\n";
    $body .= "Content-Type: multipart/alternative; boundary=\"$boundaryAlt\"\r\n\r\n";

    $body .= "--$boundaryAlt\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($text) . "\r\n";

    $body .= "--$boundaryAlt\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($html) . "\r\n";

    $body .= "--$boundaryAlt--\r\n";

    foreach ($opts['attachments'] ?? [] as $att) {
        $mime = $att['mime'] ?? 'application/octet-stream';
        $body .= "--$boundaryMixed\r\n";
        $body .= "Content-Type: $mime; name=\"" . $att['filename'] . "\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"" . $att['filename'] . "\"\r\n\r\n";
        $body .= chunk_split(base64_encode($att['content'])) . "\r\n";
    }

    $body .= "--$boundaryMixed--\r\n";

    $encodedSubject = mime_encode($subject);
    $transport = config('mail_transport', 'log');

    if ($transport === 'log') {
        return mail_log($to, $encodedSubject, $headers, $body);
    }
    if ($transport === 'smtp') {
        return smtp_send($fromEmail, $to, $encodedSubject, $headers, $body, $opts);
    }
    // Transport 'mail' par défaut.
    $ok = mail($to, $encodedSubject, $body, implode("\r\n", $headers));
    if (!$ok) {
        mail_error('mail() a échoué (transport « mail »). Essayez le transport SMTP.');
    }
    return $ok;
}

/** Encodage MIME d'un en-tête (sujet). */
function mime_encode(string $text): string
{
    if (preg_match('/[^\x20-\x7e]/', $text)) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
    return $text;
}

/** Nom d'expéditeur encodé si non-ASCII. */
function mime_name(string $name): string
{
    return mime_encode($name);
}

/** Transport 'log' : écrit l'email dans data/maillog/ au lieu de l'envoyer. */
function mail_log(string $to, string $subject, array $headers, string $body): bool
{
    $dir = data_dir() . '/maillog';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    $file = $dir . '/' . gmdate('Ymd-His') . '-' . substr(md5($to . $subject . microtime()), 0, 8) . '.eml';
    $content = 'To: ' . $to . "\r\n" . 'Subject: ' . $subject . "\r\n"
        . implode("\r\n", $headers) . "\r\n\r\n" . $body;
    return file_put_contents($file, $content) !== false;
}
