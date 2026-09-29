<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Resource\OrkAccountUnlinkController;
use Amtgard\IdP\Persistence\Client\Entities\UserEntity;
use Amtgard\IdP\Persistence\Client\Entities\UserOrkProfileEntity;
use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Utility\Security\CurrentUserResolverInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

final class UnlinkOrkUserEntity extends UserEntity
{
    public function __construct(private int $numericId)
    {
    }

    public function getId(): int
    {
        return $this->numericId;
    }
}

final class UnlinkOrkProfileEntity extends UserOrkProfileEntity
{
    public function getMundaneId(): int
    {
        return 4;
    }
}

final class OrkAccountUnlinkControllerTest extends TestCase
{
    private LoggerInterface $logger;
    private CurrentUserResolverInterface $currentUserResolver;
    private UserRepository $userRepository;
    private UserOrkProfileRepository $orkProfileRepository;
    private ServerRequestInterface $request;
    private ResponseInterface $response;
    private StreamInterface $stream;
    private OrkAccountUnlinkController $controller;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->currentUserResolver = $this->createMock(CurrentUserResolverInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->orkProfileRepository = $this->createMock(UserOrkProfileRepository::class);
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->stream = $this->createMock(StreamInterface::class);
        $this->response->method('getBody')->willReturn($this->stream);
        $this->response->method('withHeader')->willReturnSelf();
        $this->response->method('withStatus')->willReturnSelf();

        $this->controller = new OrkAccountUnlinkController(
            $this->logger,
            $this->currentUserResolver,
            $this->userRepository,
            $this->orkProfileRepository,
        );
    }

    public function testUnlinkFromProfileRedirectsToLoginWhenSignedOut(): void
    {
        $this->logger->expects($this->never())->method('info');
        $this->currentUserResolver->method('resolve')->willReturn(null);
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/auth/login')
            ->willReturnSelf();
        $this->response->expects($this->once())->method('withStatus')->with(302)->willReturnSelf();

        $this->assertSame($this->response, $this->controller->unlinkFromProfile($this->request, $this->response));
    }

    public function testUnlinkFromProfileRedirectsWhenAProfileIsRemoved(): void
    {
        $this->logger->expects($this->any())->method('info');
        $this->currentUserResolver->method('resolve')->willReturn(new UnlinkOrkUserEntity(33));
        $this->orkProfileRepository->method('findByUserId')->with(33)->willReturn(new UnlinkOrkProfileEntity());
        $this->orkProfileRepository->expects($this->once())->method('unlinkByUserId')->with(33)->willReturn(true);
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile?success=unlinked')
            ->willReturnSelf();
        $this->response->expects($this->once())->method('withStatus')->with(302)->willReturnSelf();

        $this->assertSame($this->response, $this->controller->unlinkFromProfile($this->request, $this->response));
    }

    public function testUnlinkFromProfileStillRedirectsWhenNothingIsLinked(): void
    {
        $this->logger->expects($this->any())->method('info');
        $this->currentUserResolver->method('resolve')->willReturn(new UnlinkOrkUserEntity(33));
        $this->orkProfileRepository->method('findByUserId')->with(33)->willReturn(null);
        $this->orkProfileRepository->expects($this->never())->method('unlinkByUserId');
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/resources/profile?success=unlinked')
            ->willReturnSelf();

        $this->assertSame($this->response, $this->controller->unlinkFromProfile($this->request, $this->response));
    }

    public function testUnlinkFromProfileLogsWhenAProfileIsRemoved(): void
    {
        $this->currentUserResolver->method('resolve')->willReturn(new UnlinkOrkUserEntity(33));
        $this->orkProfileRepository->method('findByUserId')->willReturn(new UnlinkOrkProfileEntity());
        $this->orkProfileRepository->method('unlinkByUserId')->willReturn(true);
        $this->logger->expects($this->once())
            ->method('info')
            ->with('ork profile unlinked', ['user_id' => 33, 'mundane_id' => 4]);

        $this->controller->unlinkFromProfile($this->request, $this->response);
    }

    public function testUnlinkFromProfileLogsWhenNothingIsLinked(): void
    {
        $this->currentUserResolver->method('resolve')->willReturn(new UnlinkOrkUserEntity(33));
        $this->orkProfileRepository->method('findByUserId')->willReturn(null);
        $this->logger->expects($this->once())
            ->method('info')
            ->with('ork profile unlink skipped', ['user_id' => 33, 'reason' => 'not_linked']);

        $this->controller->unlinkFromProfile($this->request, $this->response);
    }

    public function testUnlinkFromProfileLogsWhenTheRowDisappearsBeforeDelete(): void
    {
        $this->currentUserResolver->method('resolve')->willReturn(new UnlinkOrkUserEntity(33));
        $this->orkProfileRepository->method('findByUserId')->willReturn(new UnlinkOrkProfileEntity());
        $this->orkProfileRepository->method('unlinkByUserId')->willReturn(false);
        $this->logger->expects($this->once())
            ->method('info')
            ->with('ork profile unlink skipped', ['user_id' => 33, 'reason' => 'not_linked']);

        $this->controller->unlinkFromProfile($this->request, $this->response);
    }

    public function testUnlinkOrkProfileRejectsAMissingUserId(): void
    {
        $this->logger->expects($this->never())->method('info');
        $this->request->method('getParsedBody')->willReturn(['idp_user_id' => '  ']);
        $this->response->expects($this->once())->method('withStatus')->with(400)->willReturnSelf();
        $this->stream->expects($this->once())->method('write')->with($this->stringContains('idp_user_id'));

        $this->assertSame($this->response, $this->controller->unlinkOrkProfile($this->request, $this->response));
    }

    public function testUnlinkOrkProfileRejectsAnUnknownUser(): void
    {
        $this->logger->expects($this->any())->method('info');
        $this->request->method('getParsedBody')->willReturn(['idp_user_id' => 'missing-user']);
        $this->userRepository->method('findUserByUserId')->with('missing-user')->willReturn(null);
        $this->response->expects($this->once())->method('withStatus')->with(404)->willReturnSelf();
        $this->stream->expects($this->once())->method('write')->with($this->stringContains('unknown idp_user_id'));

        $this->assertSame($this->response, $this->controller->unlinkOrkProfile($this->request, $this->response));
    }

    public function testUnlinkOrkProfileLogsAnUnknownUser(): void
    {
        $this->request->method('getParsedBody')->willReturn(['idp_user_id' => 'missing-user']);
        $this->userRepository->method('findUserByUserId')->willReturn(null);
        $this->logger->expects($this->once())
            ->method('info')
            ->with('unlinkOrkProfile unknown idp_user_id', ['idp_user_id' => 'missing-user']);

        $this->controller->unlinkOrkProfile($this->request, $this->response);
    }

    public function testUnlinkOrkProfileReturnsNoContentWhenTheProfileIsRemoved(): void
    {
        $this->logger->expects($this->any())->method('info');
        $this->request->method('getParsedBody')->willReturn(['idp_user_id' => ' uuid-user ']);
        $this->userRepository->method('findUserByUserId')->with('uuid-user')->willReturn(new UnlinkOrkUserEntity(33));
        $this->orkProfileRepository->method('findByUserId')->with(33)->willReturn(new UnlinkOrkProfileEntity());
        $this->orkProfileRepository->expects($this->once())->method('unlinkByUserId')->with(33)->willReturn(true);
        $this->response->expects($this->once())->method('withStatus')->with(204)->willReturnSelf();

        $this->assertSame($this->response, $this->controller->unlinkOrkProfile($this->request, $this->response));
    }

    public function testUnlinkOrkProfileReturnsNoContentWhenNothingIsLinked(): void
    {
        $this->logger->expects($this->any())->method('info');
        $this->request->method('getParsedBody')->willReturn(['idp_user_id' => 'uuid-user']);
        $this->userRepository->method('findUserByUserId')->willReturn(new UnlinkOrkUserEntity(33));
        $this->orkProfileRepository->method('findByUserId')->willReturn(null);
        $this->orkProfileRepository->expects($this->never())->method('unlinkByUserId');
        $this->response->expects($this->once())->method('withStatus')->with(204)->willReturnSelf();

        $this->assertSame($this->response, $this->controller->unlinkOrkProfile($this->request, $this->response));
    }
}
