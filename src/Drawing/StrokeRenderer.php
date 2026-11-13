<?php

namespace App\Drawing;

/**
 * Renders canonical strokes to a PNG image, black lines on white: shown to the guesser, and to the
 * drawer with the lines of its grid.
 */
final class StrokeRenderer
{
    public const int IMAGE_SIZE = 512;
    private const int LINE_WIDTH = 6;

    /** Lines of the grid of the drawer, every this many cells. */
    private const int GRID_STEP = 10;

    /**
     * @param iterable<list<array{int, int, int}>> $strokes
     * @param bool                                 $grid    With light lines every 10 cells of the 50 x 50 grid, numbered, for the drawer to place its strokes
     */
    public function toPng(iterable $strokes, bool $grid = false): string
    {
        $image = imagecreatetruecolor(self::IMAGE_SIZE, self::IMAGE_SIZE);
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 17, 17, 17);
        imagefill($image, 0, 0, $white);
        if ($grid) {
            $this->grid($image);
        }
        imagesetthickness($image, self::LINE_WIDTH);

        $scale = self::IMAGE_SIZE / StrokePoints::CANVAS_SIZE;
        foreach ($strokes as $points) {
            $previous = null;
            foreach ($points as [$x, $y]) {
                $current = [(int) round($x * $scale), (int) round($y * $scale)];
                if (null !== $previous) {
                    imageline($image, $previous[0], $previous[1], $current[0], $current[1], $black);
                }
                // Round joints and dots: a thick line alone leaves gaps at the corners.
                imagefilledellipse($image, $current[0], $current[1], self::LINE_WIDTH, self::LINE_WIDTH, $black);
                $previous = $current;
            }
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function grid(\GdImage $image): void
    {
        $grey = (int) imagecolorallocate($image, 200, 200, 200);
        $label = (int) imagecolorallocate($image, 150, 150, 150);
        $cell = self::IMAGE_SIZE / GridStroke::GRID_SIZE;
        for ($n = self::GRID_STEP; $n < GridStroke::GRID_SIZE; $n += self::GRID_STEP) {
            // The line between cell n and cell n + 1.
            $at = (int) round($n * $cell);
            imageline($image, $at, 0, $at, self::IMAGE_SIZE, $grey);
            imageline($image, 0, $at, self::IMAGE_SIZE, $at, $grey);
            imagestring($image, 2, $at + 2, 2, 'x'.$n, $label);
            imagestring($image, 2, 2, $at + 2, 'y'.$n, $label);
        }
    }
}
