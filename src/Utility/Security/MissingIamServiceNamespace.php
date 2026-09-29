<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

/**
 * Valid confidential credentials, but the client has no iam_service namespace.
 * The IAM middleware turns this into a 403 JSON body instead of an uncaught HTTP exception.
 */
final class MissingIamServiceNamespace extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Client is not configured with an IAM service namespace.');
    }
}
