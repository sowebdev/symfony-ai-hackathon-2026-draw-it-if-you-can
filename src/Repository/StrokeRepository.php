<?php

namespace App\Repository;

use App\Entity\Round;
use App\Entity\Stroke;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Stroke>
 */
class StrokeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stroke::class);
    }

    /**
     * @return list<Stroke>
     */
    public function findByRound(Round $round): array
    {
        return $this->findBy(['round' => $round], ['position' => 'ASC']);
    }

    public function findLast(Round $round): ?Stroke
    {
        return $this->findOneBy(['round' => $round], ['position' => 'DESC']);
    }

    public function nextPosition(Round $round): int
    {
        return ($this->findLast($round)?->getPosition() ?? 0) + 1;
    }
}
