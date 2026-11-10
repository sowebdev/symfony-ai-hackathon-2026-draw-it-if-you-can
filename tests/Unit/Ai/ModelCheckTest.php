<?php

namespace App\Tests\Unit\Ai;

use App\Ai\ModelCheck;
use App\Drawing\StrokePoints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelCheckTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, bool}>
     */
    public static function answers(): iterable
    {
        yield 'circle' => ['circle', true];
        yield 'case and article' => ['A Circle', true];
        yield 'plural' => ['circles', true];
        yield 'french' => ['Cercle', true];
        yield 'ring' => ['ring', true];
        yield 'another shape' => ['square', false];
        yield 'a guess from a blind model' => ['cat', false];
        yield 'no usable answer' => [null, false];
    }

    #[DataProvider('answers')]
    public function testJudgesTheAnswerByCode(?string $answer, bool $expected): void
    {
        self::assertSame($expected, ModelCheck::isCircle($answer));
    }

    public function testTheCircleIsACanonicalStroke(): void
    {
        $points = ModelCheck::circle();

        self::assertSame($points[0][0], end($points)[0], 'The circle is closed');
        foreach ($points as [$x, $y]) {
            self::assertGreaterThanOrEqual(0, min($x, $y));
            self::assertLessThanOrEqual(StrokePoints::CANVAS_SIZE, max($x, $y));
        }
    }
}
