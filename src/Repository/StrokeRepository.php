<?php

namespace App\Repository;

use App\Entity\Game;
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
    public function findByGame(Game $game): array
    {
        return $this->findBy(['game' => $game], ['position' => 'ASC']);
    }

    public function findLast(Game $game): ?Stroke
    {
        return $this->findOneBy(['game' => $game], ['position' => 'DESC']);
    }

    public function nextPosition(Game $game): int
    {
        return ($this->findLast($game)?->getPosition() ?? 0) + 1;
    }
}
