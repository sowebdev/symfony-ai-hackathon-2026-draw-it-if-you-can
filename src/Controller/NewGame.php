<?php

namespace App\Controller;

use App\Game\DrawnBy;
use App\Game\Mode;
use App\Game\Theme;

/**
 * Payload of the home form: the theme, who draws the first round (the AI, so that the player
 * discovers the game by guessing), and whether an agent plays along over MCP, as a copilot or as
 * a rival in a duel.
 */
final readonly class NewGame
{
    public function __construct(
        public Theme $theme,
        public DrawnBy $drawnBy = DrawnBy::Ai,
        public Mode $mode = Mode::Solo,
    ) {
    }
}
