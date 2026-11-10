<?php

namespace App\Controller;

use App\Game\Theme;

/**
 * Payload of the home form.
 */
final readonly class NewGame
{
    public function __construct(
        public Theme $theme,
    ) {
    }
}
