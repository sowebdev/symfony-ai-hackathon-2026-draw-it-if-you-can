<?php

namespace App\Repository;

use App\Entity\GameSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameSetting>
 */
class GameSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameSetting::class);
    }

    public function row(): ?GameSetting
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
