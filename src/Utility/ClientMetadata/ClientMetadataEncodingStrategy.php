<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\ClientMetadata;

interface ClientMetadataEncodingStrategy
{
    public function encodingName(): string;

    /**
     * @return array{payload: string, encoding: string}
     */
    public function prepare(mixed $metadata): array;
}
