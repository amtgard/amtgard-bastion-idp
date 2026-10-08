<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

use Amtgard\IdP\Controllers\Resource\OrkIntegrationController;
use Amtgard\IdP\Services\Ork\OrkClaimSendNonceService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

final class OrkIntegrationControllerTest extends TestCase
{
    public function testValidateSendNonceReturnsClaimsWhenValid(): void
    {
        $nonces = $this->createMock(OrkClaimSendNonceService::class);
        $nonces->expects($this->once())
            ->method('validateAndConsumeSendNonce')
            ->with('abc')
            ->willReturn(['idp_user_id' => 'user-1', 'mundane_id' => 42]);

        $controller = new OrkIntegrationController($nonces, $this->createMock(LoggerInterface::class));

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['send_nonce' => 'abc']);

        $stream = $this->createMock(StreamInterface::class);
        $stream->expects($this->once())->method('write')->with($this->callback(function (string $json): bool {
            $data = json_decode($json, true);

            return ($data['idp_user_id'] ?? '') === 'user-1' && ($data['mundane_id'] ?? 0) === 42;
        }));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('withHeader')->willReturnSelf();
        $response->expects($this->once())->method('withStatus')->with(200)->willReturnSelf();

        $controller->validateSendNonce($request, $response);
    }

    public function testValidateSendNonceReturns404WhenInvalid(): void
    {
        $nonces = $this->createMock(OrkClaimSendNonceService::class);
        $nonces->method('validateAndConsumeSendNonce')->willReturn(null);

        $controller = new OrkIntegrationController($nonces, $this->createMock(LoggerInterface::class));
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['send_nonce' => 'bad']);

        $stream = $this->createMock(StreamInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);
        $response->method('withHeader')->willReturnSelf();
        $response->expects($this->once())->method('withStatus')->with(404)->willReturnSelf();

        $controller->validateSendNonce($request, $response);
    }
}
