<?php
declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Amtgard\IdP\Utility\Log\IdpLogging;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class JsonBodyParserMiddleware implements MiddlewareInterface
{
    public function __construct(private IdpLogging $idpLogging)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (strstr($contentType, 'application/json')) {
            $contents = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->idpLogging->app()->debug('json body parsed', [
                    'path' => $request->getUri()->getPath(),
                ]);
                $request = $request->withParsedBody($contents);
            }
        }

        return $handler->handle($request);
    }
}
