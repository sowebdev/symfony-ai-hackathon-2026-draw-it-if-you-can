<?php

namespace App\Game;

use App\Entity\Game;
use App\Entity\Round;

/**
 * What an agent waits for: the game as it was when the agent started to wait, to tell whether
 * something new happened for it since. An agent cannot wait by itself between two calls, so the
 * game waits for it.
 */
final readonly class AgentTurn
{
    /** A round started since. */
    public const string NEW_ROUND = 'a new round started';

    /** The round ended since. */
    public const string ROUND_OVER = 'the round is over';

    /** The drawing the agent plays on has strokes it neither saw nor was told of. */
    public const string NEW_STROKES = 'new strokes on the drawing';

    /** While the player draws, the AI made guesses the copilot was not told of. */
    public const string NEW_AI_GUESSES = 'the AI made new guesses';

    private function __construct(
        private ?string $round,
        private bool $over,
    ) {
    }

    public static function from(?Round $round): self
    {
        return new self($round?->getId()->toRfc4122(), $round?->isOver() ?? false);
    }

    /**
     * What is new for the agent, for it to act on; null while nothing is.
     *
     * @param int $strokes   Strokes of the round now
     * @param int $aiGuesses Guesses of the AI in the round now
     *
     * @return self::NEW_ROUND|self::ROUND_OVER|self::NEW_STROKES|self::NEW_AI_GUESSES|null
     */
    public function news(Game $game, ?Round $round, int $strokes, int $aiGuesses = 0): ?string
    {
        return match (true) {
            null === $round => null,
            $round->getId()->toRfc4122() !== $this->round => self::NEW_ROUND,
            $round->isOver() => $this->over ? null : self::ROUND_OVER,
            self::playsOn($game, $round) && $strokes > $round->getStrokesKnownByAgent() => self::NEW_STROKES,
            // The copilot of a player who draws acts on what the AI sees, not on every stroke.
            self::tipsOn($game, $round) && $aiGuesses > $round->getAiGuessesToldToAgent() => self::NEW_AI_GUESSES,
            default => null,
        };
    }

    /**
     * Whether the agent plays on the drawing of this round: a rival on every drawing, a copilot
     * on the drawings of the AI only.
     */
    public static function playsOn(Game $game, Round $round): bool
    {
        return $game->isDuel() || $round->isDrawnByAi();
    }

    /**
     * Whether the agent gives tips in this round: a copilot while the player draws.
     */
    public static function tipsOn(Game $game, Round $round): bool
    {
        return !$game->isDuel() && !$round->isDrawnByAi();
    }
}
