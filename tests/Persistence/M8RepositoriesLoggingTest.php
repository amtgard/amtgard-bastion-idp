<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Persistence;

use Amtgard\ActiveRecordOrm\Entity\EntityMapper;
use Amtgard\ActiveRecordOrm\Interface\EntityInterface;
use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Common\Repositories\ClaimOrnValidator;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicy;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicyClaimRepository;
use Amtgard\IdP\Persistence\Common\Repositories\UserPolicyClaimWriter;
use Amtgard\IdP\Persistence\Server\Repositories\ScopeRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository;
use Amtgard\IdP\Tests\Support\CapturedIdpLogging;
use Amtgard\IdP\Utility\IamServiceFormatParser;
use Amtgard\IdP\Utility\Log\IdpLoggingRuntime;
use Amtgard\IdP\Utility\Pvh;
use Amtgard\IdP\Utility\PvhCacheRecord;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class M8RepositoriesLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        IdpLoggingRuntime::reset();
    }

    protected function tearDown(): void
    {
        IdpLoggingRuntime::reset();
    }

    public function testUserPolicyEmptyFallbackLogsError(): void
    {
        $capture = CapturedIdpLogging::create();
        IdpLoggingRuntime::bind($capture->idpLogging);

        $claims = $this->createMock(UserPolicyClaimRepository::class);
        $claims->method('getUserPolicy')->willThrowException(new \RuntimeException('db down'));

        $user = $this->createMock(EntityInterface::class);
        $user->id = 42;

        $policy = new UserPolicy($claims, $capture->idpLogging);
        $result = $policy->getUserPolicy($user);

        $this->assertSame('[]', $result->toJson());
        $record = $this->findOn($capture->handler('app'), 'user policy empty fallback');
        $this->assertSame(42, $record->context['user_id'] ?? null);
        $this->assertSame('empty_policy', $record->context['fallback'] ?? null);
    }

    public function testClaimOrnValidatorLogsParseFailure(): void
    {
        $capture = CapturedIdpLogging::create();
        $validator = new ClaimOrnValidator($capture->idpLogging);

        try {
            $validator->assertClaimParses('NotARegisteredService', '0::::', 'X/Y');
        } catch (\InvalidArgumentException) {
        }

        $record = $this->findOn($capture->handler('app'), 'policy claim orn parse failed');
        $this->assertSame('orn', $record->context['field'] ?? null);
    }

    public function testPolicyClaimWriterLogsIdempotentAdd(): void
    {
        $capture = CapturedIdpLogging::create();
        $mapper = $this->createMock(EntityMapper::class);
        $mapper->method('find')->willReturn(1);

        $ornValidator = $this->createMock(ClaimOrnValidator::class);
        $writer = new UserPolicyClaimWriter(
            $mapper,
            $ornValidator,
            $capture->idpLogging,
        );

        $writer->addClaim(1, ServiceCatalog::Idp->value, '0::::', 'IDP/EditClient', 1, null);

        $record = $this->findOn($capture->handler('audit'), 'policy claim add idempotent noop');
        $this->assertSame(1, $record->context['user_id'] ?? null);
    }

    public function testScopeRepositoryUnknownScopeLogsDebug(): void
    {
        $capture = CapturedIdpLogging::create();
        IdpLoggingRuntime::bind($capture->idpLogging);

        $repo = $this->getMockBuilder(ScopeRepository::class)
            ->onlyMethods(['fetchBy'])
            ->getMock();
        $repo->method('fetchBy')->willReturn(null);

        $repo->getScopeEntityByIdentifier('unknown-scope');

        $record = $this->findOn($capture->handler('oauth'), 'oauth unknown scope');
        $this->assertSame('unknown-scope', $record->context['scope'] ?? null);
    }

    public function testUserClientAuthorizeIdempotentLogsDebug(): void
    {
        $capture = CapturedIdpLogging::create();
        IdpLoggingRuntime::bind($capture->idpLogging);

        $repo = $this->getMockBuilder(UserClientAuthorizationRepository::class)
            ->onlyMethods(['hasAuthorization'])
            ->getMock();
        $repo->method('hasAuthorization')->willReturn(true);

        $repo->authorize('user-uuid', 3);

        $record = $this->findOn($capture->handler('oauth'), 'user client authorize idempotent noop');
        $this->assertSame('user-uuid', $record->context['user_identifier'] ?? null);
    }

    public function testIamServiceFormatParserDefaultLogsDebug(): void
    {
        $capture = CapturedIdpLogging::create();
        IdpLoggingRuntime::bind($capture->idpLogging);

        IamServiceFormatParser::parse(null);

        $record = $this->findOn($capture->handler('app'), 'iam service format default');
        $this->assertSame('default_format', $record->context['reason'] ?? null);
    }

    public function testPvhInvalidHexLogsDebug(): void
    {
        $capture = CapturedIdpLogging::create();
        IdpLoggingRuntime::bind($capture->idpLogging);

        try {
            Pvh::policyHashFromHex('not-hex');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }

        $record = $this->findOn($capture->handler('pvh'), 'pvh policy hash hex invalid');
        $this->assertSame('policy_hash_hex', $record->context['rule'] ?? null);
    }

    public function testPvhCacheRecordCorruptLogsWarning(): void
    {
        $capture = CapturedIdpLogging::create();
        IdpLoggingRuntime::bind($capture->idpLogging);

        $this->assertNull(PvhCacheRecord::fromJson('{'));

        $record = $this->findOn($capture->handler('pvh'), 'pvh cache record corrupt');
        $this->assertSame('corrupt_pvh_record', $record->context['reason'] ?? null);
    }

    public function testUserLoginRepositoryOrElseFalseLogsDebug(): void
    {
        $capture = CapturedIdpLogging::create();
        IdpLoggingRuntime::bind($capture->idpLogging);

        $repo = $this->getMockBuilder(UserLoginRepository::class)
            ->onlyMethods(['findLoginById'])
            ->getMock();
        $repo->method('findLoginById')->willReturn(null);

        $this->assertFalse($repo->loginBelongsToUser(9, 1));

        $record = $this->findOn($capture->handler('app'), 'user login ownership check failed');
        $this->assertSame(9, $record->context['login_id'] ?? null);
    }

    private function findOn(TestHandler $handler, string $message): LogRecord
    {
        foreach ($handler->getRecords() as $record) {
            if ($record->message === $message) {
                return $record;
            }
        }

        $this->fail('Expected log message not found: ' . $message);
    }
}
