<?php

namespace App\Ai;

/**
 * What a check asked and what came back, to show on the models screen.
 */
final readonly class ModelCheckResult
{
    public function __construct(
        /** What is wrong, or null if the role can play */
        public ?Problem $problem,
        /** What the model answered when shown the circle, if it answered */
        public ?string $answer = null,
        /**
         * The stroke the drawer drew, in canonical points, if it drew one.
         *
         * @var list<array{int, int, int}>|null
         */
        public ?array $stroke = null,
    ) {
    }
}
