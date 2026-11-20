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
        private Dealer $dealer,
    ) {
    }

    /**
     * A new game, stored without a round: the theme of its first round is dealt, so that its card
     * is shown while the AI picks the word. A game with an agent waits in the lobby meanwhile.
     */
    public function open(Game $game): void
    {
        $game->dealNextTheme($this->dealer->theme(among: $game->getThemes()));
        $this->entityManager->persist($game);
        $this->entityManager->flush();
    }

    /**
     * The round after the last one of the game, once it is over, or the first one. Null when the game is over.
     * A second request (a double click) gets the round the first one started.
     *
     * @throws \Throwable When the model gives no word: nothing is stored
     */
    public function startNext(Game $game): ?Round
    {
        // A game with a copilot has no round until the player leaves the lobby.
        $last = $this->rounds->findLastOfGame($game);
        if (null !== $last && !$last->isOver()) {
            return $last;
        }
        if (null !== $last && $last->isLast()) {
            return null;
        }

        $round = $this->newRound($game, null === $last ? 1 : $last->getNumber() + 1, $last);
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

    private function newRound(Game $game, int $number, ?Round $previous): Round
    {
        $theme = $game->getNextTheme() ?? $this->dealer->theme($previous?->getTheme(), $game->getThemes());
        // The AI that does not guess picks the word: the guesser when the player draws, the drawer otherwise.
        $role = DrawnBy::Ai === $game->drawnByInRound($number) ? Role::Drawer : Role::Guesser;
        $word = $this->wordPicker->pick($theme, $game->getLanguage(), $role);
        $game->dealNextTheme($this->dealer->theme($theme, $game->getThemes()));

        return new Round($game, $number, $word->word, $game->getRoundSeconds() * 1000, $theme, $word->article);
    }
}
