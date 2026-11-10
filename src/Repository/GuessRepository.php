<?php

namespace App\Repository;

use App\Entity\Guess;
use App\Entity\Round;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Guess>
 */
class GuessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Guess::class);
    }

    /**
     * @return list<Guess>
     */
    public function findByRound(Round $round): array
    {
        return $this->findBy(['round' => $round], ['id' => 'ASC']);
    }
}
