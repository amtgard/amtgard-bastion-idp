<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Live;

use Amtgard\IdP\Services\Mail\MailCarrierSettings;
use Amtgard\IdP\Services\Mail\SesOutboundMail;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Sends one message through Amazon SES SMTP using credentials from the project .env.
 * Run only via {@see composer ses:live} — excluded from {@see composer test}.
 */
final class SesOutboundMailLiveTest extends TestCase
{
    private const FROM_EMAIL = 'megiddo@amtgard.com';

    private const TO_EMAIL = 'en.gannim@gmail.com';

    public function testSendDeliversThroughAmazonSesSmtp(): void
    {
        $settings = MailCarrierSettings::fromEnv();
        if (!$this->sesConfigured($settings)) {
            $this->markTestSkipped(
                'Set AMAZON_SES_HOST, AMAZON_SES_USERNAME, and AMAZON_SES_PASSWORD in .env, then run: composer ses:live',
            );
        }

        $token = bin2hex(random_bytes(8));
        $mailer = new SesOutboundMail(
            $this->createStub(LoggerInterface::class),
            $settings->sesHost,
            $settings->sesUsername,
            $settings->sesPassword,
            self::FROM_EMAIL,
            $settings->sesPort,
        );

        $mailer->send(
            self::TO_EMAIL,
            'Amtgard IDP SES live test ' . gmdate('Y-m-d H:i:s') . ' UTC',
            "This is an opt-in smoke test (token {$token}).\nIf you received it, SES SMTP credentials in .env are working.\n",
        );

        $this->addToAssertionCount(1);
    }

    private function sesConfigured(MailCarrierSettings $settings): bool
    {
        return trim($settings->sesHost) !== ''
            && trim($settings->sesUsername) !== ''
            && $settings->sesPassword !== '';
    }
}
