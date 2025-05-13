<?php
declare(strict_types=1);

namespace Amtgard\IdP\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use DateTime;

/**
 * @ORM\Entity
 * @ORM\Table(name="oauth_clients")
 */
class Client
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=36)
     */
    private string $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private string $name;

    /**
     * @ORM\Column(type="string", length=255, unique=true)
     */
    private string $clientId;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private string $clientSecret;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private string $redirectUri;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $isConfidential;

    /**
     * @ORM\Column(type="array")
     */
    private array $allowedScopes = [];

    /**
     * @ORM\Column(type="array")
     */
    private array $allowedGrantTypes = [];

    /**
     * @ORM\Column(type="datetime")
     */
    private DateTime $createdAt;

    /**
     * @ORM\Column(type="datetime")
     */
    private DateTime $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::uuid4()->toString();
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function setClientId(string $clientId): self
    {
        $this->clientId = $clientId;
        return $this;
    }

    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    public function setClientSecret(string $clientSecret): self
    {
        $this->clientSecret = $clientSecret;
        return $this;
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function setRedirectUri(string $redirectUri): self
    {
        $this->redirectUri = $redirectUri;
        return $this;
    }

    public function isConfidential(): bool
    {
        return $this->isConfidential;
    }

    public function setIsConfidential(bool $isConfidential): self
    {
        $this->isConfidential = $isConfidential;
        return $this;
    }

    public function getAllowedScopes(): array
    {
        return $this->allowedScopes;
    }

    public function setAllowedScopes(array $allowedScopes): self
    {
        $this->allowedScopes = $allowedScopes;
        return $this;
    }

    public function getAllowedGrantTypes(): array
    {
        return $this->allowedGrantTypes;
    }

    public function setAllowedGrantTypes(array $allowedGrantTypes): self
    {
        $this->allowedGrantTypes = $allowedGrantTypes;
        return $this;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTime $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function updateTimestamp(): void
    {
        $this->updatedAt = new DateTime();
    }
}