<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services\Mail;

interface SmtpPipe
{
    public function readResponse(): string;

    public function write(string $data): void;

    public function startTls(): void;
}
