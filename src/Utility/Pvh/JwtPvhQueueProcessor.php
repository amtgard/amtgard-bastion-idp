<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Pvh;

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Services\JwtPvhRefreshService;
use Amtgard\IdP\Utility\Log\RequestLogContext;
use Amtgard\SetQueue\PubSubQueue;
use Psr\Log\LoggerInterface;
use Throwable;

/** Pub/sub callback body for `bin/jwt-pvh-worker.php` (testable log branches). */
final class JwtPvhQueueProcessor
{
    /** @param (callable(): void)|null $clearIdentityMap CLI worker clears AARO; tests may pass a no-op. */
    public function __construct(
        private JwtPvhRefreshService $service,
        private PubSubQueue $pubSub,
        private string $queueName,
        private LoggerInterface $logger,
        private string $jobId,
        private mixed $clearIdentityMap = null,
    ) {
    }

    public function onMessage(mixed $key, mixed $message): void
    {
        $parsed = PvhQueueMessage::fromJson((string) $message, $this->logger);
        if (!$parsed->isPresent()) {
            $this->logger->error('jwt pvh worker dropped malformed message', [
                'key' => $key,
                'queue' => $this->queueName,
                'parse_reason' => PvhQueueMessage::classifyParseFailure((string) $message),
                'job_id' => $this->jobId,
            ]);

            return;
        }

        $queueMessage = $parsed->get();
        $requestId = $queueMessage->getRequestId();
        if ($requestId !== null && $requestId !== '') {
            RequestLogContext::set($requestId);
        }

        try {
            $dequeueContext = [
                'key' => $key,
                'user_uuid' => $queueMessage->getUserUuid(),
                'aud' => $queueMessage->getAud(),
                'queue' => $this->queueName,
                'job_id' => $this->jobId,
            ];
            if ($requestId !== null && $requestId !== '') {
                $dequeueContext['request_id'] = $requestId;
            }
            $this->logger->notice('jwt pvh worker dequeued', $dequeueContext);
            $this->clearAaroIdentityMap();
            $this->service->refresh($queueMessage->getUserUuid(), $queueMessage->getAud());
        } catch (Throwable $e) {
            $this->logger->critical('jwt pvh worker job failed; re-publishing', [
                'key' => $key,
                'user_uuid' => $queueMessage->getUserUuid(),
                'aud' => $queueMessage->getAud(),
                'queue' => $this->queueName,
                'job_id' => $this->jobId,
                'exception_class' => $e::class,
            ]);
            $this->pubSub->publish(
                $this->queueName,
                $queueMessage->publishKey(),
                (string) $message
            );
        } finally {
            if ($requestId !== null && $requestId !== '') {
                RequestLogContext::reset();
            }
        }
    }

    private function clearAaroIdentityMap(): void
    {
        if ($this->clearIdentityMap !== null) {
            ($this->clearIdentityMap)();

            return;
        }

        EntityManager::getManager()->clearAll();
    }
}
