<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Models\Oidc;

use Amtgard\IdP\Models\Oidc\OidcNonceContext;
use PHPUnit\Framework\TestCase;

class OidcNonceContextTest extends TestCase
{
    public function testStartsEmptyThenStoresAndClears(): void
    {
        $context = new OidcNonceContext();

        $this->assertNull($context->get());

        $context->set('abc');
        $this->assertSame('abc', $context->get());

        $context->clear();
        $this->assertNull($context->get());
    }

    public function testSetReplacesPreviousValue(): void
    {
        $context = new OidcNonceContext();
        $context->set('first');
        $context->set('second');

        $this->assertSame('second', $context->get());
    }
}
