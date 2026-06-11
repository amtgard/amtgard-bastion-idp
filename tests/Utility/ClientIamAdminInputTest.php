<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Utility;

use Amtgard\IdP\Utility\Client\ClientIamAdminInput;
use PHPUnit\Framework\TestCase;

class ClientIamAdminInputTest extends TestCase
{
    public function testFromFormDataNormalizesIamServiceAndFormat(): void
    {
        $input = ClientIamAdminInput::fromFormData([
            'iam_service' => ' Skbc ',
            'iam_service_format' => ' ["tenant-id","Kingdom"] ',
        ]);

        $this->assertSame('Skbc', $input->iamService);
        $this->assertSame('["tenant-id","Kingdom"]', $input->iamServiceFormat);
    }

    public function testFromFormDataTreatsMissingFormatAsNull(): void
    {
        $input = ClientIamAdminInput::fromFormData([
            'iam_service' => 'Skbc',
        ]);

        $this->assertNull($input->iamServiceFormat);
    }

    public function testFromFormDataRejectsInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientIamAdminInput::fromFormData([
            'iam_service' => 'Skbc',
            'iam_service_format' => '["Configuration",""]',
        ]);
    }
}
