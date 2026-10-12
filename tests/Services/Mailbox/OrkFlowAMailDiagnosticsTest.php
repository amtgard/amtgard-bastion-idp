<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services\Mailbox;

use Amtgard\IdP\Services\Mailbox\OrkFlowAMailDiagnostics;
use PHPUnit\Framework\TestCase;

final class OrkFlowAMailDiagnosticsTest extends TestCase
{
    private ?string $previousDebug = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDebug = array_key_exists('APP_DEBUG', $_ENV) ? (string) $_ENV['APP_DEBUG'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->previousDebug === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $this->previousDebug;
        }
        parent::tearDown();
    }

    public function testBlockReasonDetectsMissingPlayerAndEmptyEmail(): void
    {
        $this->assertSame('player_missing', OrkFlowAMailDiagnostics::blockReason(null, ''));
        $this->assertSame('email_empty', OrkFlowAMailDiagnostics::blockReason(['Email' => '  '], ''));
        $this->assertSame('email_invalid', OrkFlowAMailDiagnostics::blockReason(['Email' => 'not-an-email'], 'not-an-email'));
        $this->assertSame('suspended', OrkFlowAMailDiagnostics::blockReason(['Email' => 'a@b.com', 'Suspended' => 1], 'a@b.com'));
        $this->assertSame('penalty_box', OrkFlowAMailDiagnostics::blockReason(['Email' => 'a@b.com', 'PenaltyBox' => 1], 'a@b.com'));
        $this->assertNull(OrkFlowAMailDiagnostics::blockReason(['Email' => 'A@B.com', 'Suspended' => 0], 'a@b.com'));
    }

    public function testBuildLogContextHashesEmailAndOmitsRawWhenNotDebug(): void
    {
        $_ENV['APP_DEBUG'] = 'false';
        $context = OrkFlowAMailDiagnostics::buildLogContext(
            34,
            'OrkUser',
            [
                'mundaneId' => 4,
                'username' => 'OrkUser',
                'persona' => 'Persona',
                'parkName' => 'Park',
                'kingdomName' => 'Kingdom',
            ],
            ['Email' => 'Player@Example.com', 'MundaneId' => 4, 'Suspended' => 0],
            'player@example.com',
        );

        $this->assertSame(4, $context['mundane_id']);
        $this->assertTrue($context['will_send']);
        $this->assertNull($context['block_reason']);
        $this->assertSame(hash('sha256', 'player@example.com'), $context['ork_email_fields']['sent_to_hash']);
        $this->assertSame('pl***@example.com', $context['ork_email_fields']['ork_email_redacted']);
        $this->assertArrayNotHasKey('ork_email', $context['ork_email_fields']);
    }

    public function testProfileErrorSeparatesLookupFailureFromMissingMailbox(): void
    {
        $this->assertSame(
            'ork_mailbox_lookup_failed',
            OrkFlowAMailDiagnostics::profileErrorForMailSkip('no_mailbox', null, 'player_missing'),
        );
        $this->assertSame(
            'ork_mailbox_lookup_failed',
            OrkFlowAMailDiagnostics::profileErrorForMailSkip('no_mailbox', ['ShowEmail' => 1], 'email_empty'),
        );
        $this->assertSame(
            'ork_mailbox_not_set',
            OrkFlowAMailDiagnostics::profileErrorForMailSkip('no_mailbox', ['ShowEmail' => 0], 'email_empty'),
        );
    }

    public function testBuildLogContextIncludesRawEmailWhenDebug(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        $context = OrkFlowAMailDiagnostics::buildLogContext(
            34,
            'OrkUser',
            [
                'mundaneId' => 4,
                'username' => 'OrkUser',
                'persona' => '',
                'parkName' => '',
                'kingdomName' => '',
            ],
            ['Email' => 'player@example.com'],
            'player@example.com',
        );

        $this->assertSame('player@example.com', $context['ork_email_fields']['ork_email']);
    }
}
