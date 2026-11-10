<?php

namespace App\Repository;

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
        return $this->createQueryBuilder('r')
            ->select('r.targetWord')
            ->join('r.game', 'g')
            ->where('g.theme = :theme')
            ->andWhere('g.language = :language')
            ->setParameter('theme', $theme)
            ->setParameter('language', $language)
            ->orderBy('r.startedAtMs', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();
    }
}
