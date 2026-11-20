<?php

namespace App\Tests\Unit\Game;

use App\Game\Dealer;
use App\Game\DrawnBy;
use App\Game\Theme;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

class DealerTest extends TestCase
{
    public function testEitherSideMayDrawFirst(): void
    {
        $dealer = new Dealer(new Randomizer(new Mt19937(1)));

        $firsts = array_map(static fn (): DrawnBy => $dealer->firstDrawer(), range(1, 50));

        self::assertContains(DrawnBy::Ai, $firsts);
        self::assertContains(DrawnBy::Player, $firsts);
    }

    public function testEveryThemeIsDealtButNeverTwiceInARow(): void
    {
        $dealer = new Dealer(new Randomizer(new Mt19937(1)));

        $themes = [$dealer->theme()];
        for ($i = 1; $i < 100; ++$i) {
            $themes[] = $dealer->theme($themes[$i - 1]);
            self::assertNotSame($themes[$i - 1], $themes[$i]);
        }

        self::assertEqualsCanonicalizing(Theme::cases(), array_values(array_unique($themes, \SORT_REGULAR)));
    }

    public function testOnlyTheThemesOfTheGameAreDealt(): void
    {
        $dealer = new Dealer(new Randomizer(new Mt19937(1)));
        $among = [Theme::Animals, Theme::Nature, Theme::Transport];

        $themes = [$dealer->theme(among: $among)];
        for ($i = 1; $i < 100; ++$i) {
            $themes[] = $dealer->theme($themes[$i - 1], $among);
            self::assertNotSame($themes[$i - 1], $themes[$i]);
        }

        self::assertEqualsCanonicalizing($among, array_values(array_unique($themes, \SORT_REGULAR)));
    }

    public function testAGameWithASingleThemeDealsItEveryRound(): void
    {
        $dealer = new Dealer(new Randomizer(new Mt19937(1)));

        self::assertSame(Theme::Places, $dealer->theme(among: [Theme::Places]));
        self::assertSame(Theme::Places, $dealer->theme(Theme::Places, [Theme::Places]));
    }
}
