<?php

namespace App\Game;

/**
 * Tells whether a guess is the word to find, without asking anyone: case, accents, a leading
 * article and a plural do not count ("Les Chats" finds "chat"). A near word ("kitten" for "cat")
 * does not count. The plurals of German, Spanish and Italian are not handled (only the final "s" or "x").
 */
final class WordMatcher
{
    private const array ARTICLES = [
        'a', 'an', 'the', 'le', 'la', 'les', 'l', 'un', 'une', 'des', 'du',
        'der', 'die', 'das', 'den', 'dem', 'ein', 'eine', 'einen', 'einem', 'einer',
        'el', 'los', 'las', 'una', 'unos', 'unas',
        'il', 'lo', 'gli', 'i', 'uno',
    ];

    public function matches(string $guess, string $target): bool
    {
        $guess = self::normalize($guess);

        return '' !== $guess && $guess === self::normalize($target);
    }

    public static function normalize(string $word): string
    {
        $word = (string) transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC; Lower()', $word);
        $word = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $word));
        if ('' === $word) {
            return '';
        }

        $parts = explode(' ', $word);
        if (\count($parts) > 1 && \in_array($parts[0], self::ARTICLES, true)) {
            array_shift($parts);
        }

        return implode(' ', array_map(self::singular(...), $parts));
    }

    private static function singular(string $part): string
    {
        if (mb_strlen($part) > 3 && \in_array(mb_substr($part, -1), ['s', 'x'], true)) {
            return mb_substr($part, 0, -1);
        }

        return $part;
    }
}
