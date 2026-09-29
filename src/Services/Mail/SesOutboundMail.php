<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mail;

use Psr\Log\LoggerInterface;

final class SesOutboundMail implements OutboundMail
{
    public function __construct(
        private LoggerInterface $logger,
        private string $host,
        private string $username,
        private string $password,
        private string $fromEmail,
        private int $port = 587,
        private ?SmtpPipe $pipe = null,
    ) {
        if (trim($host) === '' || trim($username) === '' || $password === '' || trim($fromEmail) === '') {
            throw new \InvalidArgumentException(
                'AMAZON_SES_HOST, AMAZON_SES_USERNAME, AMAZON_SES_PASSWORD, and AMAZON_SES_FROM_EMAIL are required',
            );
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('AMAZON_SES_PORT is invalid');
        }
        $this->host = trim($host);
        $this->username = trim($username);
        $this->fromEmail = trim($fromEmail);
    }

    public function send(string $to, string $subject, string $text): void
    {
        if (strpbrk($to, "\r\n") !== false || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('SES recipient is invalid');
        }
        $subject = str_replace(["\r", "\n"], '', $subject);
        $pipe = $this->pipe ?? StreamSmtpPipe::connect($this->host, $this->port);
        try {
            $this->deliver($pipe, $to, $subject, $text);
        } catch (\RuntimeException $e) {
            $this->logFailure($to, $e->getCode() > 0 ? $e->getCode() : null);
            throw new \RuntimeException('SES mail send failed', $e->getCode(), $e);
        }

        $this->logger->info('mail.sent', [
            'carrier' => MailCarrier::Ses->value,
            'sent_to_hash' => hash('sha256', $to),
            'subject' => $subject,
        ]);
    }

    private function deliver(SmtpPipe $pipe, string $to, string $subject, string $text): void
    {
        $this->expect($pipe, 220);
        $pipe->write("EHLO localhost\r\n");
        $this->expect($pipe, 250);
        $pipe->write("STARTTLS\r\n");
        $this->expect($pipe, 220);
        $pipe->startTls();
        $pipe->write("EHLO localhost\r\n");
        $this->expect($pipe, 250);
        $pipe->write("AUTH LOGIN\r\n");
        $this->expect($pipe, 334);
        $pipe->write(base64_encode($this->username) . "\r\n");
        $this->expect($pipe, 334);
        $pipe->write(base64_encode($this->password) . "\r\n");
        $this->expect($pipe, 235);
        $pipe->write('MAIL FROM:<' . $this->fromEmail . ">\r\n");
        $this->expect($pipe, 250);
        $pipe->write('RCPT TO:<' . $to . ">\r\n");
        $this->expect($pipe, 250);
        $pipe->write("DATA\r\n");
        $this->expect($pipe, 354);
        $pipe->write($this->message($to, $subject, $text));
        $this->expect($pipe, 250);
        $pipe->write("QUIT\r\n");
        $this->expect($pipe, 221);
    }

    private function expect(SmtpPipe $pipe, int $code): void
    {
        $response = $pipe->readResponse();
        $status = (int) substr($response, 0, 3);
        if ($status !== $code) {
            throw new \RuntimeException('SES mail send failed', $status);
        }
    }

    private function message(string $to, string $subject, string $text): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $text);
        $body = str_replace("\n.", "\n..", $body);
        if (str_starts_with($body, '.')) {
            $body = '.' . $body;
        }
        $body = str_replace("\n", "\r\n", $body);
        $data = implode("\r\n", [
            'From: ' . $this->fromEmail,
            'To: ' . $to,
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $body,
        ]);

        return $data . "\r\n.\r\n";
    }

    private function logFailure(string $to, ?int $status): void
    {
        $context = [
            'carrier' => MailCarrier::Ses->value,
            'sent_to_hash' => hash('sha256', $to),
        ];
        if ($status !== null) {
            $context['status'] = $status;
        }
        $this->logger->info('mail.failed', $context);
    }
}
