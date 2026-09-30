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
