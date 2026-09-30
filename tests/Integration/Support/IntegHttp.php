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

    /** @param array<string, mixed> $body */
    public function postJson(string $path, array $body): ResponseInterface
    {
        return $this->client->post(ltrim($path, '/'), [
            'json' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function getWithBasicAuth(string $path, string $clientId, string $clientSecret): ResponseInterface
    {
        return $this->client->get(ltrim($path, '/'), [
            'headers' => [
                'Authorization' => $this->basicAuthorizationHeader($clientId, $clientSecret),
            ],
        ]);
    }

    /** @param array<string, mixed> $body */
    public function postJsonWithBasicAuth(
        string $path,
        array $body,
        string $clientId,
        string $clientSecret,
    ): ResponseInterface {
        return $this->client->post(ltrim($path, '/'), [
            'json' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => $this->basicAuthorizationHeader($clientId, $clientSecret),
            ],
        ]);
    }

    /** @param array<string, mixed> $body */
    public function putJsonWithBasicAuth(
        string $path,
        array $body,
        string $clientId,
        string $clientSecret,
    ): ResponseInterface {
        return $this->client->put(ltrim($path, '/'), [
            'json' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => $this->basicAuthorizationHeader($clientId, $clientSecret),
            ],
        ]);
    }

    /** @param array<string, mixed> $body */
    public function deleteJsonWithBasicAuth(
        string $path,
        array $body,
        string $clientId,
        string $clientSecret,
    ): ResponseInterface {
        return $this->client->delete(ltrim($path, '/'), [
            'json' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => $this->basicAuthorizationHeader($clientId, $clientSecret),
            ],
        ]);
    }

    public function deleteWithBasicAuth(string $path, string $clientId, string $clientSecret): ResponseInterface
    {
        return $this->client->delete(ltrim($path, '/'), [
            'headers' => [
                'Authorization' => $this->basicAuthorizationHeader($clientId, $clientSecret),
            ],
        ]);
    }

    private function basicAuthorizationHeader(string $clientId, string $clientSecret): string
    {
        return 'Basic ' . base64_encode($clientId . ':' . $clientSecret);
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

    /** @param array<string, string> $fields */
    public function postFormWithBasicAuth(
        string $path,
        array $fields,
        string $clientId,
        string $clientSecret,
    ): ResponseInterface {
        return $this->client->post(ltrim($path, '/'), [
            'form_params' => $fields,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
            ],
        ]);
    }

    public function getWithBearerToken(string $path, string $bearerToken): ResponseInterface
    {
        return $this->client->get(ltrim($path, '/'), [
            'headers' => [
                'Authorization' => 'Bearer ' . $bearerToken,
            ],
        ]);
    }

    public function parseCsrfToken(string $html): string
    {
        return $this->parseHiddenField($html, '_csrf_token');
    }

    public function parseHiddenField(string $html, string $name): string
    {
        $pattern = '/name="' . preg_quote($name, '/') . '"\s+value="([^"]+)"/';
        if (preg_match($pattern, $html, $matches) !== 1) {
            throw new \RuntimeException("Hidden field {$name} not found in HTML");
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

    public function idpHost(): string
    {
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('Invalid IDP_BASE_URL host');
        }

        return strtolower($host);
    }

    public function locationHost(ResponseInterface $response): ?string
    {
        $location = $this->redirectLocation($response);
        if ($location === null) {
            return null;
        }
        $host = parse_url($location, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }

    public function parseQueryParam(string $url, string $name): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            throw new \RuntimeException("Query string missing in URL for param {$name}");
        }
        parse_str($query, $params);
        $value = $params[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException("Query param {$name} not found in URL");
        }

        return $value;
    }

    public function getBearer(string $path): ResponseInterface
    {
        $jwtResponse = $this->get('/resources/jwt');
        if ($jwtResponse->getStatusCode() !== 200) {
            throw new \RuntimeException('Expected 200 from /resources/jwt; status=' . $jwtResponse->getStatusCode());
        }
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $jwtResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $jwt = $payload['jwt'] ?? null;
        if (!is_string($jwt) || $jwt === '') {
            throw new \RuntimeException('Authorization JWT missing from /resources/jwt');
        }

        return $this->client->get(ltrim($path, '/'), [
            'headers' => [
                'Authorization' => 'Bearer ' . $jwt,
            ],
        ]);
    }

    public function isRedirectToPath(ResponseInterface $response, string $path): bool
    {
        $status = $response->getStatusCode();
        if ($status !== 301 && $status !== 302) {
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
