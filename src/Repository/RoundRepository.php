<?php

namespace App\Repository;

use App\Entity\Game;
use App\Entity\Round;
use App\Game\Language;
use App\Game\Theme;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Round>
 */
class RoundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Round::class);
    }

    /**
     * The words drawn in the last rounds of a theme and a language, the most recent first.
     *
     * @return list<string>
     */
    public function recentTargetWords(Theme $theme, Language $language, int $limit): array
    {
        $words = $this->createQueryBuilder('r')
            ->select('r.targetWord')
            ->join('r.game', 'g')
            ->where('r.theme = :theme')
            ->andWhere('g.language = :language')
            ->setParameter('theme', $theme)
            ->setParameter('language', $language)
            ->orderBy('r.startedAtMs', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $word): string => (string) $word, $words));
    }

    /**
     * Marks the drawing of the AI as started, unless another request did it first: a single SQL
     * update, so two requests cannot both win.
     */
    public function claimAiDrawing(Round $round): bool
    {
        $claimed = $this->createQueryBuilder('r')
            ->update()
            ->set('r.aiDrawingStarted', ':started')
            ->where('r.id = :id')
            ->andWhere('r.aiDrawingStarted = :notStarted')
            ->setParameter('started', true)
            ->setParameter('notStarted', false)
            ->setParameter('id', $round->getId(), 'uuid')
            ->getQuery()
            ->execute();

        return 1 === $claimed;
    }

    /**
     * @return list<Round>
     */
    public function findByGame(Game $game): array
    {
        return $this->findBy(['game' => $game], ['number' => 'ASC']);
    }

    public function findLastOfGame(Game $game): ?Round
    {
        return $this->findOneBy(['game' => $game], ['number' => 'DESC']);
    }

    /**
     * The round created last: the one the player is playing, or has just played. The id, a UUID v7,
     * follows the order of creation, even for two rounds started in the same millisecond.
     */
    public function findLatest(): ?Round
    {
        return $this->findOneBy([], ['id' => 'DESC']);
    }
}
