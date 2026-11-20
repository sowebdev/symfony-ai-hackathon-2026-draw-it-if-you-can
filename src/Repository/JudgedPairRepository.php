<?php

namespace App\Repository;

use App\Ai\Link;
use App\Entity\JudgedPair;
use App\Game\Language;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JudgedPair>
 */
class JudgedPairRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JudgedPair::class);
    }

    public function linkOf(string $word, string $answer, Language $language): ?Link
    {
        return $this->findOneBy(['word' => $word, 'answer' => $answer, 'language' => $language])?->getLink();
    }

    /**
     * Keeps the label of a pair. Written straight to the database: two requests judging the same
     * pair at once is no error (the second finds it kept), and must not close the entity manager.
     */
    public function remember(string $word, string $answer, Language $language, Link $link): void
    {
        try {
            $this->getEntityManager()->getConnection()->insert('judged_pair', [
                'word' => $word,
                'answer' => $answer,
                'language' => $language->value,
                'link' => $link->value,
                'judged_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Judged by someone else in the meantime: the same question has the same label.
        }
    }
}
