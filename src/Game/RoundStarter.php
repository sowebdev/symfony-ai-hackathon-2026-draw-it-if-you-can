<?php

namespace App\Game;

use App\Ai\Role;
use App\Ai\WordPicker;
use App\Entity\Game;
use App\Entity\Round;
use App\Repository\RoundRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Starts the rounds of a game, one after the other: the AI picks the word, and the clock starts.
 */
final readonly class RoundStarter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $registry,
        private RoundRepository $rounds,
        private WordPicker $wordPicker,
        #[Autowire('%game.round_seconds%')]
        private int $roundSeconds,
    ) {
    }

    /**
     * The first round of a new game, stored with it.
     *
     * @throws \Throwable When the model gives no word: nothing is stored
     */
    public function startGame(Game $game): Round
    {
        $round = $this->newRound($game, 1);
        $this->entityManager->persist($game);
        $this->entityManager->persist($round);
        $this->entityManager->flush();

        return $round;
    }

    /**
     * The round after the last one of the game, once it is over. Null when the game is over.
     * A second request (a double click) gets the round the first one started.
     *
     * @throws \Throwable When the model gives no word: nothing is stored
     */
    public function startNext(Game $game): ?Round
    {
        $last = $this->rounds->findLastOfGame($game) ?? throw new \LogicException('A game starts with its first round.');
        if (!$last->isOver()) {
            return $last;
        }
        if ($last->isLast()) {
            return null;
        }

        $round = $this->newRound($game, $last->getNumber() + 1);
        try {
            $this->entityManager->persist($round);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // The unique (game, number) lets one request only start the round.
            $this->registry->resetManager();

            return $this->rounds->findLastOfGame($game);
        }

        return $round;
    }

    private function newRound(Game $game, int $number): Round
    {
        // The AI that does not guess picks the word: the guesser when the player draws, the drawer otherwise.
        $role = DrawnBy::Ai === $game->drawnByInRound($number) ? Role::Drawer : Role::Guesser;
        $word = $this->wordPicker->pick($game->getTheme(), $game->getLanguage(), $role);

        return new Round($game, $number, $word, $this->roundSeconds * 1000);
    }
}
