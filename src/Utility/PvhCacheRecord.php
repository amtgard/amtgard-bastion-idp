<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility;

/**
 * Redis JSON value for pvh:{userUuid}:{aud}. Not serialized.
 */
final class PvhCacheRecord
{
    public function __construct(
        private readonly string $userUuid,
        private readonly string $aud,
        private readonly string $email,
        private readonly string $pvh,
        private readonly ?string $prevPvh,
    ) {
    }

    public function getUserUuid(): string
    {
        return $this->userUuid;
    }

    public function getAud(): string
    {
        return $this->aud;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPvh(): string
    {
        return $this->pvh;
    }

    public function getPrevPvh(): ?string
    {
        return $this->prevPvh;
    }

    /**
     * @return array{user_uuid: string, aud: string, email: string, pvh: string, prev_pvh: ?string}
     */
    public function toArray(): array
    {
        return [
            'user_uuid' => $this->userUuid,
            'aud' => $this->aud,
            'email' => $this->email,
            'pvh' => $this->pvh,
            'prev_pvh' => $this->prevPvh,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): ?self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        return self::fromArray($data);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        foreach (['user_uuid', 'aud', 'email', 'pvh'] as $required) {
            if (!isset($data[$required]) || !is_string($data[$required])) {
                return null;
            }
        }

        $prevPvh = $data['prev_pvh'] ?? null;
        if ($prevPvh !== null && !is_string($prevPvh)) {
            return null;
        }

        return new self(
            $data['user_uuid'],
            $data['aud'],
            $data['email'],
            $data['pvh'],
            $prevPvh,
        );
    }
}
