<?php

namespace App\Game;

use App\Ai\Judge;
use App\Entity\Guess;
use App\Entity\Round;
use App\Repository\GuessRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Lock\LockFactory;

/**
 * The circuit every answer goes through, whoever gives it: the player, the AI that guesses, the rival
 * agent. A word is kept as soon as it is said, pending, at the moment it was said (hear). The judge
 * then gives its verdict, at once for the exact word, after the model for the others (judge). The round
 * is settled in the order the words were said (Round::settle), so a word that waits for the model is
 * never overtaken by a word said after it. One lock per round: two requests never settle it at once.
 * Why: docs/adr/0006-the-judge-a-strict-label-a-cache-and-the-order-of-the-words.md.
 */
final readonly class Referee
{
    /** A word judged for longer than this is refused: the judge has its own limit, this one is a margin over it. */
    public const int JUDGING_LIMIT_MS = (Judge::MAX_DURATION_S + 2) * 1000;

    /** How often a word waits for the words said before it to be settled. */
    private const int WAIT_STEP_MS = 150;

    public function __construct(
        private Judge $judge,
        private EntityManagerInterface $entityManager,
        private GuessRepository $guesses,
        private LockFactory $locks,
    ) {
    }

    /**
     * Keeps a word now, pending: its moment is the moment it was said, whatever the judge takes.
     */
    public function hear(Round $round, string $word, Author $author): Guess
    {
        $lock = $this->locks->createLock('round-'.$round->getId());
        $lock->acquire(true);
        try {
            $guess = new Guess($round, $word, $round->elapsedMs(), $author);
            $this->entityManager->persist($guess);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        return $guess;
    }

    /**
     * Judges a word heard before, then settles the round. Returns once every word said before it is
     * settled too: the guess then knows whether it found the round, and the round whether it is found.
     */
    public function judge(Guess $guess): void
    {
        $round = $guess->getRound();
        $game = $round->getGame();
        $verdict = $this->judge->judge($guess->getWord(), $round->getTargetWord(), $game->getLanguage(), $game->isEasyMode());

        $first = true;
        while ($this->settle($guess, $first ? $verdict : null)) {
            $first = false;
            Clock::get()->sleep(self::WAIT_STEP_MS / 1000);
        }
    }

    /**
     * @return bool Whether a word said before $guess is still being judged
     */
    private function settle(Guess $guess, ?Verdict $verdict): bool
    {
        $round = $guess->getRound();
        $lock = $this->locks->createLock('round-'.$round->getId());
        $lock->acquire(true);
        try {
            // Other requests judge the other words: read them, and the round, as they are now.
            $this->entityManager->refresh($round);
            $guesses = $this->guesses->findByRound($round, fresh: true);
            if (null !== $verdict) {
                $guess->judged($verdict);
            }
            $waiting = $round->settle($guesses, $guess, self::JUDGING_LIMIT_MS);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        return $waiting;
    }
}
