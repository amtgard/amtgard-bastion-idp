<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mail;

final class StreamSmtpPipe implements SmtpPipe
{
    /** @param resource $stream */
    public function __construct(private $stream, private ?\Closure $enableCrypto = null)
    {
    }

    public static function connect(string $host, int $port, int $timeoutSeconds = 15): self
    {
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
        );
        if ($socket === false) {
            throw new \RuntimeException('SES mail send failed');
        }
        stream_set_timeout($socket, $timeoutSeconds);

        return new self($socket);
    }

    public function readResponse(): string
    {
        $lines = [];
        do {
            $line = fgets($this->stream);
            if ($line === false) {
                throw new \RuntimeException('SES mail send failed');
            }
            $line = rtrim($line, "\r\n");
            $lines[] = $line;
        } while (isset($line[3]) && $line[3] === '-');

        return implode("\n", $lines);
    }

    public function write(string $data): void
    {
        $written = @fwrite($this->stream, $data);
        if ($written !== strlen($data)) {
            throw new \RuntimeException('SES mail send failed');
        }
    }

    public function startTls(): void
    {
        $enable = $this->enableCrypto ?? static function ($stream): bool {
            return @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) === true;
        };
        if ($enable($this->stream) !== true) {
            throw new \RuntimeException('SES mail send failed');
        }
    }
}
