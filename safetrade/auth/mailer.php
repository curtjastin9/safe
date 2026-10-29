<?php
declare(strict_types=1);

/**
 * ---------------------------------------------------------------------
 * Minimal, dependency-free SMTP mail sender.
 *
 * No Composer, no PHPMailer, no external library to install — just raw
 * sockets speaking SMTP directly, with STARTTLS + AUTH LOGIN. That
 * combination is all any of the common *free* mail options need:
 *
 *   - Gmail SMTP           smtp.gmail.com   : 587   (needs a Google
 *     "App Password", not your normal password — Google Account ->
 *     Security -> 2-Step Verification -> App passwords). Free, but
 *     capped around 500 emails/day.
 *   - Brevo (ex-Sendinblue) smtp-relay.brevo.com : 587 — free tier is
 *     300 emails/day, no card required, and is meant for exactly this
 *     (transactional emails from an app), so it's the one to reach for
 *     once Gmail's cap feels tight.
 *   - Outlook / Office365  smtp.office365.com : 587
 *   - Mailtrap.io — NOT for real delivery, but great for local dev:
 *     it "sends" email into a sandbox inbox you view online, so you
 *     can test this whole flow without spamming real addresses while
 *     building.
 *
 * Configure via environment variables (same pattern as DB_HOST etc. in
 * config/database.php) so no credentials are ever hardcoded here:
 *
 *   SAFETRADE_SMTP_HOST=smtp.gmail.com
 *   SAFETRADE_SMTP_PORT=587
 *   SAFETRADE_SMTP_USER=youraddress@gmail.com
 *   SAFETRADE_SMTP_PASS=your16charapppassword
 *   SAFETRADE_SMTP_FROM=youraddress@gmail.com
 *   SAFETRADE_SMTP_FROM_NAME=SafeTrade
 *
 * On XAMPP, set these in a .env you load yourself, in Apache's
 * httpd-vhosts config (SetEnv), or just hardcode temporarily for local
 * testing — never commit real credentials.
 * ---------------------------------------------------------------------
 */

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST',      getenv('SAFETRADE_SMTP_HOST') ?: '');
    define('SMTP_PORT',      (int) (getenv('SAFETRADE_SMTP_PORT') ?: 587));
    define('SMTP_USER',      getenv('SAFETRADE_SMTP_USER') ?: '');
    define('SMTP_PASS',      getenv('SAFETRADE_SMTP_PASS') ?: '');
    define('SMTP_FROM_EMAIL', getenv('SAFETRADE_SMTP_FROM') ?: SMTP_USER);
    define('SMTP_FROM_NAME',  getenv('SAFETRADE_SMTP_FROM_NAME') ?: 'SafeTrade');
}

/**
 * Returns the reason the most recent send_email() call failed, or null
 * if the last call succeeded (or none has run yet). Exists so failures
 * can be shown directly on-screen during setup/testing, instead of
 * requiring access to the server's error_log to debug SMTP problems.
 */
function smtp_last_error(): ?string
{
    return $GLOBALS['__safetrade_smtp_last_error'] ?? null;
}

/**
 * Sends a plain-text + HTML email over SMTP with STARTTLS.
 *
 * Returns true on success, false on failure. NEVER throws — a mail
 * server hiccup should never take down whatever triggered the email
 * (a password reset, an admin approving someone, etc). Failures are
 * written to error_log AND to smtp_last_error() so they're visible
 * either way.
 */
if (!function_exists('send_email')):
function send_email(string $toEmail, string $toName, string $subject, string $bodyText, ?string $bodyHtml = null): bool
{
    if (SMTP_HOST === '' || SMTP_USER === '' || SMTP_PASS === '') {
        $msg = 'SMTP not configured (SAFETRADE_SMTP_HOST/USER/PASS are empty) — skipping email to ' . $toEmail;
        error_log('[SafeTrade Mailer] ' . $msg);
        $GLOBALS['__safetrade_smtp_last_error'] = $msg;
        return false;
    }

    $socket = null;
    try {
        $socket = @stream_socket_client(
            'tcp://' . SMTP_HOST . ':' . SMTP_PORT,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT
        );
        if (!$socket) {
            throw new RuntimeException("Could not connect to " . SMTP_HOST . ":" . SMTP_PORT . " — $errstr ($errno)");
        }
        stream_set_timeout($socket, 15);

        $readResponse = function () use ($socket): string {
            $data = '';
            while (($line = fgets($socket, 515)) !== false) {
                $data .= $line;
                // A response's final line has a space (not a dash) as the
                // 4th character — anything before that is a continuation
                // line of a multi-line reply (e.g. EHLO's capability list).
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $data;
        };
        $expect = function (string $context) use ($readResponse): string {
            $data = $readResponse();
            $code = (int) substr($data, 0, 3);
            if ($code === 0 || $code >= 400) {
                throw new RuntimeException("SMTP error during $context: " . trim($data));
            }
            return $data;
        };
        $send = function (string $cmd) use ($socket): void {
            fwrite($socket, $cmd . "\r\n");
        };

        $expect('connect');

        $heloHost = parse_url(defined('BASE_URL') ? BASE_URL : '', PHP_URL_HOST) ?: 'localhost';

        $send('EHLO ' . $heloHost);
        $expect('EHLO');

        $send('STARTTLS');
        $expect('STARTTLS');
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('TLS handshake with the SMTP server failed');
        }

        // RFC 3207: must re-issue EHLO after STARTTLS.
        $send('EHLO ' . $heloHost);
        $expect('EHLO after STARTTLS');

        $send('AUTH LOGIN');
        $expect('AUTH LOGIN');
        $send(base64_encode(SMTP_USER));
        $expect('AUTH username');
        $send(base64_encode(SMTP_PASS));
        $expect('AUTH password (check SMTP_USER / SMTP_PASS — for Gmail this must be an App Password, not your login password)');

        $send('MAIL FROM:<' . SMTP_FROM_EMAIL . '>');
        $expect('MAIL FROM');
        $send('RCPT TO:<' . $toEmail . '>');
        $expect('RCPT TO');
        $send('DATA');
        $expect('DATA');

        $boundary = 'safetrade-' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . '>',
            'To: ' . ($toName !== '' ? $toName . ' <' . $toEmail . '>' : $toEmail),
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8'),
            'MIME-Version: 1.0',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $heloHost . '>',
        ];

        if ($bodyHtml !== null) {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body  = "--$boundary\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
            $body .= $bodyText . "\r\n\r\n";
            $body .= "--$boundary\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
            $body .= $bodyHtml . "\r\n\r\n";
            $body .= "--$boundary--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $body = $bodyText;
        }

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        // RFC 5321 dot-stuffing: a line starting with "." must be escaped
        // to "..", or the SMTP server reads a lone "." as end-of-message.
        $message = preg_replace('/\r\n\./', "\r\n..", $message);

        $send($message . "\r\n.");
        $expect('message body');

        $send('QUIT');
        fclose($socket);
        $GLOBALS['__safetrade_smtp_last_error'] = null;
        return true;
    } catch (Throwable $e) {
        error_log('[SafeTrade Mailer] Failed to send to ' . $toEmail . ': ' . $e->getMessage());
        $GLOBALS['__safetrade_smtp_last_error'] = $e->getMessage();
        if (is_resource($socket)) {
            fclose($socket);
        }
        return false;
    }
}
endif;

/**
 * Convenience wrapper: wraps a message in SafeTrade's own look (matches
 * the app.css palette) so every notification email looks consistent,
 * without having to hand-write HTML at every call site.
 */
if (!function_exists('send_branded_email')):
function send_branded_email(
    string $toEmail,
    string $toName,
    string $subject,
    string $heading,
    string $bodyHtml,
    ?string $ctaUrl = null,
    ?string $ctaLabel = null
): bool {
    $plain = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml)));
    if ($ctaUrl && $ctaLabel) {
        $plain .= "\n\n$ctaLabel: $ctaUrl";
    }

    $cta = '';
    if ($ctaUrl && $ctaLabel) {
        $cta = '<p style="margin:28px 0 0;">'
             . '<a href="' . htmlspecialchars($ctaUrl) . '" style="background:#155943;color:#FBF7EF;padding:12px 22px;'
             . 'border-radius:999px;text-decoration:none;font-weight:600;display:inline-block;font-family:sans-serif;font-size:14px;">'
             . htmlspecialchars($ctaLabel) . '</a></p>';
    }

    $html = '
    <div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;background:#FBF7EF;padding:32px;">
      <div style="max-width:480px;margin:0 auto;background:#F4EEE1;border-radius:20px;padding:32px;">
        <div style="font-family:Georgia,serif;font-weight:700;font-size:19px;color:#155943;margin-bottom:20px;">&#10003; SafeTrade</div>
        <h2 style="font-family:Georgia,serif;font-size:20px;color:#161F16;margin:0 0 14px;">' . htmlspecialchars($heading) . '</h2>
        <div style="font-size:14px;line-height:1.65;color:#5A6252;">' . $bodyHtml . '</div>
        ' . $cta . '
        <p style="margin:30px 0 0;font-size:11.5px;color:#8B927F;">This is an automated message from SafeTrade — please don\'t reply to it directly.</p>
      </div>
    </div>';

    return send_email($toEmail, $toName, $subject, $plain, $html);
}
endif;
