<?php
declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Api;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IAM\PolicyFactory;
use Amtgard\IdP\Models\Orn\IdpRequirement;
use Amtgard\IdP\Utility\Log\IdpLogging;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ApiController
{
    public function __construct(
        private IdpLogging $idpLogging,
    ) {
    }

    public function isAuthorized(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $policyJson = $body['policy'] ?? '[]';
        $requirementString = $body['requirement'] ?? '';

        $policy = PolicyFactory::fromOrn(json_decode($policyJson, true));
        $requirement = new IdpRequirement(ServiceCatalog::Idp, $requirementString);

        $isAuthorized = $policy->isAuthorized($requirement);

        $this->idpLogging->app()->debug('api is_authorized evaluated', [
            'is_authorized' => $isAuthorized,
        ]);

        $response->getBody()->write(json_encode(['is_authorized' => $isAuthorized]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
