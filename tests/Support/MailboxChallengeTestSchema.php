<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Support;

use Amtgard\ActiveRecordOrm\Schema\FieldDefinition;
use Amtgard\ActiveRecordOrm\Schema\FieldType;
use Amtgard\ActiveRecordOrm\Schema\TableSchema;

final class MailboxChallengeTestSchema extends TableSchema
{
    public function __construct()
    {
        $this->tableName = 'mailbox_challenges';
        $this->fields = [];
        foreach ([
            'id' => FieldType::STRING,
            'purpose' => FieldType::STRING,
            'idp_user_id' => FieldType::STRING,
            'mundane_id' => FieldType::INTEGER,
            'code_hash' => FieldType::STRING,
            'sent_to_hash' => FieldType::STRING,
            'new_email' => FieldType::STRING,
            'attempts' => FieldType::INTEGER,
            'send_count' => FieldType::INTEGER,
            'stage' => FieldType::STRING,
            'expires_at' => FieldType::DATETIME,
            'consumed_at' => FieldType::DATETIME,
            'created_at' => FieldType::DATETIME,
        ] as $name => $type) {
            $this->fields[$name] = FieldDefinition::builder()->name($name)->type($type)->build();
        }
        $this->primaryKey = FieldDefinition::builder()->name('id')->type(FieldType::STRING)->build();
    }
}
