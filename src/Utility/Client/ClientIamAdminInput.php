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
                self::formatText(
                    isset($formData['iam_service_format']) ? trim((string) $formData['iam_service_format']) : null
                )
            ),
        );
    }

    /**
     * The admin field accepts a JSON array or a comma-separated slot list.
     * Stored values stay a JSON array.
     */
    private static function formatText(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $trimmed = ltrim($raw);
        if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
            return $raw;
        }

        return json_encode(array_map('trim', explode(',', $raw)), JSON_THROW_ON_ERROR);
    }
}
