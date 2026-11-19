<?php

namespace App\Tests\Unit\Ai;

use App\Ai\OneWordModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OneWordModelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function usableAnswers(): iterable
    {
        yield 'plain word' => ['cat', 'cat'];
        yield 'spaces and full stop' => ["  Cat.\n", 'Cat'];
        yield 'quotes' => ['"éléphant"', 'éléphant'];
        yield 'markdown bold' => ['**rocket**', 'rocket'];
        yield 'first line only' => ["bicycle\nI think it is a bicycle.", 'bicycle'];
        yield 'empty reasoning block' => ["<think>\n</think>\n\ncat", 'cat'];
        yield 'two words' => ['coffee mug', 'coffee mug'];
        yield 'phrase' => ['Père Noël', 'Père Noël'];
        yield 'phrase of three words' => ['« pomme de terre ».', 'pomme de terre'];
        yield 'elision' => ["l'arbre", "l'arbre"];
    }

    #[DataProvider('usableAnswers')]
    public function testKeepsASingleWord(string $answer, string $word): void
    {
        self::assertSame($word, OneWordModel::clean($answer));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableAnswers(): iterable
    {
        yield 'empty' => [''];
        yield 'sentence' => ['I think this is a cat'];
        yield 'four words' => ['a very big cat'];
        yield 'digits' => ['42'];
        yield 'symbols' => ['<svg>'];
        yield 'too long' => [str_repeat('a', 41)];
    }

    #[DataProvider('unusableAnswers')]
    public function testRejectsAnythingElse(string $answer): void
    {
        self::assertNull(OneWordModel::clean($answer));
    }

    public function testKeepsTheUsableWordsOfAList(): void
    {
        $answer = "<think></think>1. mouse\n2) Keyboard\n- coffee mug\n* Mouse\nHere are some words:\n\n• rubber duck\n42";

        self::assertSame(['mouse', 'Keyboard', 'coffee mug', 'rubber duck'], OneWordModel::cleanList($answer));
    }
}
