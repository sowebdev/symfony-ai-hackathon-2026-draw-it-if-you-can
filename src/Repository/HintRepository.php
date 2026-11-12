<?php

namespace App\Repository;

use App\Entity\Hint;
use App\Entity\Round;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Hint>
 */
class HintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Hint::class);
    }

    /**
     * @return list<Hint>
     */
    public function findByRound(Round $round): array
    {
        return $this->findBy(['round' => $round], ['id' => 'ASC']);
    }
}
