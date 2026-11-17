<?php

namespace App\Repository;

use App\Entity\Round;
use App\Entity\Tip;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tip>
 */
class TipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tip::class);
    }

    /**
     * @return list<Tip>
     */
    public function findByRound(Round $round): array
    {
        return $this->findBy(['round' => $round], ['id' => 'ASC']);
    }
}
