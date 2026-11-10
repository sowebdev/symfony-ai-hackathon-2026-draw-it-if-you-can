<?php

namespace App\Tests\Unit\Drawing;

use App\Drawing\StrokeRenderer;
use PHPUnit\Framework\TestCase;

final class StrokeRendererTest extends TestCase
{
    public function testDrawsTheStrokesInBlackOnWhite(): void
    {
        // A horizontal line across the middle of the canvas.
        $png = (new StrokeRenderer())->toPng([[[100, 500, 0], [900, 500, 300]]]);

        $image = imagecreatefromstring($png);
        self::assertNotFalse($image);
        self::assertSame(StrokeRenderer::IMAGE_SIZE, imagesx($image));
        self::assertSame(StrokeRenderer::IMAGE_SIZE, imagesy($image));

        $middle = intdiv(StrokeRenderer::IMAGE_SIZE, 2);
        self::assertTrue($this->isDark($image, $middle, $middle), 'The line is drawn.');
        self::assertFalse($this->isDark($image, $middle, 20), 'The rest stays white.');
        self::assertFalse($this->isDark($image, 10, $middle), 'The line stops where the stroke stops.');
    }

    public function testDrawsASinglePointAsADot(): void
    {
        $image = imagecreatefromstring((new StrokeRenderer())->toPng([[[500, 500, 0]]]));
        self::assertNotFalse($image);

        $middle = intdiv(StrokeRenderer::IMAGE_SIZE, 2);
        self::assertTrue($this->isDark($image, $middle, $middle));
    }

    public function testRendersAnEmptyCanvas(): void
    {
        $image = imagecreatefromstring((new StrokeRenderer())->toPng([]));
        self::assertNotFalse($image);

        $middle = intdiv(StrokeRenderer::IMAGE_SIZE, 2);
        self::assertFalse($this->isDark($image, $middle, $middle));
    }

    private function isDark(\GdImage $image, int $x, int $y): bool
    {
        return (imagecolorat($image, $x, $y) & 0xFF) < 128;
    }
}
