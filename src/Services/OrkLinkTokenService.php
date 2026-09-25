<?php

declare(strict_types=1);

namespace Amtgard\IdP\Services;

use Amtgard\ActiveRecordOrm\Repository\Database;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Optional\Optional;
use Psr\Log\LoggerInterface;

/**
 * Verifies short-lived HS256 JWTs minted by ORK3 for the /auth/connect handoff.
 * Records the jti in link_token_jti to make tokens single-use even within their
 * 15-minute validity window.
 */
class OrkLinkTokenService
{
    public function __construct(
        private Database $database,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve and validate the HS256 shared secret at the point of use rather
     * than at container-build time, so a misconfigured secret surfaces as a
     * clear runtime error from this service instead of an opaque DI failure.
     *
     * Canonical env var is IDP_ORK_SHARED_SECRET (matches ORK's resolver);
     * ORK_LINK_TOKEN_SECRET is retained as a transition-period fallback.
     */
    private function sharedSecret(): string
    {
        $secret = $_ENV['IDP_ORK_SHARED_SECRET'] ?? $_ENV['ORK_LINK_TOKEN_SECRET'] ?? '';
        if (strlen($secret) < 32) {
            throw new \RuntimeException('IDP_ORK_SHARED_SECRET (or legacy ORK_LINK_TOKEN_SECRET) is unset or shorter than 32 chars');
        }

        return $secret;
    }

    /**
     * Decode a Flow B handoff JWT without consuming jti.
     * `idp_email` is a destination hint only — it is not a bind key.
     *
     * @return array{mundane_id: int, idp_email: string, challenge_id: string, jti: string}|null
     */
    public function peekClaims(string $jwt): ?array
    {
        return Optional::ofNullable($this->decode($jwt, 'ork', 'idp'))
            ->map(function (object $decoded) {
                $idpEmail = (string) ($decoded->idp_email ?? $decoded->email ?? '');
                $validSub = isset($decoded->sub) && ctype_digit((string) $decoded->sub) && (int) $decoded->sub > 0;
                $validHints = !empty($decoded->jti) && !empty($decoded->challenge_id) && $idpEmail !== '';
                if (!$validSub || !$validHints) {
                    $this->logger->warning('OrkLinkToken missing required claim');

                    return null;
                }

                return [
                    'mundane_id' => (int) $decoded->sub,
                    'idp_email' => $idpEmail,
                    'challenge_id' => (string) $decoded->challenge_id,
                    'jti' => (string) $decoded->jti,
                ];
            })
            ->orElse(null);
    }

    /**
     * Decode the handoff ORK mints today: `email` plus `sub`, no `challenge_id`.
     *
     * @return array{mundane_id: int, email: string, jti: string}|null
     */
    public function peekLegacyClaims(string $jwt): ?array
    {
        return Optional::ofNullable($this->decode($jwt, 'ork', 'idp'))
            ->map(function (object $decoded) {
                $email = (string) ($decoded->email ?? '');
                $validSub = isset($decoded->sub) && ctype_digit((string) $decoded->sub) && (int) $decoded->sub > 0;
                if (!$validSub || $email === '' || empty($decoded->jti)) {
                    $this->logger->warning('OrkLinkToken missing required claim');

                    return null;
                }

                return [
                    'mundane_id' => (int) $decoded->sub,
                    'email' => $email,
                    'jti' => (string) $decoded->jti,
                ];
            })
            ->orElse(null);
    }

    /**
     * Completion JWT the current ORK `idp_link_complete` route accepts.
     * No `challenge_id`. The possession flow uses `mintFlowBCompletion`.
     */
    public function mintLegacyCompletion(string $idpUserId, int $mundaneId): string
    {
        $now = time();

        return $this->encode([
            'iss' => 'idp',
            'aud' => 'ork',
            'sub' => $idpUserId,
            'mundane_id' => $mundaneId,
            'iat' => $now,
            'exp' => $now + 300,
            'jti' => bin2hex(random_bytes(18)),
        ]);
    }

    /**
     * Decode a Flow A completion JWT (ORK → IDP) without consuming jti.
     *
     * @return array{challenge_id: string, idp_user_id: string, mundane_id: int, purpose: string, jti: string}|null
     */
    public function peekFlowACompletion(string $jwt): ?array
    {
        return Optional::ofNullable($this->decode($jwt, 'ork', 'idp'))
            ->filter(fn (object $decoded) => !empty($decoded->challenge_id) && !empty($decoded->idp_user_id) && !empty($decoded->jti))
            ->filter(fn (object $decoded) => ($decoded->purpose ?? null) === 'claim_ork')
            ->filter(fn (object $decoded) => isset($decoded->mundane_id) && (int) $decoded->mundane_id > 0)
            ->map(fn (object $decoded) => [
                'challenge_id' => (string) $decoded->challenge_id,
                'idp_user_id' => (string) $decoded->idp_user_id,
                'mundane_id' => (int) $decoded->mundane_id,
                'purpose' => 'claim_ork',
                'jti' => (string) $decoded->jti,
            ])
            ->orElse(null);
    }

    public function mintFlowAHandoff(string $idpUserId, string $challengeId): string
    {
        $now = time();

        return $this->encode([
            'iss' => 'idp',
            'aud' => 'ork',
            'sub' => $idpUserId,
            'challenge_id' => $challengeId,
            'jti' => bin2hex(random_bytes(18)),
            'iat' => $now,
            'exp' => $now + 900,
        ]);
    }

    public function mintFlowBCompletion(string $idpUserId, int $mundaneId, string $challengeId): string
    {
        $now = time();

        return $this->encode([
            'iss' => 'idp',
            'aud' => 'ork',
            'sub' => $idpUserId,
            'idp_user_id' => $idpUserId,
            'mundane_id' => $mundaneId,
            'challenge_id' => $challengeId,
            'purpose' => 'claim_idp',
            'jti' => bin2hex(random_bytes(18)),
            'iat' => $now,
            'exp' => $now + 120,
        ]);
    }

    public function orkBaseUrl(): string
    {
        $raw = rtrim((string) ($_ENV['ORK_BASE_URL'] ?? ''), '/');
        if ($raw === '') {
            throw new \RuntimeException('ORK_BASE_URL is not set');
        }
        $parts = parse_url($raw);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException('ORK_BASE_URL is not a valid absolute URL: ' . $raw);
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \RuntimeException('ORK_BASE_URL scheme must be http or https: ' . $raw);
        }

        return $raw;
    }

    public function flowAClaimRedirectUrl(string $jwt, string $username): string
    {
        return $this->orkBaseUrl()
            . '/index.php?Route=Login/claim_ork&t=' . urlencode($jwt)
            . '&username=' . urlencode($username);
    }

    public function flowBCompletionRedirectUrl(string $jwt): string
    {
        return $this->orkBaseUrl() . '/index.php?Route=Login/idp_link_complete&t=' . urlencode($jwt);
    }

    public function hasSharedSecret(): bool
    {
        $secret = $_ENV['IDP_ORK_SHARED_SECRET'] ?? $_ENV['ORK_LINK_TOKEN_SECRET'] ?? '';

        return strlen((string) $secret) >= 32;
    }

    /**
     * Idempotently record the jti. Returns false if already seen (replay).
     */
    public function consumeJti(string $jti): bool
    {
        try {
            $this->database->clear();
            $this->database->__set('jti', $jti);
            $this->database->execute("INSERT INTO link_token_jti (jti, seen_at) VALUES (:jti, NOW())");

            return true;
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate')) {
                $this->logger->warning('OrkLinkToken replay', ['jti' => $jti]);

                return false;
            }
            throw $e;
        }
    }

    /**
     * Best-effort cleanup of consumed jti rows older than 1 hour. Safe to call
     * from a periodic management endpoint or cron.
     */
    public function cleanExpiredJti(): void
    {
        try {
            $this->database->clear();
            $this->database->execute("DELETE FROM link_token_jti WHERE seen_at < (NOW() - INTERVAL 1 HOUR)");
        } catch (\Throwable $e) {
            $this->logger->warning('cleanExpiredJti failed', ['msg' => $e->getMessage()]);
        }
    }

    private function decode(string $jwt, string $iss, string $aud): ?object
    {
        JWT::$leeway = 30;
        try {
            $decoded = JWT::decode($jwt, new Key($this->sharedSecret(), 'HS256'));
        } catch (ExpiredException $e) {
            $this->logger->info('OrkLinkToken expired', ['msg' => $e->getMessage()]);

            return null;
        } catch (SignatureInvalidException $e) {
            $this->logger->warning('OrkLinkToken bad signature', ['msg' => $e->getMessage()]);

            return null;
        } catch (\Throwable $e) {
            $this->logger->info('OrkLinkToken decode error', ['msg' => $e->getMessage()]);

            return null;
        }

        return Optional::of($decoded)
            ->filter(fn (object $token) => ($token->iss ?? '') === $iss)
            ->filter(fn (object $token) => ($token->aud ?? '') === $aud)
            ->orElseGet(function () use ($decoded, $iss, $aud) {
                if (($decoded->iss ?? '') !== $iss) {
                    $this->logger->warning('OrkLinkToken wrong iss', ['iss' => $decoded->iss ?? 'null']);
                } else {
                    $this->logger->warning('OrkLinkToken wrong aud', ['aud' => $decoded->aud ?? 'null']);
                }

                return null;
            });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return JWT::encode($payload, $this->sharedSecret(), 'HS256');
    }
}
