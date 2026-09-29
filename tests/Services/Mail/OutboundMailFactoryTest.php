<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services\Mail;

use Amtgard\IdP\Services\Mail\LogOutboundMail;
use Amtgard\IdP\Services\Mail\MailCarrier;
use Amtgard\IdP\Services\Mail\OutboundMailFactory;
use Amtgard\IdP\Services\Mail\PhpOutboundMail;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class OutboundMailFactoryTest extends TestCase
{
    private ?string $previous;

    protected function setUp(): void
    {
        $this->previous = array_key_exists('MAIL_TRANSPORT', $_ENV) ? (string) $_ENV['MAIL_TRANSPORT'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->previous === null) {
            unset($_ENV['MAIL_TRANSPORT']);
        } else {
            $_ENV['MAIL_TRANSPORT'] = $this->previous;
        }
    }

    public function testUnsetTransportSelectsLog(): void
    {
        unset($_ENV['MAIL_TRANSPORT']);

        $this->assertSame(MailCarrier::Log, MailCarrier::fromEnv());
    }

    public function testBlankTransportSelectsLog(): void
    {
        $_ENV['MAIL_TRANSPORT'] = '  ';

        $this->assertSame(MailCarrier::Log, MailCarrier::fromEnv());
    }

    public function testFactoryBuildsTheNamedCarrier(): void
    {
        $factory = new OutboundMailFactory($this->createMock(LoggerInterface::class));

        $this->assertInstanceOf(LogOutboundMail::class, $factory->forCarrier(MailCarrier::Log));
        $this->assertInstanceOf(PhpOutboundMail::class, $factory->forCarrier(MailCarrier::Php));
    }

    public function testUnknownCarrierIsRejected(): void
    {
        $_ENV['MAIL_TRANSPORT'] = 'sendgrid';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sendgrid');
        MailCarrier::fromEnv();
    }
}
