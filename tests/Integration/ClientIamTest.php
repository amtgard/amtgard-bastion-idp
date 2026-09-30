<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegDb;
use Amtgard\IdP\Tests\Integration\Support\IntegHttp;
/** Mode B — confidential client IAM, service format, and ORK profile mirror routes. */
final class ClientIamTest extends IntegTestCase
{
    private const IAM_SERVICE = 'IntegApp';
    private const PROVISOS = ':0::::';
    private const RESOURCE = 'IntegIam/Grant';
    private const MIRROR_MUNDANE_ID = 990014001;

    private IntegHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $this->http = new IntegHttp($baseUrl);
    }

    public function testClientIamPolicyMetadataFormatAndOrkMirror(): void
    {
        $clientId = IntegFixtures::CONFIDENTIAL_CLIENT_ID;
        $clientSecret = IntegFixtures::CONFIDENTIAL_CLIENT_SECRET;

        $lookup = $this->http->getWithBasicAuth(
            '/resources/client/users/by-email?email=' . rawurlencode(IntegFixtures::PLAYER_EMAIL),
            $clientId,
            $clientSecret,
        );
        $this->assertSame(200, $lookup->getStatusCode(), (string) $lookup->getBody());
        /** @var array<string, mixed> $lookupPayload */
        $lookupPayload = json_decode((string) $lookup->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $idpUserId = $lookupPayload['idp_user_id'] ?? null;
        $this->assertIsString($idpUserId);
        $this->assertNotSame('', $idpUserId);
        $this->assertSame(IntegFixtures::PLAYER_EMAIL, $lookupPayload['email'] ?? null);

        $claimBody = [
            'idp_user_id' => $idpUserId,
            'provisos' => self::PROVISOS,
            'resource' => self::RESOURCE,
        ];
        $addClaim = $this->http->postJsonWithBasicAuth(
            '/resources/client/policy-claims',
            $claimBody,
            $clientId,
            $clientSecret,
        );
        $this->assertSame(204, $addClaim->getStatusCode(), (string) $addClaim->getBody());

        $listClaims = $this->http->getWithBasicAuth(
            '/resources/client/policy-claims/' . rawurlencode($idpUserId),
            $clientId,
            $clientSecret,
        );
        $this->assertSame(200, $listClaims->getStatusCode(), (string) $listClaims->getBody());
        /** @var array<string, mixed> $claimsPayload */
        $claimsPayload = json_decode((string) $listClaims->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $claims = $claimsPayload['claims'] ?? null;
        $this->assertIsArray($claims);
        $this->assertNotEmpty($claims);
        $first = $claims[0];
        $this->assertIsArray($first);
        $this->assertSame(self::IAM_SERVICE, $first['service'] ?? null);
        $this->assertSame(self::PROVISOS, $first['provisos'] ?? null);
        $this->assertSame(self::RESOURCE, $first['resource'] ?? null);

        $deleteClaim = $this->http->deleteJsonWithBasicAuth(
            '/resources/client/policy-claims',
            $claimBody,
            $clientId,
            $clientSecret,
        );
        $this->assertSame(204, $deleteClaim->getStatusCode(), (string) $deleteClaim->getBody());

        $listAfterDelete = $this->http->getWithBasicAuth(
            '/resources/client/policy-claims/' . rawurlencode($idpUserId),
            $clientId,
            $clientSecret,
        );
        $this->assertSame(200, $listAfterDelete->getStatusCode(), (string) $listAfterDelete->getBody());
        /** @var array<string, mixed> $emptyClaimsPayload */
        $emptyClaimsPayload = json_decode((string) $listAfterDelete->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $emptyClaimsPayload['claims'] ?? null);

        $loginId = IntegDb::passwordLoginIdForEmail(IntegFixtures::PLAYER_EMAIL);
        $metadataBody = [
            'idp_user_id' => $idpUserId,
            'login_id' => $loginId,
            'metadata' => ['role' => 'integ-editor', 'tier' => 2],
            'encoding' => 'json',
        ];
        $putMetadata = $this->http->putJsonWithBasicAuth(
            '/resources/client/user-metadata',
            $metadataBody,
            $clientId,
            $clientSecret,
        );
        $this->assertSame(204, $putMetadata->getStatusCode(), (string) $putMetadata->getBody());

        $getMetadata = $this->http->getWithBasicAuth(
            '/resources/client/user-metadata/' . rawurlencode($idpUserId) . '?login_id=' . $loginId,
            $clientId,
            $clientSecret,
        );
        $this->assertSame(200, $getMetadata->getStatusCode(), (string) $getMetadata->getBody());
        /** @var array<string, mixed> $metadataPayload */
        $metadataPayload = json_decode((string) $getMetadata->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('integ-editor', $metadataPayload['metadata']['role'] ?? null);

        $deleteMetadata = $this->http->deleteWithBasicAuth(
            '/resources/client/user-metadata/' . rawurlencode($idpUserId) . '?login_id=' . $loginId,
            $clientId,
            $clientSecret,
        );
        $this->assertSame(204, $deleteMetadata->getStatusCode(), (string) $deleteMetadata->getBody());

        $getMetadataMissing = $this->http->getWithBasicAuth(
            '/resources/client/user-metadata/' . rawurlencode($idpUserId) . '?login_id=' . $loginId,
            $clientId,
            $clientSecret,
        );
        $this->assertSame(404, $getMetadataMissing->getStatusCode(), (string) $getMetadataMissing->getBody());

        $formatGet = $this->http->getWithBasicAuth('/resources/client/service-format', $clientId, $clientSecret);
        $this->assertSame(200, $formatGet->getStatusCode(), (string) $formatGet->getBody());
        /** @var array<string, mixed> $formatPayload */
        $formatPayload = json_decode((string) $formatGet->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::IAM_SERVICE, $formatPayload['iam_service'] ?? null);
        $this->assertTrue($formatPayload['is_default'] ?? false);

        $createFormat = $this->http->postJsonWithBasicAuth(
            '/resources/client/service-format',
            ['service_format' => ['Configuration', 'Kingdom', 'EventInstance']],
            $clientId,
            $clientSecret,
        );
        $this->assertSame(204, $createFormat->getStatusCode(), (string) $createFormat->getBody());

        $duplicateFormat = $this->http->postJsonWithBasicAuth(
            '/resources/client/service-format',
            ['service_format' => ['Configuration', 'Kingdom']],
            $clientId,
            $clientSecret,
        );
        $this->assertSame(409, $duplicateFormat->getStatusCode(), (string) $duplicateFormat->getBody());

        $replaceFormat = $this->http->putJsonWithBasicAuth(
            '/resources/client/service-format',
            ['service_format' => ['Configuration', 'Game', 'Kingdom', 'Park']],
            $clientId,
            $clientSecret,
        );
        $this->assertSame(204, $replaceFormat->getStatusCode(), (string) $replaceFormat->getBody());

        $link = $this->http->postJsonWithBasicAuth(
            '/resources/link-ork-profile',
            ['idp_user_id' => $idpUserId, 'mundane_id' => self::MIRROR_MUNDANE_ID],
            $clientId,
            $clientSecret,
        );
        $this->assertSame(204, $link->getStatusCode(), (string) $link->getBody());

        $unlink = $this->http->postJsonWithBasicAuth(
            '/resources/unlink-ork-profile',
            ['idp_user_id' => $idpUserId],
            $clientId,
            $clientSecret,
        );
        $this->assertSame(204, $unlink->getStatusCode(), (string) $unlink->getBody());
    }

    public function testClientWithoutIamNamespaceGets403OnPolicyClaimsAnd200OnServiceFormatGet(): void
    {
        $clientId = IntegFixtures::NO_IAM_CLIENT_ID;
        $clientSecret = IntegFixtures::NO_IAM_CLIENT_SECRET;

        $policy = $this->http->postJsonWithBasicAuth(
            '/resources/client/policy-claims',
            [
                'idp_user_id' => '00000000-0000-0000-0000-000000000001',
                'provisos' => self::PROVISOS,
                'resource' => self::RESOURCE,
            ],
            $clientId,
            $clientSecret,
        );
        $this->assertSame(403, $policy->getStatusCode(), (string) $policy->getBody());

        $format = $this->http->getWithBasicAuth('/resources/client/service-format', $clientId, $clientSecret);
        $this->assertSame(200, $format->getStatusCode(), (string) $format->getBody());
    }
}
