<?php

namespace App\Repository;

use App\Entity\Game;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Game>
 */
class GameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Game::class);
    }

    /**
     * The game the agent plays when it names no round: the one whose page the player opened last,
     * among the games they did not leave, solo games included. When the player left them all, the
     * one opened last, so that the agent is told to stop. The id, a UUID v7, breaks a tie: the
     * dates keep the second only.
     */
    public function findToFollow(): ?Game
    {
        $query = fn (bool $open): ?Game => $this->createQueryBuilder('g')
            ->addSelect('COALESCE(g.openedAt, g.createdAt) AS HIDDEN lastOpenedAt')
            ->where($open ? 'g.leftAt IS NULL' : '1 = 1')
            ->orderBy('lastOpenedAt', 'DESC')
            ->addOrderBy('g.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $query(true) ?? $query(false);
    }
}
