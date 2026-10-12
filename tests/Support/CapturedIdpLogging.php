<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Support;

use Amtgard\IdP\Utility\Log\IdpLogging;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/** IdpLogging mock with Monolog TestHandler per channel for log assertions. */
final class CapturedIdpLogging
{
    /** @param array<string, TestHandler> $handlers */
    private function __construct(
        public readonly IdpLogging $idpLogging,
        private readonly array $handlers,
    ) {
    }

    public static function create(): self
    {
        $factory = new class ('CapturedIdpLoggingFactory') extends TestCase {
            /** @return array{0: IdpLogging, 1: array<string, TestHandler>} */
            public function build(): array
            {
                $handlers = [];
                $idpLogging = $this->createMock(IdpLogging::class);

                foreach (['app', 'security', 'oauth', 'audit', 'pvh'] as $channel) {
                    $handler = new TestHandler();
                    $handlers[$channel] = $handler;
                    $logger = new Logger($channel);
                    $logger->pushHandler($handler);
                    $idpLogging->method($channel)->willReturn($logger);
                }

                return [$idpLogging, $handlers];
            }
        };

        [$idpLogging, $handlers] = $factory->build();

        return new self($idpLogging, $handlers);
    }

    public function handler(string $channel): TestHandler
    {
        return $this->handlers[$channel];
    }
}
