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
     * @param bool $fresh Read again the words already loaded: other requests judge them
     *
     * @return list<Guess>
     */
    public function findByRound(Round $round, bool $fresh = false): array
    {
        $guesses = $this->findBy(['round' => $round], ['id' => 'ASC']);
        if ($fresh) {
            foreach ($guesses as $guess) {
                $this->getEntityManager()->refresh($guess);
            }
        }

        return $guesses;
    }
}
