<?php

namespace App\Drawing;

/**
 * Renders canonical strokes to the PNG image shown to the guesser: black lines on white.
 */
final class StrokeRenderer
{
    public const int IMAGE_SIZE = 512;
    private const int LINE_WIDTH = 6;

    /**
     * @param iterable<list<array{int, int, int}>> $strokes
     */
    public function toPng(iterable $strokes): string
    {
        $image = imagecreatetruecolor(self::IMAGE_SIZE, self::IMAGE_SIZE);
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 17, 17, 17);
        imagefill($image, 0, 0, $white);
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
}
