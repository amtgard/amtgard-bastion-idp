<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services\Mail;

use Amtgard\IdP\Services\Mail\LogOutboundMail;
use Amtgard\IdP\Services\Mail\MailCarrier;
use Amtgard\IdP\Services\Mail\MailCarrierSettings;
use Amtgard\IdP\Services\Mail\OutboundMailFactory;
use Amtgard\IdP\Services\Mail\PhpOutboundMail;
use Amtgard\IdP\Services\Mail\SendGridOutboundMail;
use Amtgard\IdP\Services\Mail\SesOutboundMail;
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
        $factory = new OutboundMailFactory($this->createStub(LoggerInterface::class));

        $this->assertInstanceOf(LogOutboundMail::class, $factory->forCarrier(MailCarrier::Log));
        $this->assertInstanceOf(PhpOutboundMail::class, $factory->forCarrier(MailCarrier::Php));
    }

    public function testFactoryBuildsSendGridWhenCredentialsArePresent(): void
    {
        $_ENV['MAIL_TRANSPORT'] = 'SendGrid';
        $factory = new OutboundMailFactory(
            $this->createStub(LoggerInterface::class),
            new MailCarrierSettings('sg-secret-key', 'from@example.com'),
        );

        $this->assertSame(MailCarrier::SendGrid, MailCarrier::fromEnv());
        $this->assertInstanceOf(SendGridOutboundMail::class, $factory->forCarrier(MailCarrier::SendGrid));
    }

    public function testFactoryBuildsSesWhenCredentialsArePresent(): void
    {
        $_ENV['MAIL_TRANSPORT'] = 'ses';
        $factory = new OutboundMailFactory(
            $this->createStub(LoggerInterface::class),
            new MailCarrierSettings('', '', 'email-smtp.example.com', 'user', 'secret', 'from@example.com'),
        );

        $this->assertSame(MailCarrier::Ses, MailCarrier::fromEnv());
        $this->assertInstanceOf(SesOutboundMail::class, $factory->forCarrier(MailCarrier::Ses));
    }

    public function testFactoryRejectsSendGridWithoutCredentials(): void
    {
        $factory = new OutboundMailFactory($this->createStub(LoggerInterface::class));

        $this->expectException(\InvalidArgumentException::class);
        $factory->forCarrier(MailCarrier::SendGrid);
    }

    public function testUnknownCarrierIsRejected(): void
    {
        $_ENV['MAIL_TRANSPORT'] = 'mailchimp';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"mailchimp"');
        MailCarrier::fromEnv();
    }
}
