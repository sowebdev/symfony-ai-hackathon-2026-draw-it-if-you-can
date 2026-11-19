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

    /**
     * The role whose model this one uses while it has none of its own: the guesser follows the drawer
     * (which sees images too). The drawer follows nobody. A future role (a judge, a voice) is one more
     * case here, and needs no new column.
     */
    public function fallback(): ?self
    {
        return match ($this) {
            self::Guesser => self::Drawer,
            self::Drawer => null,
        };
    }
}
