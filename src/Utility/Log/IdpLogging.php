<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log;

use Psr\Log\LoggerInterface;

/** Single DI facade for IDP log channels (coalescing container). */
final class IdpLogging
{
    public function __construct(
        private readonly LogChannelFactory $channels,
    ) {
    }

    public function app(): LoggerInterface
    {
        return $this->channels->forChannel(LogChannel::App);
    }

    public function security(): LoggerInterface
    {
        return $this->channels->forChannel(LogChannel::Security);
    }

    public function oauth(): LoggerInterface
    {
        return $this->channels->forChannel(LogChannel::Oauth);
    }

    public function pvh(): LoggerInterface
    {
        return $this->channels->forChannel(LogChannel::Pvh);
    }

    public function audit(): LoggerInterface
    {
        return $this->channels->forChannel(LogChannel::Audit);
    }

    public function forChannel(LogChannel $channel): LoggerInterface
    {
        return $this->channels->forChannel($channel);
    }
}
