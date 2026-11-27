<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Persistence;

use Amtgard\ActiveRecordOrm\Schema\FieldDefinition;
use Amtgard\ActiveRecordOrm\Schema\FieldType;
use Amtgard\ActiveRecordOrm\Schema\TableSchema;

class AccessTokenRepositoryTestTableSchema extends TableSchema
{
    public function __construct()
    {
        $this->tableName = 'access_tokens';
        $this->fields = [
            'token_id' => FieldDefinition::builder()->name('token_id')->type(FieldType::STRING)->build(),
            'client_id' => FieldDefinition::builder()->name('client_id')->type(FieldType::INTEGER)->build(),
            'user_identifier' => FieldDefinition::builder()->name('user_identifier')->type(FieldType::STRING)->build(),
            'expiry_date_time' => FieldDefinition::builder()->name('expiry_date_time')
                ->type(FieldType::DATETIME)->build(),
            'client_secret' => FieldDefinition::builder()->name('client_secret')->type(FieldType::STRING)->build(),
            'name' => FieldDefinition::builder()->name('name')->type(FieldType::STRING)->build(),
            'redirect_uri' => FieldDefinition::builder()->name('redirect_uri')->type(FieldType::STRING)->build(),
            'is_confidential' => FieldDefinition::builder()->name('is_confidential')->type(FieldType::BOOL)->build(),
        ];
        $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::INTEGER)->build();
    }
}
