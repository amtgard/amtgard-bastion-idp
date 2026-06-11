<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Client;

use Amtgard\IdP\Utility\IamServiceFormatValidator;
use Amtgard\IdP\Utility\IamServiceValidator;

/**
 * Normalizes IAM admin form fields once so create/update stay aligned.
 */
final class ClientIamAdminInput
{
    public function __construct(
        public readonly ?string $iamService,
        public readonly ?string $iamServiceFormat,
    ) {}

    /**
     * @param array<string, mixed> $formData
     */
    public static function fromFormData(array $formData): self
    {
        return new self(
            IamServiceValidator::validate(
                isset($formData['iam_service']) ? trim((string) $formData['iam_service']) : null
            ),
            IamServiceFormatValidator::validate(
                isset($formData['iam_service_format']) ? trim((string) $formData['iam_service_format']) : null
            ),
        );
    }
}
