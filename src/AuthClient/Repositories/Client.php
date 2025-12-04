<?php
declare(strict_types=1);

namespace Amtgard\IdP\AuthClient\Repositories;

use Amtgard\ActiveRecordOrm\Configuration\DataAccessPolicy\UncachedDataAccessPolicy;
use Amtgard\ActiveRecordOrm\Entity\EntityMapper;
use Amtgard\ActiveRecordOrm\Repository\Database;
use Amtgard\ActiveRecordOrm\TableFactory;

class Client
{
    private EntityMapper $clientRepo;
    public function __construct(Database $database, UncachedDataAccessPolicy $tablePolicy) {
        $this->clientRepo = EntityMapper::builder()
            ->table(TableFactory::build($database, $tablePolicy, 'clients'))
            ->build();
    }

    public function findByClientId(string $clientId): ?Client {
        $this->clientRepo->clear();
        return $this->clientRepo->fetchBy('clientId', $clientId);
    }

}