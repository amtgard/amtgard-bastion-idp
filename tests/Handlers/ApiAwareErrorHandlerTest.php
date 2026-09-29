<?php
declare(strict_types=1);

namespace Amtgard\IdP\Tests\Handlers;

use Amtgard\IdP\Handlers\ApiAwareErrorHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Interfaces\CallableResolverInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

class ApiAwareErrorHandlerTest extends TestCase
{
    public function testValidate401IsJsonWithoutDetailsWhenNotDebug(): void
    {
        $response = $this->handler()(
            $this->request('/resources/validate', 'text/html'),
            new HttpUnauthorizedException($this->request('/resources/validate', 'text/html')),
            false,
            false,
            false
        );

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-type'));
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('message', $decoded);
        $this->assertArrayNotHasKey('exception', $decoded);
        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringNotContainsString('<!DOCTYPE', $body);
        $this->assertStringNotContainsString('<!doctype', $body);
    }

    public function testValidate401JsonIncludesExceptionOnlyInDebug(): void
    {
        $request = $this->request('/resources/validate', '*/*');
        $response = $this->handler()(
            $request,
            new HttpUnauthorizedException($request, 'Not authorized.'),
            true,
            false,
            false
        );

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-type'));
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('exception', $decoded);
        $this->assertStringNotContainsString('<html', $body);
    }

    public function testHtmlRouteKeepsHtmlWhenNotDebug(): void
    {
        $request = $this->request('/profile', 'text/html');
        $response = $this->handler()(
            $request,
            new HttpUnauthorizedException($request),
            false,
            false,
            false
        );

        $body = (string) $response->getBody();
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('text/html', $response->getHeaderLine('Content-type'));
        $this->assertStringContainsString('<html', strtolower($body));
        $this->assertStringNotContainsString('ApiAwareErrorHandler.php', $body);
    }

    #[DataProvider('browserPages')]
    public function testBrowserPageErrorsAreFriendlyEvenWhenDebug(string $path): void
    {
        $request = $this->request($path, 'application/json');
        $response = $this->handler($this->twig())(
            $request,
            new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '4' for key 'ux_user_ork_profiles_mundane_id'"),
            true,
            false,
            false
        );

        $body = (string) $response->getBody();
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('text/html', $response->getHeaderLine('Content-type'));
        $this->assertStringContainsString('We could not complete that request', $body);
        $this->assertStringNotContainsString('Duplicate entry', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
    }

    public function testMachineRouteStaysJsonWhenDebug(): void
    {
        $request = $this->request('/oauth/token', 'text/html');
        $response = $this->handler($this->twig())(
            $request,
            new \RuntimeException('token exploded'),
            true,
            false,
            false
        );

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        $this->assertSame('application/json', $response->getHeaderLine('Content-type'));
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('exception', $decoded);
        $this->assertStringNotContainsString('We could not complete that request', $body);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function browserPages(): array
    {
        return [
            ['/resources/profile'],
            ['/resources/profile/link-ork'],
            ['/resources/clients'],
            ['/resources/clients/1/redirect'],
            ['/oauth/authorize'],
            ['/oauth/approve'],
        ];
    }

    private function handler(?TwigEnvironment $view = null): ApiAwareErrorHandler
    {
        $callableResolver = $this->createMock(CallableResolverInterface::class);
        $callableResolver->method('resolve')->willReturnCallback(
            static fn ($callable) => is_string($callable) ? new $callable() : $callable
        );

        return new ApiAwareErrorHandler(
            $callableResolver,
            new ResponseFactory(),
            $this->createStub(LoggerInterface::class),
            $view
        );
    }

    private function twig(): TwigEnvironment
    {
        return new TwigEnvironment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
    }

    private function request(string $path, string $accept): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturnCallback(
            static function (string $name) use ($accept): string {
                return $name === 'Accept' ? $accept : '';
            }
        );

        return $request;
    }
}
