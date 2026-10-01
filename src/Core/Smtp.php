<?php
declare(strict_types=1);

namespace Saqf\Core;

use RuntimeException;

/**
 * Dependency-free SMTP client (RFC 5321): implicit TLS (465) or STARTTLS (587), AUTH PLAIN/LOGIN,
 * UTF-8 plain-text messages (base64 body, RFC 2047 headers). Works with Microsoft 365
 * (smtp.office365.com:587), Google Workspace and on-premise relays. Certificates are verified.
 */
final class Smtp
{
    /** @var resource|null */
    private $socket = null;
    private array $extensions = [];

    public function __construct(
        private string $host,
        private int $port = 587,
        private string $encryption = 'tls',
        private string $username = '',
        private string $password = '',
        private int $timeout = 20
    ) {
    }

    public function send(string $fromEmail, string $fromName, string $toEmail, ?string $toName, string $subject, string $body): void
    {
        foreach ([$fromEmail, $toEmail] as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid e-mail address: ' . $address);
            }
        }
        try {
            $this->connect();
            $this->command('MAIL FROM:<' . $fromEmail . '>', [250]);
            $this->command('RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command('DATA', [354]);
            $this->command($this->message($fromEmail, $fromName, $toEmail, $toName, $subject, $body) . "\r\n.", [250]);
            $this->command('QUIT', [221]);
        } finally {
            if ($this->socket) {
                fclose($this->socket);
                $this->socket = null;
            }
        }
    }

    private function connect(): void
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host]]);
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $socket = @stream_socket_client("$scheme://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            throw new RuntimeException("Cannot connect to mail server {$this->host}:{$this->port} ($errstr)");
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
        $this->expect([220]);
        $this->ehlo();
        if ($this->encryption === 'tls') {
            if (!isset($this->extensions['STARTTLS'])) {
                throw new RuntimeException('The mail server does not offer STARTTLS; set SAQF_MAIL_ENCRYPTION=ssl or none.');
            }
            $this->command('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('TLS negotiation with the mail server failed (certificate or protocol).');
            }
            $this->ehlo();
        }
        if ($this->username !== '') {
            $auth = strtoupper($this->extensions['AUTH'] ?? '');
            if (str_contains($auth, 'PLAIN')) {
                $this->command('AUTH PLAIN ' . base64_encode("\0{$this->username}\0{$this->password}"), [235], true);
            } else {
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode($this->username), [334], true);
                $this->command(base64_encode($this->password), [235], true);
            }
        }
    }

    private function ehlo(): void
    {
        $lines = $this->command('EHLO ' . (preg_replace('/[^A-Za-z0-9.\-]/', '', (string) gethostname()) ?: 'saqf.local'), [250]);
        $this->extensions = [];
        foreach (array_slice($lines, 1) as $line) {
            $ext = strtoupper(trim(substr($line, 4)));
            [$name, $args] = array_pad(explode(' ', $ext, 2), 2, '');
            $this->extensions[$name] = $args;
        }
    }

    /** @return list<string> reply lines */
    private function command(string $line, array $ok, bool $secret = false): array
    {
        fwrite($this->socket, $line . "\r\n");
        return $this->expect($ok, $secret ? '[credentials]' : strtok($line, "\r\n"));
    }

    private function expect(array $ok, string $after = 'connect'): array
    {
        $lines = [];
        while (($line = fgets($this->socket, 1024)) !== false) {
            $lines[] = rtrim($line, "\r\n");
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = $lines ? (int) substr(end($lines), 0, 3) : 0;
        if (!in_array($code, $ok, true)) {
            $last = $lines ? end($lines) : 'no reply (timeout)';
            throw new RuntimeException('Mail server rejected ' . mb_substr($after, 0, 40) . ': ' . mb_substr($last, 0, 200));
        }
        return $lines;
    }

    private function message(string $fromEmail, string $fromName, string $toEmail, ?string $toName, string $subject, string $body): string
    {
        $domain = substr((string) strrchr($fromEmail, '@'), 1) ?: 'saqf.local';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . self::address($fromEmail, $fromName),
            'To: ' . self::address($toEmail, $toName),
            'Subject: ' . self::encode($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n"));
    }

    private static function address(string $email, ?string $name): string
    {
        if (!$name) {
            return '<' . $email . '>';
        }
        $encoded = self::encode($name);
        if ($encoded === trim(str_replace(["\r", "\n"], ' ', $name)) && preg_match('/[()<>@,;:\\\\".\[\]]/', $encoded)) {
            $encoded = '"' . addcslashes($encoded, '"\\') . '"';
        }
        return $encoded . ' <' . $email . '>';
    }

    /** RFC 2047 encoded word; CR/LF removed so header injection is impossible. */
    private static function encode(string $value): string
    {
        $value = trim(str_replace(["\r", "\n"], ' ', $value));
        return preg_match('/^[\x20-\x7E]*$/', $value) && !str_contains($value, '=?') ? $value : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
