<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Pvh;

use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use Optional\Optional;

final class PvhQueueMessage
{
    use Builder;
    use Getter;

    protected string $userUuid;

    protected string $aud;

    public static function encode(string $userUuid, string $aud): string
    {
        return json_encode([
            'user_uuid' => $userUuid,
            'aud' => $aud,
        ], JSON_THROW_ON_ERROR);
    }

    public function publishKey(): string
    {
        return $this->userUuid . ':' . $this->aud;
    }

    public static function fromJson(string $message): Optional
    {
        try {
            $payload = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)
                || !isset($payload['user_uuid'], $payload['aud'])
                || !is_string($payload['user_uuid'])
                || !is_string($payload['aud'])
                || $payload['user_uuid'] === ''
                || $payload['aud'] === ''
            ) {
                return Optional::blank();
            }

            return Optional::of(
                self::builder()
                    ->userUuid($payload['user_uuid'])
                    ->aud($payload['aud'])
                    ->build()
            );
        } catch (\Throwable) {
            return Optional::blank();
        }
    }
}
