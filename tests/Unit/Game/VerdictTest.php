<?php

namespace App\Tests\Unit\Game;

use App\Ai\Link;
use App\Game\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The table of the judge, row by row: the label of the model, and the mode, give the verdict.
 */
final class VerdictTest extends TestCase
{
    /**
     * @return iterable<string, array{?Link, Verdict, Verdict}>
     */
    public static function table(): iterable
    {
        yield 'same word' => [Link::SameWord, Verdict::Accepted, Verdict::Accepted];
        yield 'synonym' => [Link::Synonym, Verdict::Warm, Verdict::Accepted];
        yield 'very close' => [Link::VeryClose, Verdict::Warm, Verdict::Accepted];
        yield 'related' => [Link::Related, Verdict::Refused, Verdict::Warm];
        yield 'unrelated' => [Link::Unrelated, Verdict::Refused, Verdict::Refused];
        yield 'no label' => [null, Verdict::Refused, Verdict::Refused];
    }

    #[DataProvider('table')]
    public function testTheTable(?Link $link, Verdict $normal, Verdict $easy): void
    {
        self::assertSame($normal, Verdict::of($link, false), 'normal mode');
        self::assertSame($easy, Verdict::of($link, true), 'easy mode');
    }

    public function testOnlyAcceptedIsAccepted(): void
    {
        self::assertTrue(Verdict::Accepted->isAccepted());
        self::assertFalse(Verdict::Warm->isAccepted());
        self::assertFalse(Verdict::Refused->isAccepted());
        self::assertTrue(Verdict::Warm->isWarm());
    }
}
