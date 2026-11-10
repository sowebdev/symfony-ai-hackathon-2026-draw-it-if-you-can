<?php

namespace App\Drawing;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns the cells written by the model into a canonical stroke.
 *
 * Humanized (the default, HUMANIZED_DRAWING in .env): it looks drawn by a hand, a smooth line
 * through the cells (sharp where a cell is repeated), a slight wobble, a small overshoot at the
 * end, and a pen that starts slowly, speeds up and slows down again. Otherwise: straight segments
 * from cell to cell, at a steady speed.
 *
 * Deterministic: the same stroke and seed always give the same points.
 */
final readonly class StrokeTracer
{
    private const float CELL = StrokePoints::CANVAS_SIZE / GridStroke::GRID_SIZE;

    /** Distance between two samples of the line, in canvas units. */
    private const float STEP = 8.0;

    /** Wobble across the line, in canvas units. */
    private const float WOBBLE = 4.0;

    /** Longest overshoot past the last cell, in canvas units. */
    private const float OVERSHOOT = 10.0;

    /** Pen speed, in canvas units per millisecond: about a second for the width of the canvas. */
    private const float MIN_SPEED = 0.7;
    private const float MAX_SPEED = 1.1;

    /** Pen speed when not humanized. */
    private const float STEADY_SPEED = 0.9;

    private const int MIN_DURATION_MS = 150;

    public function __construct(
        #[Autowire('%env(bool:HUMANIZED_DRAWING)%')]
        private bool $humanized = true,
    ) {
    }

    public function draw(GridStroke $stroke, int $startMs, int $seed): StrokePoints
    {
        if (!$this->humanized) {
            return StrokePoints::fromArray($this->timed($this->straight($stroke->cells), $startMs, null));
        }

        $random = new Randomizer(new Mt19937($seed));
        $line = $this->wobble($this->overshoot($this->smooth($stroke->cells), $random), $random);

        return StrokePoints::fromArray($this->timed($line, $startMs, $random));
    }

    /**
     * Straight segments from cell centre to cell centre, sampled so that the pen moves smoothly.
     *
     * @param list<array{int, int}> $cells
     *
     * @return list<array{float, float}>
     */
    private function straight(array $cells): array
    {
        $line = [];
        foreach ($cells as $cell) {
            $point = [($cell[0] - 0.5) * self::CELL, ($cell[1] - 0.5) * self::CELL];
            $previous = self::last($line);
            if (null !== $previous) {
                $samples = max(1, (int) ceil(self::distance($previous, $point) / self::STEP));
                for ($s = 1; $s < $samples; ++$s) {
                    $line[] = [$previous[0] + ($point[0] - $previous[0]) * $s / $samples, $previous[1] + ($point[1] - $previous[1]) * $s / $samples];
                }
            }
            if ($point !== $previous) {
                $line[] = $point;
            }
        }

        return $line;
    }

    /**
     * How long the pen takes on a stroke, from its points.
     */
    public static function durationMs(StrokePoints $points): int
    {
        return $points->endTime() - $points->startTime();
    }

    /**
     * Cells to canvas points, through a Catmull-Rom spline, broken at repeated cells.
     *
     * @param list<array{int, int}> $cells
     *
     * @return list<array{float, float}>
     */
    private function smooth(array $cells): array
    {
        $runs = [[]];
        $previous = null;
        foreach ($cells as $cell) {
            $point = [($cell[0] - 0.5) * self::CELL, ($cell[1] - 0.5) * self::CELL];
            if ($cell === $previous) {
                // A corner: the next run starts where this one ends.
                $runs[] = [$point];
                continue;
            }
            $runs[array_key_last($runs)][] = $point;
            $previous = $cell;
        }

        $line = [];
        foreach ($runs as $run) {
            foreach ($this->spline($run) as $point) {
                if ($point !== self::last($line)) {
                    $line[] = $point;
                }
            }
        }

        return $line;
    }

    /**
     * @param list<array{float, float}> $run
     *
     * @return list<array{float, float}>
     */
    private function spline(array $run): array
    {
        $count = \count($run);
        if ($count < 2) {
            return $run;
        }

        $line = [$run[0]];
        for ($i = 0; $i < $count - 1; ++$i) {
            $p0 = $run[max(0, $i - 1)];
            [$p1, $p2] = [$run[$i], $run[$i + 1]];
            $p3 = $run[min($count - 1, $i + 2)];
            $samples = max(1, (int) ceil(self::distance($p1, $p2) / self::STEP));
            for ($s = 1; $s <= $samples; ++$s) {
                $u = $s / $samples;
                $line[] = [self::catmullRom($p0[0], $p1[0], $p2[0], $p3[0], $u), self::catmullRom($p0[1], $p1[1], $p2[1], $p3[1], $u)];
            }
        }

        return $line;
    }

    private static function catmullRom(float $p0, float $p1, float $p2, float $p3, float $u): float
    {
        return 0.5 * ((2 * $p1) + (-$p0 + $p2) * $u + (2 * $p0 - 5 * $p1 + 4 * $p2 - $p3) * $u ** 2 + (-$p0 + 3 * $p1 - 3 * $p2 + $p3) * $u ** 3);
    }

    /**
     * The pen goes a little past the end, in the direction it was going.
     *
     * @param list<array{float, float}> $line
     *
     * @return list<array{float, float}>
     */
    private function overshoot(array $line, Randomizer $random): array
    {
        if (\count($line) < 2) {
            return $line;
        }
        [$before, $last] = \array_slice($line, -2);
        $length = self::distance($before, $last);
        if ($length < 0.001) {
            return $line;
        }
        $extra = $random->getFloat(0, self::OVERSHOOT);
        $line[] = [$last[0] + ($last[0] - $before[0]) / $length * $extra, $last[1] + ($last[1] - $before[1]) / $length * $extra];

        return $line;
    }

    /**
     * A slow wave across the line: a hand never follows a ruler.
     *
     * @param list<array{float, float}> $line
     *
     * @return list<array{float, float}>
     */
    private function wobble(array $line, Randomizer $random): array
    {
        $phase = $random->getFloat(0, 2 * M_PI);
        $wavelength = $random->getFloat(120, 300);
        $travelled = 0.0;
        $wobbled = [];
        foreach ($line as $i => $point) {
            $next = $line[$i + 1] ?? $point;
            $previous = $line[$i - 1] ?? $point;
            $travelled += self::distance($previous, $point);
            [$dx, $dy] = [$next[0] - $previous[0], $next[1] - $previous[1]];
            $length = sqrt($dx ** 2 + $dy ** 2);
            if ($length < 0.001) {
                $wobbled[] = $point;
                continue;
            }
            $offset = self::WOBBLE * sin($phase + 2 * M_PI * $travelled / $wavelength);
            $wobbled[] = [$point[0] - $dy / $length * $offset, $point[1] + $dx / $length * $offset];
        }

        return $wobbled;
    }

    /**
     * Rounds to the canvas and gives each point its time. Humanized: slow at both ends, fast in
     * the middle. Otherwise (no randomizer): a steady speed.
     *
     * @param list<array{float, float}> $line
     *
     * @return list<array{int, int, int}>
     */
    private function timed(array $line, int $startMs, ?Randomizer $random): array
    {
        $lengths = [0.0];
        for ($i = 1, $count = \count($line); $i < $count; ++$i) {
            $lengths[] = $lengths[$i - 1] + self::distance($line[$i - 1], $line[$i]);
        }
        $total = end($lengths);
        $speed = null === $random ? self::STEADY_SPEED : $random->getFloat(self::MIN_SPEED, self::MAX_SPEED);
        $duration = max(self::MIN_DURATION_MS, $total / $speed);
        $ease = null === $random ? 0.0 : 0.6;

        $points = [];
        foreach ($line as $i => [$x, $y]) {
            $f = $total > 0 ? $lengths[$i] / $total : 0.0;
            // dt/df = 1 + ease cos(2 pi f): more time per distance at the ends than in the middle.
            $t = $startMs + (int) round($duration * ($f + $ease * sin(2 * M_PI * $f) / (2 * M_PI)));
            $last = self::last($points);
            $point = [self::clamp($x), self::clamp($y), max($t, $last[2] ?? $startMs)];
            if (null !== $last && $last[0] === $point[0] && $last[1] === $point[1]) {
                continue;
            }
            $points[] = $point;
        }

        return $points;
    }

    /**
     * @template T
     *
     * @param list<T> $list
     *
     * @return T|null
     */
    private static function last(array $list): mixed
    {
        return [] === $list ? null : $list[\count($list) - 1];
    }

    private static function clamp(float $value): int
    {
        return (int) min(StrokePoints::CANVAS_SIZE, max(0, round($value)));
    }

    /**
     * @param array{float, float} $a
     * @param array{float, float} $b
     */
    private static function distance(array $a, array $b): float
    {
        return sqrt(($b[0] - $a[0]) ** 2 + ($b[1] - $a[1]) ** 2);
    }
}
