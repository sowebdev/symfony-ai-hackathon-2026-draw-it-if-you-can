<?php

namespace App\Controller;

use App\Game\DrawnBy;
use App\Game\Theme;

/**
 * Payload of the home form: the theme, and who draws the first round (the AI, so that the
 * player discovers the game by guessing), and whether a copilot plays along over MCP.
 */
final readonly class NewGame
{
    public function __construct(
        public Theme $theme,
        public DrawnBy $drawnBy = DrawnBy::Ai,
        public bool $copilot = false,
    ) {
    }
}
