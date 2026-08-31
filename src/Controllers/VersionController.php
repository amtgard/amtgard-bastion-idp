<?php
declare(strict_types=1);

namespace Amtgard\IdP\Controllers;

use Amtgard\IdP\Utility\BuildInfo;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class VersionController
{
    public function index(Request $request, Response $response): Response
    {
        $body = json_encode(BuildInfo::load(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($body);

        return $response->withHeader('Content-Type', 'application/json');
    }
}
