<?php

namespace App\Game;

use App\Entity\Hint;
use App\Entity\Round;
use App\Entity\Tip;
use App\Repository\HintRepository;
use App\Repository\TipRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The rules of the copilot: it whispers words to the player who guesses the drawing of the AI,
 * a few per round, never the same twice, and gives tips to the player who draws, a few seconds
 * apart. The player decides: a hint never finds the word by itself.
 */
final readonly class Copilot
{
    public const int MAX_HINTS = 5;

    /** The copilot waits this long between two tips. */
    public const int TIP_GAP_MS = 5000;

    /** A tip is short: the player reads it while drawing. */
    public const int TIP_MAX_LENGTH = 120;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private HintRepository $hints,
        private TipRepository $tips,
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
        $this->checkCopilot($round);
        if (!$round->isDrawnByAi()) {
            throw new AgentRefusal('The player draws this round and knows the word: there is nothing to guess. Help them draw with give_tip.');
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

    /**
     * @throws AgentRefusal When the round does not take this tip, with the reason for the agent
     */
    public function tip(Round $round, string $text): Tip
    {
        $text = trim($text);
        if ('' === $text || mb_strlen($text) > self::TIP_MAX_LENGTH) {
            throw new AgentRefusal(\sprintf('A tip is one short sentence, at most %d characters.', self::TIP_MAX_LENGTH));
        }
        $this->checkCopilot($round);
        if ($round->isDrawnByAi()) {
            throw new AgentRefusal('The AI draws this round: help the player guess with suggest_word.');
        }
        if ($round->isOver()) {
            throw new AgentRefusal('This round is over. Wait for the next one with wait_for_your_turn.');
        }

        $given = $this->tips->findByRound($round);
        $last = end($given);
        $now = $round->elapsedMs();
        if (false !== $last && $now - $last->getSaidAfterMs() < self::TIP_GAP_MS) {
            throw new AgentRefusal(\sprintf('Too fast: one tip every %d seconds, the player draws meanwhile. Try again in %.1f s.', intdiv(self::TIP_GAP_MS, 1000), (self::TIP_GAP_MS - $now + $last->getSaidAfterMs()) / 1000));
        }

        $tip = new Tip($round, $text, $now);
        $this->entityManager->persist($tip);
        $this->entityManager->flush();

        return $tip;
    }

    /**
     * @throws AgentRefusal When the game has no copilot playing
     */
    private function checkCopilot(Round $round): void
    {
        if ($round->getGame()->isDuel()) {
            throw new AgentRefusal('This game is a duel: you play against the player, not for them. Answer with submit_answer.');
        }
        if (!$round->getGame()->isWithCopilot()) {
            throw new AgentRefusal('The player plays this game alone, without a copilot: stop playing.');
        }
        if ($round->getGame()->hasLeft()) {
            throw new AgentRefusal('The player left the game: stop playing.');
        }
    }
}
