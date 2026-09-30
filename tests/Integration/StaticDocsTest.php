<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegHttp;

/** Mode A — public static pages and developer documentation shells. */
final class StaticDocsTest extends IntegTestCase
{
    public function testHomePageReturnsWelcomeCopy(): void
    {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $http = new IntegHttp($baseUrl);
        $response = $http->get('/');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Amtgard Identity Provider', $body);
        $this->assertStringContainsString('Welcome to the Amtgard Identity Provider service', $body);
    }

    public function testSwaggerUiPageLoadsOpenApiShell(): void
    {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $http = new IntegHttp($baseUrl);
        $response = $http->get('/swagger');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('id="swagger-ui"', $body);
        $this->assertStringContainsString("url: '/openapi.json'", $body);
    }

    public function testDocsifyPageReturnsDeveloperDocumentationShell(): void
    {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $http = new IntegHttp($baseUrl);
        $response = $http->get('/docs');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Amtgard IDP - Developer Documentation', $body);
        $this->assertStringContainsString('cdn.jsdelivr.net/npm/docsify@4', $body);
    }

    public function testDocsifyPageWithTrailingSlashReturnsSameShell(): void
    {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $http = new IntegHttp($baseUrl);
        $response = $http->get('/docs/');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Amtgard IDP - Developer Documentation', $body);
    }

    public function testDocsReadmeMarkdownServesIntegrationGuide(): void
    {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $http = new IntegHttp($baseUrl);
        $response = $http->get('/docs/readme.md');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('text/markdown', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        $this->assertStringContainsString('# Amtgard Identity Provider Integration Guide', $body);
    }

    public function testDocsReadmeUppercasePathServesSameMarkdown(): void
    {
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $http = new IntegHttp($baseUrl);
        $response = $http->get('/docs/README.md');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('text/markdown', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        $this->assertStringContainsString('# Amtgard Identity Provider Integration Guide', $body);
    }
}
