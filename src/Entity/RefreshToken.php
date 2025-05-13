<?php
declare(strict_types=1);

namespace Amtgard\IdP\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use DateTime;

/**
 * @ORM\Entity
 * @ORM\Table(name="oauth_refresh_tokens")
 */
class RefreshToken
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=36)
     */
    private string $id;

    /**
     * @ORM\Column(type="string", length=100, unique=true)
     */
    private string $identifier;

    /**
     * @ORM\ManyToOne(targetEntity="AccessToken")
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private AccessToken $accessToken;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $revoked = false;

    /**
     * @ORM\Column(type="datetime")
     */
    private DateTime $expiresAt;

    /**
     * @ORM\Column(type="datetime")
     */
    private DateTime $createdAt;

    public function __construct()
    {
        $this->id = Uuid::uuid4()->toString();
        $this->createdAt = new DateTime();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): self
    {
        $this->identifier = $identifier;
        return $this;
    }

    public function getAccessToken(): AccessToken
    {
        return $this->accessToken;
    }

    public function setAccessToken(AccessToken $accessToken): self
    {
        $this->accessToken = $accessToken;
        return $this;
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    public function setRevoked(bool $revoked): self
    {
        $this->revoked = $revoked;
        return $this;
    }

    public function getExpiresAt(): DateTime
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(DateTime $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTime();
    }
}