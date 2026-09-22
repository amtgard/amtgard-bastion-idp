<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Utility\JsonResponseBody;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

class JsonResponseBodyTest extends TestCase
{
    public function testWriteEncodesPayloadAndSetsContentType(): void
    {
        $response = new Response();
        $result = JsonResponseBody::write($response, ['id' => 'user-1'], 200);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('application/json', $result->getHeaderLine('Content-Type'));
        $this->assertSame('{"id":"user-1"}', (string) $result->getBody());
    }

    public function testWriteOmitsStatusWhenNull(): void
    {
        $response = new Response(418);
        $result = JsonResponseBody::write($response, ['ok' => true]);

        $this->assertSame(418, $result->getStatusCode());
    }

    public function testWriteErrorUsesErrorShape(): void
    {
        $response = new Response();
        $result = JsonResponseBody::writeError($response, 'unauthorized', 401);

        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('{"error":"unauthorized"}', (string) $result->getBody());
    }
}
