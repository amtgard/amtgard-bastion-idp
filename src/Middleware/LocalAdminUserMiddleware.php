<?php
declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Amtgard\IdP\Utility\UserAuthority;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Routing\RouteContext;

class LocalAdminUserMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private UserAuthority $userAuthority,
        private IdpLogging $logging,
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $session = $request->getAttribute('session');
        $userId = $session['user_id'] ?? null;

        if ($userId) {
            $user = $this->userRepository->findUserByUserId($userId);

            if ($this->userAuthority->isAdmin($user)) {
                $this->logging->security()->debug('local admin policy allow', [
                    'user_uuid' => $userId,
                    'admin' => true,
                ]);

                return $handler->handle($request);
            }

            $this->logging->security()->error('local admin access denied', [
                'user_uuid' => $userId,
                'failure_reason' => 'not_admin',
            ]);
        } else {
            $this->logging->security()->error('local admin access denied', [
                'failure_reason' => 'unauthenticated',
                'http_status' => 302,
            ]);
        }

        $responseFactory = new ResponseFactory();
        $response = $responseFactory->createResponse();
        $routeContext = RouteContext::fromRequest($request);
        $routeParser = $routeContext->getRouteParser();

        return $response
            ->withHeader('Location', $routeParser->urlFor('resources.profile'))
            ->withStatus(302);
    }
}
