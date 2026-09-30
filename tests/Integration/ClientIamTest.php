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
        $this->http = new IntegHttp($this->integBaseUrl());
    }

    public function testLookupPlayerByEmailReturnsIdpUserIdAndEmail(): void
    {
        $lookup = $this->lookupPlayerByEmail();
        $this->assertSame(200, $lookup->getStatusCode(), (string) $lookup->getBody());
        /** @var array<string, mixed> $lookupPayload */
        $lookupPayload = json_decode((string) $lookup->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $idpUserId = $lookupPayload['idp_user_id'] ?? null;
        $this->assertIsString($idpUserId);
        $this->assertNotSame('', $idpUserId);
        $this->assertSame(IntegFixtures::PLAYER_EMAIL, $lookupPayload['email'] ?? null);
    }

    public function testAddPolicyClaimReturns204(): void
    {
        $idpUserId = $this->resolvePlayerIdpUserId();
        $addClaim = $this->http->postJsonWithBasicAuth(
            '/resources/client/policy-claims',
            $this->claimBody($idpUserId),
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
        $this->assertSame(204, $addClaim->getStatusCode(), (string) $addClaim->getBody());
    }

    public function testListPolicyClaimsReturnsAddedClaimFields(): void
    {
        $idpUserId = $this->resolvePlayerIdpUserId();
        $this->addPolicyClaim($idpUserId);

        $listClaims = $this->http->getWithBasicAuth(
            '/resources/client/policy-claims/' . rawurlencode($idpUserId),
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
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
    }

    public function testDeletePolicyClaimReturns204(): void
    {
        $idpUserId = $this->resolvePlayerIdpUserId();
        $this->addPolicyClaim($idpUserId);

        $deleteClaim = $this->http->deleteJsonWithBasicAuth(
            '/resources/client/policy-claims',
            $this->claimBody($idpUserId),
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
        $this->assertSame(204, $deleteClaim->getStatusCode(), (string) $deleteClaim->getBody());
    }

    public function testListPolicyClaimsEmptyAfterDelete(): void
    {
        $idpUserId = $this->resolvePlayerIdpUserId();
        $this->addPolicyClaim($idpUserId);
        $this->deletePolicyClaim($idpUserId);

        $listAfterDelete = $this->http->getWithBasicAuth(
            '/resources/client/policy-claims/' . rawurlencode($idpUserId),
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
        $this->assertSame(200, $listAfterDelete->getStatusCode(), (string) $listAfterDelete->getBody());
        /** @var array<string, mixed> $emptyClaimsPayload */
        $emptyClaimsPayload = json_decode((string) $listAfterDelete->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $emptyClaimsPayload['claims'] ?? null);
    }

    public function testPutUserMetadataReturns204AndGetReturnsRole(): void
    {
        $idpUserId = $this->resolvePlayerIdpUserId();
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
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
        $this->assertSame(204, $putMetadata->getStatusCode(), (string) $putMetadata->getBody());

        $getMetadata = $this->http->getWithBasicAuth(
            '/resources/client/user-metadata/' . rawurlencode($idpUserId) . '?login_id=' . $loginId,
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
        $this->assertSame(200, $getMetadata->getStatusCode(), (string) $getMetadata->getBody());
        /** @var array<string, mixed> $metadataPayload */
        $metadataPayload = json_decode((string) $getMetadata->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('integ-editor', $metadataPayload['metadata']['role'] ?? null);
    }

    public function testDeleteUserMetadataReturns404OnSubsequentGet(): void
    {
        $idpUserId = $this->resolvePlayerIdpUserId();
        $loginId = IntegDb::passwordLoginIdForEmail(IntegFixtures::PLAYER_EMAIL);
        $this->http->putJsonWithBasicAuth(
            '/resources/client/user-metadata',
            [
                'idp_user_id' => $idpUserId,
                'login_id' => $loginId,
                'metadata' => ['role' => 'integ-editor', 'tier' => 2],
                'encoding' => 'json',
            ],
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );

        $deleteMetadata = $this->http->deleteWithBasicAuth(
            '/resources/client/user-metadata/' . rawurlencode($idpUserId) . '?login_id=' . $loginId,
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
        $this->assertSame(204, $deleteMetadata->getStatusCode(), (string) $deleteMetadata->getBody());

        $getMetadataMissing = $this->http->getWithBasicAuth(
            '/resources/client/user-metadata/' . rawurlencode($idpUserId) . '?login_id=' . $loginId,
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
        $this->assertSame(404, $getMetadataMissing->getStatusCode(), (string) $getMetadataMissing->getBody());
    }

    public function testGetServiceFormatReturnsDefaultIamService(): void
    {
        $formatGet = $this->http->getWithBasicAuth(
            '/resources/client/service-format',
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
        $this->assertSame(200, $formatGet->getStatusCode(), (string) $formatGet->getBody());
        /** @var array<string, mixed> $formatPayload */
        $formatPayload = json_decode((string) $formatGet->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::IAM_SERVICE, $formatPayload['iam_service'] ?? null);
        $this->assertTrue($formatPayload['is_default'] ?? false);
    }

    public function testCreateServiceFormatReturns204(): void
    {
        $createFormat = $this->http->postJsonWithBasicAuth(
            '/resources/client/service-format',
            ['service_format' => ['Configuration', 'Kingdom', 'EventInstance']],
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
        $this->assertSame(204, $createFormat->getStatusCode(), (string) $createFormat->getBody());
    }

    public function testDuplicateServiceFormatPostReturns409(): void
    {
        $this->createServiceFormat(['Configuration', 'Kingdom', 'EventInstance']);

        $duplicateFormat = $this->http->postJsonWithBasicAuth(
            '/resources/client/service-format',
            ['service_format' => ['Configuration', 'Kingdom']],
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
        $this->assertSame(409, $duplicateFormat->getStatusCode(), (string) $duplicateFormat->getBody());
    }

    public function testReplaceServiceFormatReturns204(): void
    {
        $this->createServiceFormat(['Configuration', 'Kingdom', 'EventInstance']);

        $replaceFormat = $this->http->putJsonWithBasicAuth(
            '/resources/client/service-format',
            ['service_format' => ['Configuration', 'Game', 'Kingdom', 'Park']],
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
        $this->assertSame(204, $replaceFormat->getStatusCode(), (string) $replaceFormat->getBody());
    }

    public function testLinkOrkProfileReturns204(): void
    {
        $idpUserId = $this->resolvePlayerIdpUserId();
        $link = $this->http->postJsonWithBasicAuth(
            '/resources/link-ork-profile',
            ['idp_user_id' => $idpUserId, 'mundane_id' => self::MIRROR_MUNDANE_ID],
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
        $this->assertSame(204, $link->getStatusCode(), (string) $link->getBody());
    }

    public function testUnlinkOrkProfileReturns204(): void
    {
        $idpUserId = $this->resolvePlayerIdpUserId();
        $this->http->postJsonWithBasicAuth(
            '/resources/link-ork-profile',
            ['idp_user_id' => $idpUserId, 'mundane_id' => self::MIRROR_MUNDANE_ID],
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );

        $unlink = $this->http->postJsonWithBasicAuth(
            '/resources/unlink-ork-profile',
            ['idp_user_id' => $idpUserId],
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
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

    private function lookupPlayerByEmail(): \Psr\Http\Message\ResponseInterface
    {
        return $this->http->getWithBasicAuth(
            '/resources/client/users/by-email?email=' . rawurlencode(IntegFixtures::PLAYER_EMAIL),
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
    }

    private function resolvePlayerIdpUserId(): string
    {
        $lookup = $this->lookupPlayerByEmail();
        $this->assertSame(200, $lookup->getStatusCode(), (string) $lookup->getBody());
        /** @var array<string, mixed> $lookupPayload */
        $lookupPayload = json_decode((string) $lookup->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $idpUserId = $lookupPayload['idp_user_id'] ?? null;
        $this->assertIsString($idpUserId);
        $this->assertNotSame('', $idpUserId);

        return $idpUserId;
    }

    /**
     * @return array{idp_user_id: string, provisos: string, resource: string}
     */
    private function claimBody(string $idpUserId): array
    {
        return [
            'idp_user_id' => $idpUserId,
            'provisos' => self::PROVISOS,
            'resource' => self::RESOURCE,
        ];
    }

    private function addPolicyClaim(string $idpUserId): void
    {
        $addClaim = $this->http->postJsonWithBasicAuth(
            '/resources/client/policy-claims',
            $this->claimBody($idpUserId),
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
        $this->assertSame(204, $addClaim->getStatusCode(), (string) $addClaim->getBody());
    }

    private function deletePolicyClaim(string $idpUserId): void
    {
        $deleteClaim = $this->http->deleteJsonWithBasicAuth(
            '/resources/client/policy-claims',
            $this->claimBody($idpUserId),
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
        $this->assertSame(204, $deleteClaim->getStatusCode(), (string) $deleteClaim->getBody());
    }

    /**
     * @param list<string> $serviceFormat
     */
    private function createServiceFormat(array $serviceFormat): void
    {
        $createFormat = $this->http->postJsonWithBasicAuth(
            '/resources/client/service-format',
            ['service_format' => $serviceFormat],
            IntegFixtures::CONFIDENTIAL_CLIENT_ID,
            IntegFixtures::CONFIDENTIAL_CLIENT_SECRET,
        );
        $this->assertSame(204, $createFormat->getStatusCode(), (string) $createFormat->getBody());
    }
}
