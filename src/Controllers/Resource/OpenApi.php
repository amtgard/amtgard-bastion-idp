<?php
declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Resource;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: "Amtgard Identity Provider API",
    version: "1.0.0",
    description: "OAuth 2.0 and resource endpoints for Amtgard apps. ORK-specific server-to-server integration is under the ORK Integration tag; registered client IAM APIs under Client. See /docs for the two-step JWT elevation flow."
)]
#[OA\Tag(
    name: 'ORK Integration',
    description: 'Amtgard-specific coupling with ORK3. Not for general third-party OAuth clients. See /docs Section 7 for browser handoff flows.'
)]
#[OA\Tag(
    name: 'Client',
    description: 'Server-to-server APIs for registered confidential OAuth clients with an assigned iam_service. See /docs Section 8.'
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
    bearerFormat: "JWT",
    description: "RS256 authorization JWT from GET /resources/jwt (used with /resources/userinfo and /resources/validate)"
)]
#[OA\SecurityScheme(
    securityScheme: 'oauthAccessToken',
    type: 'http',
    scheme: 'bearer',
    description: 'OAuth 2.0 access token from POST /oauth/token (used to elevate to an authorization JWT at GET /resources/jwt)'
)]
#[OA\SecurityScheme(
    securityScheme: 'orkConfidentialClient',
    type: 'http',
    scheme: 'basic',
    description: 'ORK confidential OAuth client_id and client_secret (HTTP Basic Auth)'
)]
#[OA\SecurityScheme(
    securityScheme: 'clientBasicAuth',
    type: 'http',
    scheme: 'basic',
    description: 'Confidential OAuth client_id and client_secret for a client with a configured iam_service (HTTP Basic Auth)'
)]
class OpenApi
{
    // This class serves as a container for global OpenAPI annotations scanned by swagger-php.
}
