<?php

namespace Amtgard\IdP\Persistence\Server\Entities;

use Amtgard\ActiveRecordOrm\Entity\Repository\RepositoryEntity;

trait SerializationTrait
{
    public function __serialize(): array {
        $vars = get_object_vars($this);
        foreach ($vars as $key => $var) {
            if ($var instanceof RepositoryEntity) {
                unset($vars[$key]);
            }
        }
        return $vars;
    }
}