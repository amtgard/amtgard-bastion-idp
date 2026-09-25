<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Support;

use Amtgard\IdP\Services\Mailbox\OutboundMail;

final class RecordingOutboundMail implements OutboundMail
{
    /** @var list<array{to: string, subject: string, text: string}> */
    public array $messages = [];

    public function send(string $to, string $subject, string $text): void
    {
        $this->messages[] = [
            'to' => $to,
            'subject' => $subject,
            'text' => $text,
        ];
    }

    public function lastCode(): string
    {
        $last = $this->messages[array_key_last($this->messages)] ?? ['text' => ''];
        preg_match('/\b(\d{6})\b/', $last['text'], $matches);

        return $matches[1] ?? '';
    }
}
