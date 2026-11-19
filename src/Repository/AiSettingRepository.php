<?php

namespace App\Repository;

use App\Entity\AiSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiSetting>
 */
class AiSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiSetting::class);
    }

    /**
     * The setting of the installation: there is one at most.
     */
    public function current(): ?AiSetting
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
