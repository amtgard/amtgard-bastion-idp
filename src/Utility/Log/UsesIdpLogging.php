<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log;

use Psr\Log\LoggerInterface;

/** Channel accessors for AARO repositories wired outside PHP-DI. */
trait UsesIdpLogging
{
    protected function logApp(): LoggerInterface
    {
        return IdpLoggingRuntime::app();
    }

    protected function logOauth(): LoggerInterface
    {
        return IdpLoggingRuntime::oauth();
    }

    protected function logAudit(): LoggerInterface
    {
        return IdpLoggingRuntime::audit();
    }

    protected function logPvh(): LoggerInterface
    {
        return IdpLoggingRuntime::pvh();
    }

    protected function tokenIdPrefix(string $tokenId): string
    {
        return substr($tokenId, 0, 8);
    }
}
