<?php
declare(strict_types=1);

namespace Amtgard\IdP\Middleware;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Utility\UserRole;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Routing\RouteContext;

class LocalAdminUserMiddleware implements MiddlewareInterface
{
    private UserRepository $userRepository;

    public function __construct(EntityManager $entityManager, UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $session = $request->getAttribute('session');
        $userId = $session['user_id'] ?? null;

        if ($userId) {
            $user = $this->userRepository->findUserByUserId($userId);
            if ($user && $user->getRole() === UserRole::Admin) {
                return $handler->handle($request);
            }
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
