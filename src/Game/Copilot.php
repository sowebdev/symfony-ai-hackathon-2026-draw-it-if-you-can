<?php

namespace App\Game;

use App\Entity\Hint;
use App\Entity\Round;
use App\Repository\HintRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The rules of the copilot: it whispers words to the player who guesses the drawing of the AI,
 * a few per round, never the same twice. The player decides: a hint never finds the word by itself.
 */
final readonly class Copilot
{
    public const int MAX_HINTS = 5;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private HintRepository $hints,
    ) {
    }

    /**
     * @throws AgentRefusal When the round does not take this hint, with the reason for the agent
     */
    public function suggest(Round $round, string $word): Hint
    {
        $word = trim($word);
        if ('' === $word || mb_strlen($word) > 100) {
            throw new AgentRefusal('A hint is one word or a short phrase, at most 100 characters.');
        }
        if ($round->getGame()->isDuel()) {
            throw new AgentRefusal('This game is a duel: you play against the player, not for them. Answer with submit_answer.');
        }
        if (!$round->getGame()->isWithCopilot()) {
            throw new AgentRefusal('The player plays this game alone, without a copilot: stop playing.');
        }
        if ($round->getGame()->hasLeft()) {
            throw new AgentRefusal('The player left the game: stop playing.');
        }
        if (!$round->isDrawnByAi()) {
            throw new AgentRefusal('The player draws this round and knows the word: there is nothing to guess. Wait for the next round, drawn by the AI.');
        }
        if ($round->isOver()) {
            throw new AgentRefusal('This round is over. Wait for the next one with wait_for_your_turn.');
        }

        $given = $this->hints->findByRound($round);
        if (\count($given) >= self::MAX_HINTS) {
            throw new AgentRefusal(\sprintf('You gave the %d hints of this round: let the player guess.', self::MAX_HINTS));
        }
        foreach ($given as $hint) {
            if (WordMatcher::normalize($hint->getWord()) === WordMatcher::normalize($word)) {
                throw new AgentRefusal(\sprintf('You already suggested "%s" in this round.', $hint->getWord()));
            }
        }

        $hint = new Hint($round, $word, $round->elapsedMs());
        $this->entityManager->persist($hint);
        $this->entityManager->flush();

        return $hint;
    }
}
