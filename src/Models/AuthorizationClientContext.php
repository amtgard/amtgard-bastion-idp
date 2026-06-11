<?php

declare(strict_types=1);

namespace Amtgard\IdP\Models;

final class AuthorizationClientContext
{
    public function __construct(
        public readonly ?int $forClientDbId,
    ) {}
}
