<?php

namespace App\Ai;

/**
 * What the AI does in the game. Each role has its own model, at the provider of its choice, chosen on
 * the models screen; a role without a model of its own uses the one of its fallback.
 */
enum Role: string
{
    /** Draws, stroke by stroke, while the player guesses. */
    case Drawer = 'drawer';

    /** Picks the word the player draws, then guesses the drawing: it must see images. */
    case Guesser = 'guesser';

    /** Names the link between an answer and the word (App\Ai\Judge): text only, and the faster the better. */
    case Judge = 'judge';

    /**
     * The role whose model this one uses while it has none of its own: the judge follows the guesser, which
     * follows the drawer, so one model plays every role until the player chooses another for one of them.
     * The drawer follows nobody. A future role (a voice) is one more case here, and needs no new column.
     */
    public function fallback(): ?self
    {
        return match ($this) {
            self::Judge => self::Guesser,
            self::Guesser => self::Drawer,
            self::Drawer => null,
        };
    }
}
