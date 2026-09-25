<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mailbox;

interface OutboundMail
{
    public function send(string $to, string $subject, string $text): void;
}
