<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Management\ManagementController;
use Amtgard\IdP\Controllers\VersionController;
use Amtgard\IdP\Services\OrkLinkTokenService;
use Amtgard\IdP\Tests\Support\CapturedIdpLogging;
use Amtgard\IdP\Utility\Client\ClientIamAdminInput;
use Amtgard\IdP\Utility\Http\OrkHttpClient;
use Amtgard\IdP\Utility\JsonResponseBody;
use Amtgard\IdP\Utility\Log\IdpLoggingRuntime;
use Amtgard\IdP\Utility\PvhGate;
use Amtgard\IdP\Utility\Redis\PubSubRedisConfig;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Slim\Psr7\Factory\ResponseFactory;

final class M9HardeningLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        IdpLoggingRuntime::reset();
    }

    protected function tearDown(): void
    {
        IdpLoggingRuntime::reset();
        unset($_ENV['REDIS_PUBSUB_HOST']);
    }

    public function testManagementCleanTokensLogsSuccessOnAuditChannel(): void
    {
        $capture = CapturedIdpLogging::create();
        $audit = $capture->handler('audit');

        $controller = new ManagementController(
            $capture->idpLogging,
            $this->createMock(\Twig\Environment::class),
            $this->createMock(\Amtgard\IdP\Persistence\Server\Repositories\AccessTokenRepository::class),
            $this->createMock(\Amtgard\IdP\Persistence\Server\Repositories\RefreshTokenRepository::class),
            $this->createMock(\Amtgard\IdP\Persistence\Server\Repositories\AuthCodeRepository::class),
            $this->createMock(\Amtgard\IdP\Persistence\Server\Repositories\ClientRepository::class),
            $this->createMock(OrkLinkTokenService::class),
            $this->createMock(\Amtgard\IdP\Persistence\Server\Repositories\ClientAccessRepository::class),
            $this->createMock(\Amtgard\IdP\Persistence\Client\Repositories\UserRepository::class),
        );

        $response = (new ResponseFactory())->createResponse();
        $controller->cleanTokens(
            $this->createMock(ServerRequestInterface::class),
            $response,
        );

        $finished = $this->findOn($audit, 'management token cleanup finished');
        $this->assertTrue($finished->context['tokens_cleaned'] ?? false);
    }

    public function testOrkHttpClientLogsEmergencyWhenMisconfigured(): void
    {
        $capture = CapturedIdpLogging::create();
        IdpLoggingRuntime::bind($capture->idpLogging);
        unset($_ENV['ORK_API_USER_AGENT'], $_ENV['ORK_API_REFERER']);

        try {
            OrkHttpClient::createFromEnvironment();
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        }

        $record = $this->findOn($capture->handler('app'), 'ork api client misconfigured');
        $this->assertSame('ork_api_config', $record->context['reason'] ?? null);
    }

    public function testClientIamAdminInputLogsNormalizeDebug(): void
    {
        $capture = CapturedIdpLogging::create();
        IdpLoggingRuntime::bind($capture->idpLogging);

        ClientIamAdminInput::fromFormData([
            'iam_service' => 'Demo',
            'iam_service_format' => '["slot"]',
        ]);

        $record = $this->findOn($capture->handler('app'), 'client iam admin fields normalize');
        $this->assertTrue($record->context['has_iam_service'] ?? false);
        $this->assertTrue($record->context['has_format'] ?? false);
    }

    public function testJsonResponseBodyLogsTwoHundredOnAppChannel(): void
    {
        $capture = CapturedIdpLogging::create();
        IdpLoggingRuntime::bind($capture->idpLogging);

        $response = JsonResponseBody::write((new ResponseFactory())->createResponse(), ['ok' => true], 200);

        $this->assertSame(200, $response->getStatusCode());
        $record = $this->findOn($capture->handler('app'), 'json response written');
        $this->assertSame(200, $record->context['http_status'] ?? null);
    }

    public function testVersionControllerLogsDebugHit(): void
    {
        $capture = CapturedIdpLogging::create();
        $controller = new VersionController($capture->idpLogging);
        $response = (new ResponseFactory())->createResponse();
        $stream = $this->createMock(StreamInterface::class);
        $response = $response->withBody($stream);

        $controller->index($this->createMock(ServerRequestInterface::class), $response);

        $record = $this->findOn($capture->handler('app'), 'version endpoint hit');
        $this->assertSame('version', $record->context['route'] ?? null);
    }

    public function testPvhGateMissSeedLogsOnPvhChannel(): void
    {
        $capture = CapturedIdpLogging::create();
        IdpLoggingRuntime::bind($capture->idpLogging);

        PvhGate::missSeedRecord('user-1', 'client-1', 'a@b.com', str_repeat('a', 64), null);

        $record = $this->findOn($capture->handler('pvh'), 'pvh gate miss seed record built');
        $this->assertSame('user-1', $record->context['user_uuid'] ?? null);
        $this->assertSame('client-1', $record->context['aud'] ?? null);
    }

    public function testPubSubRedisConfigLogsHostnameFallbackNotice(): void
    {
        $_ENV['REDIS_PUBSUB_HOST'] = 'unreachable-redis-host.invalid';
        $handler = new TestHandler();
        $logger = new \Monolog\Logger('test');
        $logger->pushHandler($handler);

        PubSubRedisConfig::dataStructureConfig($logger)->getConfig();

        $this->assertTrue($handler->hasRecordThatContains('redis pubsub hostname fallback', \Monolog\Level::Notice));
    }

    private function findOn(TestHandler $handler, string $message): LogRecord
    {
        foreach ($handler->getRecords() as $record) {
            if ($record->message === $message) {
                return $record;
            }
        }

        $this->fail('Missing log record: ' . $message);
    }
}
