<?php

namespace App\Tests\Unit\Game;

use App\Game\Language;
use App\Game\WordCandidate;
use App\Game\WordMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WordCandidateTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Language, string, ?string}>
     */
    public static function candidates(): iterable
    {
        yield 'french' => ['un château fort', Language::French, 'château fort', 'un'];
        yield 'french feminine' => ['Une souris', Language::French, 'souris', 'une'];
        yield 'german' => ['ein Schloss', Language::German, 'Schloss', 'ein'];
        yield 'english' => ['an apple', Language::English, 'apple', 'an'];
        yield 'spanish' => ['una casa', Language::Spanish, 'casa', 'una'];
        yield 'italian' => ['uno zaino', Language::Italian, 'zaino', 'uno'];
        yield 'italian elided' => ["un'amica", Language::Italian, 'amica', "un'"];
        yield 'without article' => ['château fort', Language::French, 'château fort', null];
        yield 'article of another language' => ['a cat', Language::French, 'a cat', null];
        yield 'article alone' => ['un', Language::French, 'un', null];
    }

    #[DataProvider('candidates')]
    public function testSplitsTheArticleFromTheWord(string $candidate, Language $language, string $word, ?string $article): void
    {
        $parsed = WordCandidate::parse($candidate, $language);

        self::assertSame($word, $parsed->word);
        self::assertSame($article, $parsed->article);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function guesses(): iterable
    {
        yield 'bare' => ['château fort'];
        yield 'definite article' => ['le château fort'];
        yield 'indefinite article' => ['un château fort'];
        yield 'capitals' => ['Un Château Fort'];
    }

    #[DataProvider('guesses')]
    public function testTheArticleDoesNotCountForTheAnswer(string $guess): void
    {
        $word = WordCandidate::parse('un château fort', Language::French)->word;

        self::assertTrue((new WordMatcher())->matches($guess, $word));
        self::assertFalse((new WordMatcher())->matches('fort', $word));
    }
}
