<?php

namespace App\Drawing;

/**
 * The canonical stroke format, shared by the human and the AI: a list of [x, y, t] points.
 * x and y are integers from 0 to 1000 on a square canvas, origin top left; t is the number of
 * milliseconds since the round started, and never goes back.
 */
final readonly class StrokePoints
{
    public const int CANVAS_SIZE = 1000;
    public const int MAX_POINTS = 2000;

    /**
     * @param list<array{int, int, int}> $points
     */
    private function __construct(public array $points)
    {
    }

    /**
     * @throws InvalidStroke
     */
    public static function fromArray(mixed $points): self
    {
        if (!\is_array($points) || !array_is_list($points)) {
            throw new InvalidStroke('A stroke is a list of points.');
        }
        if ([] === $points) {
            throw new InvalidStroke('A stroke has at least one point.');
        }
        if (\count($points) > self::MAX_POINTS) {
            throw new InvalidStroke(\sprintf('A stroke has at most %d points.', self::MAX_POINTS));
        }

        $previousTime = 0;
        foreach ($points as $index => $point) {
            if (!\is_array($point) || !array_is_list($point) || 3 !== \count($point)) {
                throw new InvalidStroke(\sprintf('Point %d is not an [x, y, t] triple.', $index));
            }
            [$x, $y, $t] = $point;
            if (!\is_int($x) || !\is_int($y) || !\is_int($t)) {
                throw new InvalidStroke(\sprintf('Point %d has a value that is not an integer.', $index));
            }
            if ($x < 0 || $x > self::CANVAS_SIZE || $y < 0 || $y > self::CANVAS_SIZE) {
                throw new InvalidStroke(\sprintf('Point %d is outside the canvas.', $index));
            }
            if ($t < $previousTime) {
                throw new InvalidStroke(\sprintf('Point %d goes back in time.', $index));
            }
            $previousTime = $t;
        }

        return new self($points);
    }

    public function startTime(): int
    {
        return $this->points[0][2];
    }

    public function endTime(): int
    {
        return $this->points[array_key_last($this->points)][2];
    }
}
