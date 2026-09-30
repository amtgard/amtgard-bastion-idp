<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mail;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

final class SendGridOutboundMail implements OutboundMail
{
    private const ENDPOINT = 'https://api.sendgrid.com/v3/mail/send';

    private ClientInterface $http;

    public function __construct(
        private LoggerInterface $logger,
        private string $apiKey,
        private string $fromEmail,
        ?ClientInterface $http = null,
    ) {
        if (trim($apiKey) === '' || trim($fromEmail) === '') {
            throw new \InvalidArgumentException('SENDGRID_API_KEY and SENDGRID_FROM_EMAIL are required');
        }
        $this->apiKey = trim($apiKey);
        $this->fromEmail = trim($fromEmail);
        $this->http = $http ?? new Client(['http_errors' => false, 'timeout' => 10]);
    }

    public function send(string $to, string $subject, string $text): void
    {
        $payload = [
            'personalizations' => [['to' => [['email' => $to]]]],
            'from' => ['email' => $this->fromEmail],
            'subject' => $subject,
            'content' => [['type' => 'text/plain', 'value' => $text]],
        ];

        try {
            $response = $this->http->request('POST', self::ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            $this->logFailure($to);
            throw new \RuntimeException('SendGrid mail send failed', 0, $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->logFailure($to, $status);
            throw new \RuntimeException('SendGrid mail send failed');
        }

        $this->logger->info('mail.sent', [
            'carrier' => MailCarrier::SendGrid->value,
            'sent_to_hash' => hash('sha256', $to),
            'subject' => $subject,
            'status' => $status,
        ]);
    }

    private function logFailure(string $to, ?int $status = null): void
    {
        $context = [
            'carrier' => MailCarrier::SendGrid->value,
            'sent_to_hash' => hash('sha256', $to),
        ];
        if ($status !== null) {
            $context['status'] = $status;
        }
        $this->logger->info('mail.failed', $context);
    }
}
