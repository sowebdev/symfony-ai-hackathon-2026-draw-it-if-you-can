<?php

namespace App\Game;

use App\Entity\Round;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The points a word is worth, as a gauge that empties with time: the most points for the first
 * seconds, then a steady fall to the fewest points at the end of the round, and nothing after.
 * The browser draws the same gauge from steps(), so the formula lives here only.
 */
final readonly class ScoreGauge
{
    public function __construct(
        #[Autowire('%game.gauge.max_points%')]
        private int $maxPoints,
        #[Autowire('%game.gauge.full_s%')]
        private int $fullSeconds,
        #[Autowire('%game.gauge.min_points%')]
        private int $minPoints,
    ) {
    }

    /**
     * Points for a word found after $foundAfterMs in a round of $durationMs. A word found in the
     * grace second after the end is worth the fewest points.
     */
    public function points(int $foundAfterMs, int $durationMs): int
    {
        if ($foundAfterMs > $durationMs + Round::GRACE_MS) {
            return 0;
        }

        return self::interpolate($this->steps($durationMs), min($foundAfterMs, $durationMs));
    }

    public function pointsOf(Round $round): int
    {
        $foundAfterMs = $round->getFoundAfterMs();

        return null === $foundAfterMs ? 0 : $this->points($foundAfterMs, $round->getDurationMs());
    }

    /**
     * @param iterable<Round> $rounds
     */
    public function total(iterable $rounds): int
    {
        $total = 0;
        foreach ($rounds as $round) {
            $total += $this->pointsOf($round);
        }

        return $total;
    }

    /**
     * The corners of the gauge, [milliseconds, points]: the points fall in a straight line between two.
     *
     * @return list<array{int, int}>
     */
    public function steps(int $durationMs): array
    {
        // In a short round, the gauge is full for half of it at most, then falls.
        $fullMs = min($this->fullSeconds * 1000, intdiv($durationMs, 2));

        return [[0, $this->maxPoints], [$fullMs, $this->maxPoints], [$durationMs, $this->minPoints]];
    }

    /**
     * Rounded to ten points, as the browser does.
     *
     * @param list<array{int, int}> $steps
     */
    private static function interpolate(array $steps, int $ms): int
    {
        for ($i = 1, $count = \count($steps); $i < $count; ++$i) {
            [$toMs, $toPoints] = $steps[$i];
            if ($ms <= $toMs) {
                [$fromMs, $fromPoints] = $steps[$i - 1];
                $share = $toMs === $fromMs ? 0.0 : ($ms - $fromMs) / ($toMs - $fromMs);

                return (int) (round(($fromPoints + ($toPoints - $fromPoints) * $share) / 10) * 10);
            }
        }

        return $steps[$count - 1][1];
    }
}
