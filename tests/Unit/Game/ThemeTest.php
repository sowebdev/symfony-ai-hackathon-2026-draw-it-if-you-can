<?php

namespace App\Tests\Unit\Game;

use App\Game\Theme;
use PHPUnit\Framework\TestCase;

/**
 * Themes stored by an entity as their values, and read back.
 */
final class ThemeTest extends TestCase
{
    public function testStoredValuesGiveTheThemesBackInTheOrderOfTheCases(): void
    {
        $values = Theme::toValues([Theme::Transport, Theme::Animals]);

        self::assertSame(['transport', 'animals'], $values);
        self::assertSame([Theme::Animals, Theme::Transport], Theme::fromValues($values));
    }

    public function testAThemeNoLongerInTheGameIsLeftOut(): void
    {
        self::assertSame([Theme::Nature], Theme::fromValues(['nature', 'dinosaurs']));
    }
}
