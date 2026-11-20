<?php

namespace App\Game;

/**
 * How a game is set up, decided once on the settings screen: the themes in play, who draws, the
 * number and the length of the rounds. A game keeps a copy of what it started with.
 */
final readonly class Settings
{
    public const int MIN_ROUNDS = 1;
    public const int MAX_ROUNDS = 12;
    /** @var list<int> */
    public const array ROUND_SECONDS = [30, 45, 60, 90, 120];

    /**
     * @param list<Theme> $themes The themes dealt, at least one
     */
    public function __construct(
        public array $themes,
        public Turns $turns,
        public int $rounds,
        public int $roundSeconds,
    ) {
        if ([] === $themes) {
            throw new \InvalidArgumentException('At least one theme must stay active.');
        }
        if ($rounds < 1 || $roundSeconds < 1) {
            throw new \InvalidArgumentException('A game has at least one round, and a round lasts at least a second.');
        }
    }

    /**
     * The fixed rules, from the defaults of the game: every theme, turns, GAME_ROUNDS and GAME_ROUND_SECONDS.
     */
    public static function defaults(int $rounds, int $roundSeconds): self
    {
        return new self(Theme::cases(), Turns::Alternate, $rounds, $roundSeconds);
    }

    public function allThemes(): bool
    {
        return \count($this->themes) === \count(Theme::cases());
    }
}
