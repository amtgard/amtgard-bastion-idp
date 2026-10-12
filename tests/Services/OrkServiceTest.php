<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services;

use Amtgard\IdP\Services\OrkService;
use Amtgard\IdP\Utility\Log\IdpLogging;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

class OrkServiceTest extends TestCase
{
    private LoggerInterface $logger;
    /** @var Client&\PHPUnit\Framework\MockObject\MockObject */
    private Client $clientMock;
    private OrkService $service;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $idpLogging = $this->createMock(IdpLogging::class);
        $idpLogging->method('app')->willReturn($this->logger);
        $this->clientMock = $this->createMock(Client::class);
        $this->service = new OrkService($this->clientMock, $idpLogging);
    }

    public function testAuthorizeUsesExpectedOrkQueryShape(): void
    {
        $responseMock = $this->jsonResponse(['Status' => ['Status' => 0], 'Token' => 't']);

        $this->clientMock->expects($this->once())
            ->method('get')
            ->with(
                'https://ork.amtgard.com/orkservice/Json/index.php',
                $this->callback(function (array $options): bool {
                    $this->assertSame('Authorization/Authorize', $options['query']['call']);
                    $this->assertSame('alice', $options['query']['request']['UserName']);
                    $this->assertSame('secret', $options['query']['request']['Password']);

                    return true;
                }),
            )
            ->willReturn($responseMock);

        $this->logger->expects($this->any())->method('debug');

        $this->service->authorize('alice', 'secret');
    }

    public function testAuthorizeSuccess(): void
    {
        $responseMock = $this->jsonResponse([
            'Status' => ['Status' => 0],
            'Token' => 'test-token',
        ]);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('ork authorization request', $this->callback(function (array $context): bool {
                $this->assertSame('Authorization/Authorize', $context['call']);

                return true;
            }));

        $this->clientMock->expects($this->once())
            ->method('get')
            ->with(self::anything(), $this->callback(function ($options) use ($responseMock) {
                if (isset($options['on_stats'])) {
                    $request = $this->createMock(\Psr\Http\Message\RequestInterface::class);
                    $uri = new \GuzzleHttp\Psr7\Uri('https://ork.amtgard.com/orkservice/Json/index.php');
                    $request->method('getUri')->willReturn($uri);
                    $stats = new \GuzzleHttp\TransferStats($request, $responseMock);
                    $options['on_stats']($stats);
                }

                return true;
            }))
            ->willReturn($responseMock);

        $result = $this->service->authorize('user', 'pass');
        $this->assertIsArray($result);
        $this->assertSame(0, $result['Status']['Status']);
        $this->assertSame('test-token', $result['Token']);
    }

    public function testAuthorizeFailure(): void
    {
        $responseMock = $this->jsonResponse(['Status' => ['Status' => 1]]);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('ork authorization failed', $this->callback(function (array $context): bool {
                $this->assertSame('ork_authorization_failed', $context['reason']);
                $this->assertArrayNotHasKey('username', $context);

                return true;
            }));

        $this->clientMock->expects($this->once())
            ->method('get')
            ->willReturn($responseMock);

        $this->assertNull($this->service->authorize('user', 'pass'));
    }

    public function testAuthorizeException(): void
    {
        $request = $this->createMock(\Psr\Http\Message\RequestInterface::class);
        $this->logger->expects($this->once())
            ->method('error')
            ->with('ork authorization exception', $this->callback(function (array $context): bool {
                $this->assertSame(RequestException::class, $context['exception_class']);

                return true;
            }));

        $this->clientMock->expects($this->once())
            ->method('get')
            ->willThrowException(new RequestException('Error', $request));

        $this->assertNull($this->service->authorize('user', 'pass'));
    }

    public function testGetPlayerSuccess(): void
    {
        $responseMock = $this->jsonResponse([
            'Status' => ['Status' => 0],
            'Player' => [
                'name' => 'John',
                'ParkId' => 99,
                'KingdomName' => 'Dragonspine',
            ],
        ]);

        $this->clientMock->expects($this->once())
            ->method('get')
            ->willReturn($responseMock);

        $result = $this->service->getPlayer('token', 123);
        $this->assertSame('John', $result['name']);
    }

    public function testGetPlayerUsesExpectedOrkQueryShape(): void
    {
        $responseMock = $this->jsonResponse([
            'Status' => ['Status' => 0],
            'Player' => ['name' => 'John'],
        ]);

        $this->clientMock->expects($this->once())
            ->method('get')
            ->with(
                'https://ork.amtgard.com/orkservice/Json/index.php',
                $this->callback(function (array $options): bool {
                    $this->assertSame('Player/GetPlayer', $options['query']['call']);
                    $this->assertSame('tok', $options['query']['request']['Token']);
                    $this->assertSame(123, $options['query']['request']['MundaneId']);

                    return true;
                }),
            )
            ->willReturn($responseMock);

        $this->service->getPlayer('tok', 123);
    }

    public function testGetPlayerFailureWhenStatusNotZero(): void
    {
        $responseMock = $this->jsonResponse(['Status' => ['Status' => 2]], 502);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('ork get player failed', $this->callback(function (array $context): bool {
                $this->assertSame(123, $context['mundane_id']);
                $this->assertSame(502, $context['http_status']);

                return true;
            }));

        $this->clientMock->expects($this->once())
            ->method('get')
            ->willReturn($responseMock);

        $this->assertNull($this->service->getPlayer('token', 123));
    }

    public function testGetPlayerFailureWhenStatusKeyMissing(): void
    {
        $responseMock = $this->jsonResponse(['Player' => ['name' => 'John']]);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('ork get player failed', $this->anything());

        $this->clientMock->expects($this->once())
            ->method('get')
            ->willReturn($responseMock);

        $this->assertNull($this->service->getPlayer('token', 123));
    }

    public function testGetPlayerFailureWhenPlayerKeyMissing(): void
    {
        $responseMock = $this->jsonResponse(['Status' => ['Status' => 0]]);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('ork get player failed', $this->anything());

        $this->clientMock->expects($this->once())
            ->method('get')
            ->willReturn($responseMock);

        $this->assertNull($this->service->getPlayer('token', 123));
    }

    public function testGetPlayerException(): void
    {
        $request = $this->createMock(\Psr\Http\Message\RequestInterface::class);
        $this->logger->expects($this->once())
            ->method('error')
            ->with('ork get player exception', $this->callback(function (array $context): bool {
                $this->assertSame(123, $context['mundane_id']);
                $this->assertSame(RequestException::class, $context['exception_class']);

                return true;
            }));

        $this->clientMock->expects($this->once())
            ->method('get')
            ->willThrowException(new RequestException('Error', $request));

        $this->assertNull($this->service->getPlayer('token', 123));
    }

    public function testGetParkShortInfoUsesExpectedOrkQueryShape(): void
    {
        $responseMock = $this->jsonResponse(['Status' => ['Status' => 0]]);

        $this->clientMock->expects($this->once())
            ->method('get')
            ->with(
                'https://ork.amtgard.com/orkservice/Json/index.php',
                $this->callback(function (array $options): bool {
                    $this->assertSame('Park/GetParkShortInfo', $options['query']['call']);
                    $this->assertSame(456, $options['query']['request']['ParkId']);

                    return true;
                }),
            )
            ->willReturn($responseMock);

        $this->logger->expects($this->any())->method('debug');

        $this->service->getParkShortInfo(456);
    }

    public function testGetParkShortInfoSuccess(): void
    {
        $responseMock = $this->jsonResponse([
            'Status' => ['Status' => 0],
            'ParkInfo' => ['ParkName' => 'Sherwood'],
            'KingdomInfo' => ['KingdomName' => 'Dragonspine'],
        ]);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('ork get park request', ['park_id' => 456]);

        $this->clientMock->expects($this->once())
            ->method('get')
            ->with(self::anything(), $this->callback(function ($options) use ($responseMock) {
                if (isset($options['on_stats'])) {
                    $request = $this->createMock(\Psr\Http\Message\RequestInterface::class);
                    $uri = new \GuzzleHttp\Psr7\Uri('https://ork.amtgard.com/orkservice/Json/index.php?call=Park');
                    $request->method('getUri')->willReturn($uri);
                    $stats = new \GuzzleHttp\TransferStats($request, $responseMock);
                    $options['on_stats']($stats);
                }

                return true;
            }))
            ->willReturn($responseMock);

        $result = $this->service->getParkShortInfo(456);
        $this->assertSame(0, $result['Status']['Status']);
        $this->assertSame('Sherwood', $result['ParkInfo']['ParkName']);
    }

    public function testGetParkShortInfoFailure(): void
    {
        $responseMock = $this->jsonResponse(['Status' => ['Status' => 1], 'Error' => 'nope'], 500);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('ork get park failed', $this->callback(function (array $context): bool {
                $this->assertSame(456, $context['park_id']);
                $this->assertSame(500, $context['http_status']);

                return true;
            }));

        $this->clientMock->expects($this->once())
            ->method('get')
            ->willReturn($responseMock);

        $this->assertNull($this->service->getParkShortInfo(456));
    }

    public function testGetParkShortInfoException(): void
    {
        $request = $this->createMock(\Psr\Http\Message\RequestInterface::class);
        $this->logger->expects($this->once())
            ->method('error')
            ->with('ork get park exception', $this->callback(function (array $context): bool {
                $this->assertSame(456, $context['park_id']);
                $this->assertSame(RequestException::class, $context['exception_class']);

                return true;
            }));

        $this->clientMock->expects($this->once())
            ->method('get')
            ->willThrowException(new RequestException('Error', $request));

        $this->assertNull($this->service->getParkShortInfo(456));
    }

    public function testGetParkShortInfoSkipsInvalidParkId(): void
    {
        $this->clientMock->expects($this->never())->method('get');
        $this->logger->expects($this->never())->method('warning');

        $this->assertNull($this->service->getParkShortInfo(0));
        $this->assertNull($this->service->getParkShortInfo(-3));
    }

    public function testResolveParkDataFromPlayerRejectsZeroParkId(): void
    {
        $this->clientMock->expects($this->never())->method('get');
        $this->logger->expects($this->once())
            ->method('debug')
            ->with('ork resolve park from player', $this->callback(function (array $context): bool {
                $this->assertNull($context['park_id_resolved']);

                return true;
            }));

        $this->assertNull($this->service->resolveParkDataFromPlayer(
            ['MundaneId' => 55, 'ParkId' => '0'],
            7,
            'link-flow',
        ));
    }

    public function testResolveParkDataFromPlayerCastsStringParkId(): void
    {
        $parkResponse = $this->jsonResponse([
            'Status' => ['Status' => 0],
            'ParkInfo' => ['ParkName' => 'Sherwood'],
        ]);

        $this->clientMock->expects($this->once())
            ->method('get')
            ->with(self::anything(), $this->callback(function (array $options): bool {
                $this->assertSame(456, $options['query']['request']['ParkId']);

                return true;
            }))
            ->willReturn($parkResponse);

        $this->logger->expects($this->atLeastOnce())->method('debug');

        $result = $this->service->resolveParkDataFromPlayer(
            ['MundaneId' => 55, 'ParkId' => '456'],
            7,
            'link-flow',
        );

        $this->assertSame('Sherwood', $result['ParkInfo']['ParkName']);
    }

    public function testResolveParkDataFromPlayerReturnsNullWhenParkIdMissing(): void
    {
        $this->clientMock->expects($this->never())->method('get');
        $this->logger->expects($this->once())
            ->method('debug')
            ->with('ork resolve park from player', $this->callback(function (array $context): bool {
                $this->assertSame(7, $context['user_id']);
                $this->assertNull($context['park_id_resolved']);

                return true;
            }));

        $this->assertNull($this->service->resolveParkDataFromPlayer(['MundaneId' => 55], 7, 'link-flow'));
    }

    public function testResolveParkDataFromPlayerReturnsParkData(): void
    {
        $parkResponse = $this->jsonResponse([
            'Status' => ['Status' => 0],
            'ParkInfo' => ['ParkName' => 'Sherwood'],
        ]);

        $this->clientMock->expects($this->once())
            ->method('get')
            ->willReturn($parkResponse);

        $this->logger->expects($this->atLeastOnce())->method('debug');

        $result = $this->service->resolveParkDataFromPlayer(
            ['MundaneId' => 55, 'ParkId' => 456],
            7,
            'refresh-flow',
        );

        $this->assertSame('Sherwood', $result['ParkInfo']['ParkName']);
    }

    public function testResolveParkDataFromPlayerLogsWarningWhenLookupFails(): void
    {
        $parkResponse = $this->jsonResponse(['Status' => ['Status' => 1]]);

        $this->clientMock->expects($this->once())
            ->method('get')
            ->willReturn($parkResponse);

        $loggedMessages = [];
        $this->logger->method('error')->willReturnCallback(function (string $message, array $context = []) use (&$loggedMessages): void {
            $loggedMessages[] = [$message, $context];
        });
        $this->logger->method('warning')->willReturnCallback(function (string $message, array $context = []) use (&$loggedMessages): void {
            $loggedMessages[] = [$message, $context];
        });
        $this->logger->method('debug')->willReturnCallback(static function (): void {
        });

        $this->assertNull($this->service->resolveParkDataFromPlayer(
            ['MundaneId' => 55, 'ParkId' => 456],
            7,
            'refresh-flow',
        ));

        $this->assertSame('ork get park failed', $loggedMessages[0][0]);
        $this->assertSame('ork park lookup empty', $loggedMessages[1][0]);
        $this->assertSame(7, $loggedMessages[1][1]['user_id']);
        $this->assertSame(456, $loggedMessages[1][1]['park_id']);
    }

    public function testGetPlayerByMundaneIdLogsEmailFieldPresenceWithoutValue(): void
    {
        $responseMock = $this->jsonResponse([
            'Status' => ['Status' => 0],
            'Player' => [
                'MundaneId' => 4,
                'UserName' => 'orkuser',
                'Email' => 'hidden@example.com',
                'Suspended' => 0,
            ],
        ]);

        $this->clientMock->expects($this->once())
            ->method('get')
            ->with(
                self::anything(),
                $this->callback(function (array $options): bool {
                    $this->assertSame('Player/GetPlayer', $options['query']['call']);
                    $this->assertSame(4, $options['query']['request']['MundaneId']);
                    $this->assertArrayNotHasKey('Token', $options['query']['request']);

                    return true;
                }),
            )
            ->willReturn($responseMock);

        $player = $this->service->getPlayerByMundaneId(4);
        $this->assertSame('hidden@example.com', $player['Email']);
    }

    public function testExtractParkRelatedFieldsIgnoresNonStringKeys(): void
    {
        $responseMock = $this->jsonResponse([
            'Status' => ['Status' => 0],
            'Player' => [
                'name' => 'John',
                0 => 'ignored',
                'ParkId' => 1,
            ],
        ]);

        $this->clientMock->expects($this->once())
            ->method('get')
            ->willReturn($responseMock);

        $this->service->getPlayer('token', 1);
    }

    public function testBeginClaimOrkMailAcceptsFlatJsonServerStatus(): void
    {
        $this->clientMock->expects($this->once())
            ->method('get')
            ->willReturn($this->jsonResponse(['Status' => 0, 'Code' => 0, 'Error' => 'Success']));

        $this->assertTrue($this->service->beginClaimOrkMail('nonce'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload, int $status = 200): ResponseInterface
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $streamMock = $this->createMock(StreamInterface::class);
        $streamMock->method('getContents')->willReturn(json_encode($payload, JSON_THROW_ON_ERROR));
        $responseMock->method('getBody')->willReturn($streamMock);
        $responseMock->method('getStatusCode')->willReturn($status);

        return $responseMock;
    }
}
