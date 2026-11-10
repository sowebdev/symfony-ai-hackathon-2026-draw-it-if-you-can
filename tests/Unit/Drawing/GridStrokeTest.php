<?php

namespace App\Tests\Unit\Drawing;

use App\Drawing\GridStroke;
use PHPUnit\Framework\TestCase;

final class GridStrokeTest extends TestCase
{
    public function testReadsTheCells(): void
    {
        $stroke = GridStroke::fromWritten('left ear', 'x10y12, X15Y20,x18y25');

        self::assertNotNull($stroke);
        self::assertSame('left ear', $stroke->part);
        self::assertSame([[10, 12], [15, 20], [18, 25]], $stroke->cells);
        self::assertSame('x10y12, X15Y20,x18y25', $stroke->written);
    }

    public function testDropsTheCellsOutsideTheGrid(): void
    {
        $stroke = GridStroke::fromWritten('tail', 'x0y5,x51y5,x5y60,x50y50,nonsense,x1y1');

        self::assertSame([[50, 50], [1, 1]], $stroke?->cells);
    }

    public function testNoUsableCellNoStroke(): void
    {
        self::assertNull(GridStroke::fromWritten('body', 'a circle in the middle'));
    }

    public function testCapsTheNumberOfCells(): void
    {
        $stroke = GridStroke::fromWritten('scribble', implode(',', array_fill(0, 500, 'x5y5')));

        self::assertCount(GridStroke::MAX_CELLS, $stroke?->cells ?? []);
    }
}
