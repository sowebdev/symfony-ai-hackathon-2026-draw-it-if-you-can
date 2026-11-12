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
     * @throws CopilotRefusal When the round does not take this hint, with the reason for the agent
     */
    public function suggest(Round $round, string $word): Hint
    {
        $word = trim($word);
        if ('' === $word || mb_strlen($word) > 100) {
            throw new CopilotRefusal('A hint is one word or a short phrase, at most 100 characters.');
        }
        if (!$round->isDrawnByAi()) {
            throw new CopilotRefusal('The player draws this round and knows the word: there is nothing to guess. Wait for the next round, drawn by the AI.');
        }
        if ($round->isOver()) {
            throw new CopilotRefusal('This round is over. Look for the next one with round_status.');
        }

        $given = $this->hints->findByRound($round);
        if (\count($given) >= self::MAX_HINTS) {
            throw new CopilotRefusal(\sprintf('You gave the %d hints of this round: let the player guess.', self::MAX_HINTS));
        }
        foreach ($given as $hint) {
            if (WordMatcher::normalize($hint->getWord()) === WordMatcher::normalize($word)) {
                throw new CopilotRefusal(\sprintf('You already suggested "%s" in this round.', $hint->getWord()));
            }
        }

        $hint = new Hint($round, $word, $round->elapsedMs());
        $this->entityManager->persist($hint);
        $this->entityManager->flush();

        return $hint;
    }
}
