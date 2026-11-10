<?php

namespace App\Controller;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload of POST /games/{id}/strokes. The shape of each point is checked by StrokePoints.
 */
final readonly class NewStroke
{
    /**
     * @param list<mixed> $points
     */
    public function __construct(
        #[Assert\NotNull]
        public array $points,
    ) {
    }
}
