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
        $fromEmail = $from ?: (getenv('MAIL_FROM') ?: 'no-reply@local');
        $fromName = getenv('MAIL_FROM_NAME') ?: '';

        // Prefer SMTP if configured
        $host = getenv('SMTP_HOST') ?: '';
        $port = (int)(getenv('SMTP_PORT') ?: 0);
        $user = getenv('SMTP_USER') ?: '';
        $pass = getenv('SMTP_PASS') ?: '';
        $secure = strtolower((string)(getenv('SMTP_SECURE') ?: ''));

        if ($host !== '' && $port > 0) {
            $ok = $this->sendSmtp($host, $port, $user, $pass, $secure, $fromEmail, $fromName, $to, $subject, $body);
            if ($ok) { return true; }
            error_log('[MailService] SMTP send failed, falling back to mail()');
        }

        // Fallback using mail()
        $headers = 'From: ' . ($fromName !== '' ? ($fromName . ' <' . $fromEmail . '>') : $fromEmail) . "\r\n" .
                   "MIME-Version: 1.0\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n" .
                   "Content-Transfer-Encoding: 8bit\r\n";
        $ok = @mail($to, $subject, $body, $headers);
        if (!$ok) {
            error_log('[MailService] mail() failed; to=' . $to . ' subject=' . $subject);
        }
        return $ok;
    }

    private function sendSmtp(
        string $host,
        int $port,
        string $user,
        string $pass,
        string $secure,
        string $fromEmail,
        string $fromName,
        string $to,
        string $subject,
        string $body
    ): bool {
        $transport = '';
        $useStartTls = false;
        if ($secure === 'ssl' || $secure === 'smtps') {
            $transport = 'ssl://';
        } elseif ($secure === 'tls') {
            $useStartTls = true; // connect plain then STARTTLS
        }

        $address = $transport . $host . ':' . $port;
        $errno = 0; $errstr = '';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);
        $fp = @stream_socket_client($address, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            error_log('[MailService][SMTP] connect failed: ' . $errno . ' ' . $errstr);
            return false;
        }
        stream_set_timeout($fp, 15);

        $read = function() use ($fp): string {
            $data = '';
            while (!feof($fp)) {
                $line = fgets($fp, 515);
                if ($line === false) { break; }
                $data .= $line;
                // Multi-line replies have a hyphen after the status code, last line uses a space
                if (preg_match('/^\d{3} /', $line)) { break; }
            }
            return $data;
        };
        $expect = function(string $resp, string $codes) use (&$fp): bool {
            $code = substr($resp, 0, 3);
            return strpos($codes, $code) !== false;
        };
        $cmd = function(string $c) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            return $read();
        };

        $greet = $read();
        if (!preg_match('/^220\b/', $greet)) {
            error_log('[MailService][SMTP] bad greeting: ' . trim($greet));
            fclose($fp);
            return false;
        }

        $ehlo = $cmd('EHLO ' . gethostname());
        if (!preg_match('/^250\b/', $ehlo)) {
            // Try HELO
            $helo = $cmd('HELO ' . gethostname());
            if (!preg_match('/^250\b/', $helo)) {
                error_log('[MailService][SMTP] EHLO/HELO failed: ' . trim($ehlo));
                fclose($fp); return false;
            }
        }

        if ($useStartTls) {
            $tlsResp = $cmd('STARTTLS');
            if (!preg_match('/^220\b/', $tlsResp)) {
                error_log('[MailService][SMTP] STARTTLS failed: ' . trim($tlsResp));
                fclose($fp); return false;
            }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log('[MailService][SMTP] TLS negotiation failed');
                fclose($fp); return false;
            }
            // Re-issue EHLO after STARTTLS
            $ehlo = $cmd('EHLO ' . gethostname());
            if (!preg_match('/^250\b/', $ehlo)) {
                error_log('[MailService][SMTP] EHLO after STARTTLS failed: ' . trim($ehlo));
                fclose($fp); return false;
            }
        }

        if ($user !== '' && $pass !== '') {
            $auth = $cmd('AUTH LOGIN');
            if (!preg_match('/^334\b/', $auth)) {
                error_log('[MailService][SMTP] AUTH LOGIN not accepted: ' . trim($auth));
                fclose($fp); return false;
            }
            $uResp = $cmd(base64_encode($user));
            if (!preg_match('/^334\b/', $uResp)) {
                error_log('[MailService][SMTP] username rejected: ' . trim($uResp));
                fclose($fp); return false;
            }
            $pResp = $cmd(base64_encode($pass));
            if (!preg_match('/^235\b/', $pResp)) {
                error_log('[MailService][SMTP] password rejected: ' . trim($pResp));
                fclose($fp); return false;
            }
        }

        $fromHeader = ($fromName !== '' ? $fromName . ' <' . $fromEmail . '>' : $fromEmail);
        $mh = $cmd('MAIL FROM: <' . $fromEmail . '>');
        if (!preg_match('/^250\b/', $mh)) { error_log('[MailService][SMTP] MAIL FROM failed: ' . trim($mh)); fclose($fp); return false; }
        $rh = $cmd('RCPT TO: <' . $to . '>');
        if (!preg_match('/^250\b/', $rh)) { error_log('[MailService][SMTP] RCPT TO failed: ' . trim($rh)); fclose($fp); return false; }
        $dh = $cmd('DATA');
        if (!preg_match('/^354\b/', $dh)) { error_log('[MailService][SMTP] DATA not accepted: ' . trim($dh)); fclose($fp); return false; }

        $headers = '';
        $headers .= 'From: ' . $fromHeader . "\r\n";
        $headers .= 'To: <' . $to . '>' . "\r\n";
        $headers .= 'Subject: ' . $subject . "\r\n";
        $headers .= 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        $headers .= 'Content-Transfer-Encoding: 8bit' . "\r\n";
        $msg = $headers . "\r\n" . $body . "\r\n.";
        fwrite($fp, $msg . "\r\n");
        $dr = $read();
        if (!preg_match('/^250\b/', $dr)) { error_log('[MailService][SMTP] message not accepted: ' . trim($dr)); fclose($fp); return false; }
        $quit = $cmd('QUIT');
        fclose($fp);
        return true;
    }
}
