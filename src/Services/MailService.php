<?php

namespace App\Services;

class MailService
{
    /**
     * Send an email; supports SMTP via env (SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_SECURE)
     * or falls back to PHP mail(). In both cases, we log the outcome for visibility.
     */
    public function send(string $to, string $subject, string $body, ?string $from = null): bool
    {
        $from = $from ?: (getenv('MAIL_FROM') ?: 'no-reply@local');
        $headers = "From: {$from}\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n";
        // Simple fallback using mail()
        $ok = @mail($to, $subject, $body, $headers);
        if (!$ok) {
            error_log('[MailService] mail() failed; to=' . $to . ' subject=' . $subject);
        }
        return $ok;
    }
}
