<?php

namespace App\Controller;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A word the player types to guess the drawing of the AI.
 */
final readonly class NewAnswer
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $word,
    ) {
    }
}
