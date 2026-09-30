<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services\Mail;

use Amtgard\IdP\Services\Mail\MailCarrierSettings;
use PHPUnit\Framework\TestCase;

final class MailCarrierSettingsTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $previous = [];

    protected function setUp(): void
    {
        foreach ($this->names() as $name) {
            $this->previous[$name] = array_key_exists($name, $_ENV) ? (string) $_ENV[$name] : null;
            unset($_ENV[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
    }

    public function testMissingValuesUseDefaults(): void
    {
        $settings = MailCarrierSettings::fromEnv();

        $this->assertSame('', $settings->sendGridApiKey);
        $this->assertSame('', $settings->sesHost);
        $this->assertSame('', $settings->sesPassword);
        $this->assertSame(587, $settings->sesPort);
    }

    public function testFromEnvReadsCarrierCredentials(): void
    {
        $_ENV['SENDGRID_API_KEY'] = ' sg-test ';
        $_ENV['SENDGRID_FROM_EMAIL'] = ' from@example.com ';
        $_ENV['AMAZON_SES_HOST'] = ' email-smtp.example.com ';
        $_ENV['AMAZON_SES_USERNAME'] = ' user ';
        $_ENV['AMAZON_SES_PASSWORD'] = ' keep spaces ';
        $_ENV['AMAZON_SES_FROM_EMAIL'] = ' ses@example.com ';
        $_ENV['AMAZON_SES_PORT'] = '2525';

        $settings = MailCarrierSettings::fromEnv();

        $this->assertSame('sg-test', $settings->sendGridApiKey);
        $this->assertSame('from@example.com', $settings->sendGridFromEmail);
        $this->assertSame('email-smtp.example.com', $settings->sesHost);
        $this->assertSame('user', $settings->sesUsername);
        $this->assertSame(' keep spaces ', $settings->sesPassword);
        $this->assertSame('ses@example.com', $settings->sesFromEmail);
        $this->assertSame(2525, $settings->sesPort);
    }

    /** @return list<string> */
    private function names(): array
    {
        return [
            'SENDGRID_API_KEY',
            'SENDGRID_FROM_EMAIL',
            'AMAZON_SES_HOST',
            'AMAZON_SES_USERNAME',
            'AMAZON_SES_PASSWORD',
            'AMAZON_SES_FROM_EMAIL',
            'AMAZON_SES_PORT',
        ];
    }
}
