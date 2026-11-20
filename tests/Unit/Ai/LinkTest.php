<?php

namespace App\Tests\Unit\Ai;

use App\Ai\Link;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinkTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?Link}>
     */
    public static function answers(): iterable
    {
        yield 'plain label' => ['same_word', Link::SameWord];
        yield 'case and full stop' => ['Synonym.', Link::Synonym];
        yield 'spaces' => [' very_close ', Link::VeryClose];
        yield 'quotes and newline' => ["\"related\"\n", Link::Related];
        yield 'bold' => ['**unrelated**', Link::Unrelated];
        yield 'reasoning removed' => ["<think>maybe synonym, or related</think>\nrelated", Link::Related];
        yield 'garbage' => ['I think it is a synonym', null];
        yield 'two labels' => ["synonym\nrelated", null];
        yield 'spoken label' => ['very close', null];
        yield 'unknown label' => ['opposite', null];
        yield 'empty' => ['', null];
        yield 'only reasoning' => ['<think>synonym</think>', null];
    }

    #[DataProvider('answers')]
    public function testReadsExactlyOneLabel(string $answer, ?Link $expected): void
    {
        self::assertSame($expected, Link::fromAnswer($answer));
    }
}
