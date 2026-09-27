<?php

declare(strict_types=1);

namespace SmartLock;

use RuntimeException;

/**
 * Минимален SMTP клиент (SSL на 465 или STARTTLS на 587, AUTH LOGIN), без външни зависимости.
 */
final class Mailer
{
    /** @var resource|null */
    private $socket;

    /**
     * @param  list<string>  $to
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $encryption,
        private readonly string $from,
        private readonly array $to,
    ) {}

    public static function fromEnv(): self
    {
        $to = array_values(array_filter(array_map('trim', explode(',', Env::get('MAIL_TO')))));
        if ($to === []) {
            throw new RuntimeException('Липсва MAIL_TO в .env');
        }

        return new self(
            host: Env::required('MAIL_HOST'),
            port: (int) Env::get('MAIL_PORT', '587'),
            username: Env::get('MAIL_USERNAME'),
            password: Env::get('MAIL_PASSWORD'),
            encryption: strtolower(Env::get('MAIL_ENCRYPTION', 'tls')),
            from: Env::get('MAIL_FROM', Env::get('MAIL_USERNAME')),
            to: $to,
        );
    }

    public function send(string $subject, string $body): void
    {
        $prefix = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client($prefix.$this->host.':'.$this->port, $errno, $error, 15);
        if ($socket === false) {
            throw new RuntimeException("SMTP: няма връзка с {$this->host}:{$this->port} ({$error})");
        }
        $this->socket = $socket;
        stream_set_timeout($socket, 15);

        try {
            $this->expect(220);
            $this->command('EHLO '.(gethostname() ?: 'localhost'), 250);

            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', 220);
                if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                    throw new RuntimeException('SMTP: STARTTLS неуспешен.');
                }
                $this->command('EHLO '.(gethostname() ?: 'localhost'), 250);
            }

            if ($this->username !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->username), 334);
                $this->command(base64_encode($this->password), 235, hideInErrors: true);
            }

            $this->command('MAIL FROM:<'.$this->from.'>', 250);
            foreach ($this->to as $recipient) {
                $this->command('RCPT TO:<'.$recipient.'>', [250, 251]);
            }
            $this->command('DATA', 354);
            $this->command(self::buildMessage($this->from, $this->to, $subject, $body)."\r\n.", 250);
            $this->command('QUIT', 221);
        } finally {
            fclose($socket);
            $this->socket = null;
        }
    }

    /**
     * @param  list<string>  $to
     */
    public static function buildMessage(string $from, array $to, string $subject, string $body): string
    {
        $headers = [
            'From: '.$from,
            'To: '.implode(', ', $to),
            'Subject: =?UTF-8?B?'.base64_encode($subject).'?=',
            'Date: '.date(DATE_RFC2822),
            'Message-ID: <'.bin2hex(random_bytes(12)).'@smart-lock>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        return implode("\r\n", $headers)."\r\n\r\n".rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
    }

    /**
     * @param  int|list<int>  $expected
     */
    private function command(string $line, int|array $expected, bool $hideInErrors = false): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('SMTP: няма връзка.');
        }
        fwrite($this->socket, $line."\r\n");
        $this->expect($expected, $hideInErrors ? '[скрито]' : strtok($line, "\r\n"));
    }

    /**
     * @param  int|list<int>  $expected
     */
    private function expect(int|array $expected, string $after = 'connect'): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('SMTP: няма връзка.');
        }

        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if (! in_array($code, (array) $expected, true)) {
            throw new RuntimeException('SMTP грешка след „'.$after.'“: '.trim($response));
        }
    }
}
