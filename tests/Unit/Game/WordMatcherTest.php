<?php

namespace App\Tests\Unit\Game;

use App\Game\WordMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WordMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sameWords(): iterable
    {
        yield 'identical' => ['cat', 'cat'];
        yield 'case' => ['Cat', 'cat'];
        yield 'accents' => ['Éléphant', 'elephant'];
        yield 'plural in s' => ['cats', 'cat'];
        yield 'plural in x' => ['gâteaux', 'gâteau'];
        yield 'english article' => ['a cat', 'cat'];
        yield 'french article' => ['Les chats', 'chat'];
        yield 'elided article' => ["l'éléphant", 'éléphant'];
        yield 'german article' => ['Die Katze', 'Katze'];
        yield 'italian article' => ['il gatto', 'gatto'];
        yield 'italian elided article' => ["l'albero", 'albero'];
        yield 'spanish article' => ['el perro', 'perro'];
        yield 'punctuation' => ['cat!', 'cat'];
        yield 'two words' => ['Coffee Mugs', 'coffee mug'];
    }

    #[DataProvider('sameWords')]
    public function testFindsTheSameWord(string $guess, string $target): void
    {
        self::assertTrue((new WordMatcher())->matches($guess, $target));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function otherWords(): iterable
    {
        yield 'near word' => ['kitten', 'cat'];
        yield 'longer word' => ['catfish', 'cat'];
        yield 'an article alone is a word' => ['die', 'katze'];
        yield 'empty guess' => ['', 'cat'];
        yield 'punctuation only' => ['?!', 'cat'];
        yield 'short word ending in s is kept' => ['bus', 'bu'];
    }

    #[DataProvider('otherWords')]
    public function testDoesNotFindAnotherWord(string $guess, string $target): void
    {
        self::assertFalse((new WordMatcher())->matches($guess, $target));
    }
}
