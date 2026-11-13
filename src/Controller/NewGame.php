<?php

namespace App\Controller;

use App\Game\DrawnBy;
use App\Game\Mode;

/**
 * Payload of the home form: whether an agent plays along over MCP, as a copilot or as a rival in
 * a duel. The theme of each round is dealt by the game, and so is who draws first unless given.
 */
final readonly class NewGame
{
    public function __construct(
        public Mode $mode = Mode::Solo,
        public ?DrawnBy $drawnBy = null,
    ) {
    }
}
