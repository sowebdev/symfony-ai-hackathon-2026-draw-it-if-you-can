<?php

namespace App\Game;

use Random\Randomizer;

/**
 * Deals what the player does not choose: who draws the first round, and the theme of each round.
 * The theme changes from one round to the next, so that a game does not stay on one subject.
 */
final readonly class Dealer
{
    public function __construct(
        private Randomizer $randomizer = new Randomizer(),
    ) {
    }

    public function firstDrawer(): DrawnBy
    {
        return 0 === $this->randomizer->getInt(0, 1) ? DrawnBy::Ai : DrawnBy::Player;
    }

    /**
     * @param Theme|null       $previous The theme of the round before, never dealt twice in a row
     * @param list<Theme>|null $among    The themes of the game, all of them by default; with a single one,
     *                                   the rule cannot hold and it is dealt every round
     */
    public function theme(?Theme $previous = null, ?array $among = null): Theme
    {
        $among ??= Theme::cases();
        $themes = array_values(array_filter($among, static fn (Theme $theme): bool => $theme !== $previous));
        if ([] === $themes) {
            $themes = $among;
        }

        return $themes[$this->randomizer->getInt(0, \count($themes) - 1)] ?? throw new \LogicException('No theme left to deal.');
    }
}
