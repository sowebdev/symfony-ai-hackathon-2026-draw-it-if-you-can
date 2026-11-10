<?php

namespace App\Drawing;

/**
 * A stroke as the model writes it: grid cells "xNyM" on a 50 x 50 grid, x from 1 (left) to 50
 * (right), y from 1 (top) to 50 (bottom), like screen coordinates (test A, 10/11/2026). A repeated
 * cell marks a sharp corner. Turned into a canonical stroke by StrokeTracer.
 */
final readonly class GridStroke
{
    public const int GRID_SIZE = 50;
    public const int MAX_CELLS = 100;
    private const string CELL = '/^x(\d{1,2})y(\d{1,2})$/';

    /**
     * @param list<array{int, int}> $cells
     */
    private function __construct(
        public string $part,
        public array $cells,
        public string $written,
    ) {
    }

    /**
     * Keeps the cells inside the grid and drops the others.
     *
     * @return self|null Null if no cell is usable
     */
    public static function fromWritten(string $part, string $written): ?self
    {
        $cells = [];
        foreach (explode(',', $written) as $cell) {
            if (1 !== preg_match(self::CELL, strtolower(trim($cell)), $match)) {
                continue;
            }
            [$x, $y] = [(int) $match[1], (int) $match[2]];
            if ($x >= 1 && $x <= self::GRID_SIZE && $y >= 1 && $y <= self::GRID_SIZE) {
                $cells[] = [$x, $y];
            }
        }
        if ([] === $cells) {
            return null;
        }

        return new self(mb_substr(trim($part), 0, 100), \array_slice($cells, 0, self::MAX_CELLS), mb_substr($written, 0, 2000));
    }
}
