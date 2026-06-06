<?php
declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Resource;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: "Amtgard Identity Provider API",
    version: "1.0.0",
    description: "OAuth 2.0 and resource endpoints for Amtgard apps. ORK-specific server-to-server integration is under the ORK Integration tag; browser handoff flows are documented in /docs (Section 7)."
)]
#[OA\Tag(
    name: 'ORK Integration',
    description: 'Amtgard-specific coupling with ORK3. Not for general third-party OAuth clients. See /docs Section 7 for browser handoff flows.'
)]
#[OA\Server(
    url: "/",
    description: "Current Host Server"
)]
#[OA\SecurityScheme(
    securityScheme: "bearerAuth",
    type: "http",
    name: "Authorization",
    in: "header",
    scheme: "bearer",
    bearerFormat: "JWT"
)]
#[OA\SecurityScheme(
    securityScheme: 'orkConfidentialClient',
    type: 'http',
    scheme: 'basic',
    description: 'ORK confidential OAuth client_id and client_secret (HTTP Basic Auth)'
)]
class OpenApi
{
    // This class serves as a container for global OpenAPI annotations scanned by swagger-php.
}
