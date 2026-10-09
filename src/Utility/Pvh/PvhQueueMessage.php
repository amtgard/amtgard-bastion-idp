<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Pvh;

use Amtgard\Traits\Builder\Builder;
use Amtgard\Traits\Builder\Getter;
use Optional\Optional;
use Psr\Log\LoggerInterface;

final class PvhQueueMessage
{
    use Builder;
    use Getter;

    public const REASON_INVALID_PAYLOAD = 'invalid_queue_payload';

    public const REASON_MALFORMED_JSON = 'malformed_json';

    protected string $userUuid;

    protected string $aud;

    protected ?string $requestId = null;

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public static function encode(
        string $userUuid,
        string $aud,
        ?string $requestId = null,
        ?LoggerInterface $logger = null,
    ): string {
        $payload = [
            'user_uuid' => $userUuid,
            'aud' => $aud,
        ];
        if ($requestId !== null && $requestId !== '') {
            $payload['request_id'] = $requestId;
        }

        if ($logger !== null) {
            $context = [
                'user_uuid' => $userUuid,
                'aud' => $aud,
            ];
            if ($requestId !== null && $requestId !== '') {
                $context['request_id'] = $requestId;
            }
            $logger->debug('pvh queue message encoded', $context);
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    public function publishKey(): string
    {
        return $this->userUuid . ':' . $this->aud;
    }

    public static function fromJson(string $message, ?LoggerInterface $logger = null): Optional
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
                if ($logger !== null) {
                    $logger->error('pvh queue message rejected', [
                        'reason' => self::REASON_INVALID_PAYLOAD,
                    ]);
                }

                return Optional::blank();
            }

            $builder = self::builder()
                ->userUuid($payload['user_uuid'])
                ->aud($payload['aud']);

            if (isset($payload['request_id']) && is_string($payload['request_id']) && $payload['request_id'] !== '') {
                $builder->requestId($payload['request_id']);
            }

            return Optional::of($builder->build());
        } catch (\Throwable $e) {
            if ($logger !== null) {
                $logger->error('pvh queue message parse failed', [
                    'exception_class' => $e::class,
                ]);
            }

            return Optional::blank();
        }
    }

    public static function classifyParseFailure(string $message): string
    {
        try {
            json_decode($message, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return self::REASON_MALFORMED_JSON;
        }

        return self::REASON_INVALID_PAYLOAD;
    }
}
