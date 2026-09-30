<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Integration;

use Amtgard\IdP\Tests\Integration\Support\IntegDb;
use Amtgard\IdP\Tests\Integration\Support\IntegHttp;

/** Mode B — confidential client IAM validation and cross-client isolation. */
final class ClientIamNegativesTest extends IntegTestCase
{
    private const PROVISOS = ':0::::';
    private const RESOURCE = 'IntegIam/Grant';

    private IntegHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $baseUrl = (string) (getenv('IDP_BASE_URL') ?: 'http://localhost:37080');
        $this->http = new IntegHttp($baseUrl);
    }

    public function testAddPolicyClaimRejectsMalformedProvisos(): void
    {
        $clientId = IntegFixtures::CONFIDENTIAL_CLIENT_ID;
        $clientSecret = IntegFixtures::CONFIDENTIAL_CLIENT_SECRET;
        $idpUserId = $this->resolvePlayerIdpUserId($clientId, $clientSecret);

        $response = $this->http->postJsonWithBasicAuth(
            '/resources/client/policy-claims',
            [
                'idp_user_id' => $idpUserId,
                'provisos' => 'bad',
                'resource' => self::RESOURCE,
            ],
            $clientId,
            $clientSecret,
        );
        $this->assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('Invalid ORN claim', (string) $response->getBody());
    }

    public function testUserMetadataPutRejectsLoginIdThatBelongsToAnotherUser(): void
    {
        $clientId = IntegFixtures::CONFIDENTIAL_CLIENT_ID;
        $clientSecret = IntegFixtures::CONFIDENTIAL_CLIENT_SECRET;
        $idpUserId = $this->resolvePlayerIdpUserId($clientId, $clientSecret);
        $adminLoginId = IntegDb::passwordLoginIdForEmail(IntegFixtures::ADMIN_EMAIL);

        $response = $this->http->putJsonWithBasicAuth(
            '/resources/client/user-metadata',
            [
                'idp_user_id' => $idpUserId,
                'login_id' => $adminLoginId,
                'metadata' => ['role' => 'should-not-stick'],
                'encoding' => 'json',
            ],
            $clientId,
            $clientSecret,
        );
        $this->assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('unknown login_id for user', (string) $response->getBody());
    }

    public function testPeerClientCannotSeePrimaryClientPolicyClaimsOrMetadata(): void
    {
        $primaryId = IntegFixtures::CONFIDENTIAL_CLIENT_ID;
        $primarySecret = IntegFixtures::CONFIDENTIAL_CLIENT_SECRET;
        $peerId = IntegFixtures::PEER_IAM_CLIENT_ID;
        $peerSecret = IntegFixtures::PEER_IAM_CLIENT_SECRET;

        $idpUserId = $this->resolvePlayerIdpUserId($primaryId, $primarySecret);
        $loginId = IntegDb::passwordLoginIdForEmail(IntegFixtures::PLAYER_EMAIL);

        $addClaim = $this->http->postJsonWithBasicAuth(
            '/resources/client/policy-claims',
            [
                'idp_user_id' => $idpUserId,
                'provisos' => self::PROVISOS,
                'resource' => self::RESOURCE,
            ],
            $primaryId,
            $primarySecret,
        );
        $this->assertSame(204, $addClaim->getStatusCode(), (string) $addClaim->getBody());

        $primaryList = $this->http->getWithBasicAuth(
            '/resources/client/policy-claims/' . rawurlencode($idpUserId),
            $primaryId,
            $primarySecret,
        );
        $this->assertSame(200, $primaryList->getStatusCode(), (string) $primaryList->getBody());
        /** @var array<string, mixed> $primaryClaimsPayload */
        $primaryClaimsPayload = json_decode((string) $primaryList->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotSame([], $primaryClaimsPayload['claims'] ?? null);

        $peerList = $this->http->getWithBasicAuth(
            '/resources/client/policy-claims/' . rawurlencode($idpUserId),
            $peerId,
            $peerSecret,
        );
        $this->assertSame(200, $peerList->getStatusCode(), (string) $peerList->getBody());
        /** @var array<string, mixed> $peerClaimsPayload */
        $peerClaimsPayload = json_decode((string) $peerList->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $peerClaimsPayload['claims'] ?? null);

        $putMetadata = $this->http->putJsonWithBasicAuth(
            '/resources/client/user-metadata',
            [
                'idp_user_id' => $idpUserId,
                'login_id' => $loginId,
                'metadata' => ['role' => 'primary-only'],
                'encoding' => 'json',
            ],
            $primaryId,
            $primarySecret,
        );
        $this->assertSame(204, $putMetadata->getStatusCode(), (string) $putMetadata->getBody());

        $primaryMetadata = $this->http->getWithBasicAuth(
            '/resources/client/user-metadata/' . rawurlencode($idpUserId) . '?login_id=' . $loginId,
            $primaryId,
            $primarySecret,
        );
        $this->assertSame(200, $primaryMetadata->getStatusCode(), (string) $primaryMetadata->getBody());

        $peerMetadata = $this->http->getWithBasicAuth(
            '/resources/client/user-metadata/' . rawurlencode($idpUserId) . '?login_id=' . $loginId,
            $peerId,
            $peerSecret,
        );
        $this->assertSame(404, $peerMetadata->getStatusCode(), (string) $peerMetadata->getBody());
    }

    private function resolvePlayerIdpUserId(string $clientId, string $clientSecret): string
    {
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

        return $idpUserId;
    }
}
