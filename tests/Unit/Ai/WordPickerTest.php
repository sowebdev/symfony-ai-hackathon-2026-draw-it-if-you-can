<?php

namespace App\Tests\Unit\Ai;

use App\Ai\WordPicker;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class WordPickerTest extends TestCase
{
    public function testDrawsAmongTheCandidates(): void
    {
        $candidates = ['mouse', 'keyboard', 'coffee mug', 'laptop'];

        $drawn = [];
        for ($seed = 0; $seed < 40; ++$seed) {
            $drawn[] = WordPicker::draw($candidates, [], new Randomizer(new Mt19937($seed)));
        }

        self::assertEqualsCanonicalizing($candidates, array_values(array_unique($drawn)), 'Every candidate can come out');
    }

    public function testLeavesOutTheRecentWords(): void
    {
        for ($seed = 0; $seed < 20; ++$seed) {
            // Recent words are compared like guesses: case and plural do not count.
            $word = WordPicker::draw(['mouse', 'Keyboards', 'laptop'], ['Mice', 'keyboard', 'mouse'], new Randomizer(new Mt19937($seed)));
            self::assertSame('laptop', $word);
        }
    }

    public function testFallsBackOnRecentWordsRatherThanNothing(): void
    {
        self::assertSame('mouse', WordPicker::draw(['mouse'], ['mouse'], new Randomizer(new Mt19937(1))));
    }

    public function testNoCandidateNoWord(): void
    {
        self::assertNull(WordPicker::draw([], ['mouse'], new Randomizer(new Mt19937(1))));
    }
}
