<?php
/**
 * Minimal SMTP client (STARTTLS + AUTH LOGIN/PLAIN).
 *
 * Shared hosting usually has no Composer, so this keeps the app dependency-free.
 * If you prefer PHPMailer, upload its vendor/ directory and swap the body of
 * send_transactional_email() in lib/mailer.php.
 */

class SmtpException extends RuntimeException
{
}

final class SmtpClient
{
    private $fp = null;
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function send(string $toEmail, string $toName, string $subject, string $html, string $text): void
    {
        $this->connect();

        try {
            $this->expect($this->command('EHLO ' . $this->heloName()), [250]);

            $encryption = strtolower((string) ($this->cfg['encryption'] ?? ''));
            if ($encryption === 'tls') {
                $this->expect($this->command('STARTTLS'), [220]);
                $enabled = @stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($enabled !== true) {
                    throw new SmtpException('STARTTLS negotiation failed.');
                }
                $this->expect($this->command('EHLO ' . $this->heloName()), [250]);
            }

            $this->authenticate();

            $from = (string) $this->cfg['from_email'];
            $this->expect($this->command('MAIL FROM:<' . $from . '>'), [250, 251]);
            $this->expect($this->command('RCPT TO:<' . $toEmail . '>'), [250, 251]);
            $this->expect($this->command('DATA'), [354]);

            $this->write($this->dotStuff($this->buildMessage($toEmail, $toName, $subject, $html, $text)));
            $this->write(".\r\n");
            $this->expect($this->read(), [250]);

            try {
                $this->command('QUIT');
            } catch (SmtpException $ignored) {
                // The server often closes the connection before replying.
            }
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $host       = (string) $this->cfg['host'];
        $port       = (int) $this->cfg['port'];
        $encryption = strtolower((string) ($this->cfg['encryption'] ?? ''));
        $scheme     = $encryption === 'ssl' ? 'ssl://' : 'tcp://';

        if ($host === '') {
            throw new SmtpException('No SMTP host configured.');
        }

        $errno  = 0;
        $errstr = '';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'peer_name'        => $host,
                'SNI_enabled'      => true,
            ],
        ]);
        $fp     = @stream_socket_client(
            $scheme . $host . ':' . $port,
            $errno,
            $errstr,
            20,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!is_resource($fp)) {
            throw new SmtpException(sprintf(
                'Cannot connect to %s:%d (%s)',
                $host,
                $port,
                $errstr !== '' ? $errstr : 'error ' . $errno
            ));
        }

        $this->fp = $fp;
        stream_set_timeout($this->fp, 20);
        $this->expect($this->read(), [220]);
    }

    private function close(): void
    {
        if (is_resource($this->fp)) {
            @fclose($this->fp);
        }
        $this->fp = null;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function read(): array
    {
        $code = 0;
        $text = '';

        for ($i = 0; $i < 100; $i++) {
            $line = fgets($this->fp, 2048);
            if ($line === false) {
                $meta = stream_get_meta_data($this->fp);
                throw new SmtpException(
                    !empty($meta['timed_out'])
                        ? 'Timed out waiting for the mail server.'
                        : 'The mail server closed the connection.'
                );
            }

            $text .= $line;
            $trimmed = rtrim($line, "\r\n");

            if (preg_match('/^(\d{3})([ -])(.*)$/', $trimmed, $m)) {
                $code = (int) $m[1];
                if ($m[2] === ' ') {
                    break;
                }
            }
        }

        return [$code, trim($text)];
    }

    private function write(string $data): void
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $written = @fwrite($this->fp, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new SmtpException('Failed writing to the mail server.');
            }
            $offset += $written;
        }
    }

    /**
     * @return array{0:int,1:string}
     */
    private function command(string $line): array
    {
        $this->write($line . "\r\n");

        return $this->read();
    }

    /**
     * @param array{0:int,1:string} $response
     * @param int[]                 $codes
     */
    private function expect(array $response, array $codes): void
    {
        if (!in_array($response[0], $codes, true)) {
            throw new SmtpException('SMTP error ' . $response[0] . ': ' . $this->lastLine($response[1]));
        }
    }

    private function authenticate(): void
    {
        $user = (string) ($this->cfg['username'] ?? '');
        $pass = (string) ($this->cfg['password'] ?? '');

        if ($user === '') {
            return;
        }

        $response = $this->command('AUTH LOGIN');

        if ($response[0] !== 334) {
            $plain = base64_encode("\0" . $user . "\0" . $pass);
            $this->expect($this->command('AUTH PLAIN ' . $plain), [235]);

            return;
        }

        $this->expect($this->command(base64_encode($user)), [334]);
        $this->expect($this->command(base64_encode($pass)), [235]);
    }

    private function heloName(): string
    {
        $from   = (string) ($this->cfg['from_email'] ?? '');
        $domain = substr((string) strrchr($from, '@'), 1);

        return $domain !== '' ? $domain : 'localhost.localdomain';
    }

    private function buildMessage(string $toEmail, string $toName, string $subject, string $html, string $text): string
    {
        $fromEmail = (string) $this->cfg['from_email'];
        $fromName  = (string) ($this->cfg['from_name'] ?? 'PhotoOnMaps');
        $domain    = substr((string) strrchr($fromEmail, '@'), 1) ?: 'localhost';
        $boundary  = 'pom-' . bin2hex(random_bytes(12));

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->formatAddress($fromEmail, $fromName),
            'To: ' . $this->formatAddress($toEmail, $toName),
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Auto-Response-Suppress: All',
        ];

        $body = [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($text), 76, "\r\n")),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($html), 76, "\r\n")),
            '--' . $boundary . '--',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $body) . "\r\n";
    }

    private function formatAddress(string $email, string $name = ''): string
    {
        $email = trim(str_replace(["\r", "\n", '<', '>', ' '], '', $email));
        $name  = trim(str_replace(["\r", "\n", '"'], '', $name));

        if ($name === '') {
            return '<' . $email . '>';
        }

        return $this->encodeHeader($name) . ' <' . $email . '>';
    }

    private function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);

        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return $value;
    }

    private function dotStuff(string $message): string
    {
        return (string) preg_replace('/^\./m', '..', $message);
    }

    private function lastLine(string $text): string
    {
        $lines = preg_split('/\r?\n/', $text);

        return trim((string) end($lines));
    }
}
