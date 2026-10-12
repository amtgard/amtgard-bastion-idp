<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Api\ApiController;
use Amtgard\IdP\Controllers\Resource\ClientIamRequestInterpreter;
use Amtgard\IdP\Controllers\Resource\ClientPolicyClaimsController;
use Amtgard\IdP\Controllers\Resource\ResourcesController;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Server\Entities\Repository\Client;
use Amtgard\IdP\Services\ClientIamPolicyService;
use Amtgard\IdP\Services\ResourcesUserinfoService;
use Amtgard\IdP\Tests\Support\CapturedIdpLogging;
use Amtgard\IdP\Utility\Client\ClientResourcesRequestResolver;
use Amtgard\IdP\Utility\Security\CurrentUserResolverInterface;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

final class M7ResourceApisLoggingTest extends TestCase
{
    public function testGetJwtLogsUnauthorizedOnAppChannel(): void
    {
        $capture = CapturedIdpLogging::create();
        $resolver = $this->createMock(CurrentUserResolverInterface::class);
        $resolver->method('resolve')->willReturn(null);

        $controller = $this->minimalResourcesController($capture, $resolver);

        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withStatus')->with(401)->willReturnSelf();

        $controller->getJwt($request, $response);

        $record = $this->findByMessage($capture->handler('app'), 'resources request unauthorized');
        $this->assertSame('resources.jwt', $record->context['route'] ?? null);
    }

    public function testLinkOrkProfileLogsInvalidBody(): void
    {
        $capture = CapturedIdpLogging::create();
        $resolver = $this->createMock(CurrentUserResolverInterface::class);

        $controller = $this->minimalResourcesController($capture, $resolver);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['idp_user_id' => '']);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withStatus')->willReturnSelf();
        $stream->method('write');

        $controller->linkOrkProfile($request, $response);

        $record = $this->findByMessage($capture->handler('app'), 'link ork profile rejected');
        $this->assertSame('invalid_body', $record->context['reason'] ?? null);
    }

    public function testClientPolicyClaimInvalidClaimLogsOnAuditChannel(): void
    {
        $capture = CapturedIdpLogging::create();
        $client = new class extends Client {
            public function getIdentifier(): string
            {
                return 'app-client';
            }
        };

        $user = new class extends UserEntity {
            public function getId(): int
            {
                return 1;
            }

            public function getUserId(): string
            {
                return 'uuid-1';
            }
        };

        $users = $this->createMock(\Amtgard\IdP\Persistence\Client\Repositories\UserRepository::class);
        $users->method('findUserByUserId')->willReturn($user);

        $policyService = $this->createMock(ClientIamPolicyService::class);
        $policyService->method('addClaim')->willThrowException(new \InvalidArgumentException('bad claim'));

        $controller = new ClientPolicyClaimsController(
            $this->createMock(LoggerInterface::class),
            $capture->idpLogging,
            new ClientIamRequestInterpreter(
                new ClientResourcesRequestResolver(
                    $users,
                    $this->createMock(\Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository::class),
                ),
                $capture->idpLogging,
            ),
            $policyService,
        );

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'idp_user_id' => 'uuid-1',
            'provisos' => 'x',
            'resource' => 'y',
        ]);
        $request->method('getAttribute')->willReturn($client);

        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withStatus')->willReturnSelf();
        $stream->method('write');

        $controller->addPolicyClaim($request, $response);

        $record = $this->findByMessage($capture->handler('audit'), 'client iam policy claim rejected');
        $this->assertSame('invalid_claim', $record->context['error_code'] ?? null);
        $this->assertSame('app-client', $record->context['client_id'] ?? null);
    }

    public function testApiIsAuthorizedLogsDebugOnAppChannel(): void
    {
        $capture = CapturedIdpLogging::create();
        $controller = new ApiController($capture->idpLogging);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'policy' => '[]',
            'requirement' => 'Idp:0:0:0:0:IDP/EditClient',
        ]);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('withHeader')->willReturnSelf();
        $stream->method('write');

        $controller->isAuthorized($request, $response);

        $record = $this->findByMessage($capture->handler('app'), 'api is_authorized evaluated');
        $this->assertFalse($record->context['is_authorized'] ?? true);
    }

    private function minimalResourcesController(
        CapturedIdpLogging $capture,
        CurrentUserResolverInterface $resolver,
    ): ResourcesController {
        return new ResourcesController(
            $this->createMock(LoggerInterface::class),
            $capture->idpLogging,
            $this->createMock(TwigEnvironment::class),
            $this->createMock(\League\OAuth2\Server\Repositories\ClientRepositoryInterface::class),
            $this->createMock(\Amtgard\SetQueue\PubSubQueue::class),
            $this->createMock(\Amtgard\IdP\Utility\PubSubQueueHandle::class),
            $this->createMock(\Amtgard\ActiveRecordOrm\Repository\Database::class),
            $this->createMock(\Amtgard\IdP\Services\OrkService::class),
            $this->createMock(\Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository::class),
            $this->createMock(\Amtgard\IdP\Persistence\Client\Repositories\UserRepository::class),
            $this->createMock(\Amtgard\IdP\Persistence\Server\Repositories\UserClientAuthorizationRepository::class),
            $this->createMock(\Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository::class),
            $this->createMock(AmtgardIdpJwt::class),
            $this->createMock(\Amtgard\IdP\Utility\UserAuthority::class),
            $resolver,
            ResourcesUserinfoService::builder()
                ->orkProfileRepository($this->createMock(\Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository::class))
                ->idpLogging($capture->idpLogging)
                ->build(),
            $this->createMock(\Amtgard\IdP\Persistence\Server\Repositories\ClientAccessRepository::class),
            $this->createMock(\Amtgard\IdP\Services\MailboxChallengeService::class),
            $this->createMock(\Amtgard\IdP\Services\OrkLinkTokenService::class),
            $this->createMock(\Amtgard\IdP\Services\IdpEmailMigrationService::class),
            $this->createMock(\Amtgard\IdP\Services\Ork\OrkClaimSendNonceService::class),
        );
    }

    private function findByMessage(TestHandler $handler, string $message): LogRecord
    {
        foreach ($handler->getRecords() as $record) {
            if ($record->message === $message) {
                return $record;
            }
        }

        $this->fail('Expected log message not found: ' . $message);
    }
}
