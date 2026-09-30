<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Psr\Http\Message\ResponseInterface;

/** HTTP helper for live-stack integration tests (Mode A cookie jar + Mode B API). */
final class IntegHttp
{
    private Client $client;

    public function __construct(
        private readonly string $baseUrl,
        private readonly CookieJar $jar = new CookieJar(),
    ) {
        $this->client = new Client([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
            'cookies' => $this->jar,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    public function cookieJar(): CookieJar
    {
        return $this->jar;
    }

    public function get(string $path): ResponseInterface
    {
        return $this->client->get(ltrim($path, '/'));
    }

    /** @param array<string, string> $fields */
    public function postForm(string $path, array $fields): ResponseInterface
    {
        return $this->client->post(ltrim($path, '/'), [
            'form_params' => $fields,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);
    }

    public function parseCsrfToken(string $html): string
    {
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $html, $matches) !== 1) {
            throw new \RuntimeException('CSRF token not found in HTML');
        }

        return $matches[1];
    }

    public function redirectLocation(ResponseInterface $response): ?string
    {
        $location = $response->getHeaderLine('Location');
        if ($location === '') {
            return null;
        }

        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        return rtrim($this->baseUrl, '/') . $location;
    }

    public function isRedirectToPath(ResponseInterface $response, string $path): bool
    {
        if ($response->getStatusCode() !== 302) {
            return false;
        }
        $location = $this->redirectLocation($response);
        if ($location === null) {
            return false;
        }
        $normalizedPath = str_starts_with($path, '/') ? $path : '/' . $path;
        $parsed = parse_url($location);
        $locationPath = $parsed['path'] ?? '';

        return $locationPath === $normalizedPath;
    }

    public function hasSessionCookie(): bool
    {
        foreach ($this->jar->toArray() as $cookie) {
            if (($cookie['Name'] ?? '') === 'PHPSESSID' && ($cookie['Value'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    public function setCookie(SetCookie $cookie): void
    {
        $this->jar->setCookie($cookie);
    }
}
