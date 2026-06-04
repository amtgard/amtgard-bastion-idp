<?php
declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Api;

use Amtgard\IdP\Models\Orn\ClaimFactory;
use Amtgard\IdP\Models\Orn\IdpRequirement;
use Amtgard\IdP\Models\Policy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ApiController
{
    public function isAuthorized(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $policyJson = $body['policy'] ?? '[]';
        $requirementString = $body['requirement'] ?? '';

        $claims = [];
        foreach (json_decode($policyJson, true) as $claimString) {
            $claims[] = ClaimFactory::createOrn($claimString);
        }
        $policy = new Policy($claims);
        $requirement = new IdpRequirement($requirementString);

        $isAuthorized = $policy->isAuthorized($requirement);

        $response->getBody()->write(json_encode(['is_authorized' => $isAuthorized]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
