<?php

namespace App\Game;

/**
 * Tells whether a guess is the word to find, without asking anyone: case, accents, a leading
 * article and a plural do not count ("Les Chats" finds "chat"), and one typo is forgiven when the
 * word to find has six letters or more ("elefant" finds "elephant"). A near word ("kitten" for
 * "cat") does not count here: that is for the judge (App\Ai\Judge). The plurals of German, Spanish and Italian are not handled (only the final "s" or "x").
 */
final class WordMatcher
{
    private const array ARTICLES = [
        'a', 'an', 'the', 'le', 'la', 'les', 'l', 'un', 'une', 'des', 'du',
        'der', 'die', 'das', 'den', 'dem', 'ein', 'eine', 'einen', 'einem', 'einer',
        'el', 'los', 'las', 'una', 'unos', 'unas',
        'il', 'lo', 'gli', 'i', 'uno',
    ];

    /** A word shorter than this is never forgiven a typo: "chat" and "chut" are two words. */
    public const int TYPO_MIN_LENGTH = 6;

    public function matches(string $guess, string $target): bool
    {
        $guess = self::normalize($guess);
        if ('' === $guess) {
            return false;
        }
        $target = self::normalize($target);

        return $guess === $target || (mb_strlen($target) >= self::TYPO_MIN_LENGTH && self::oneEditApart($guess, $target));
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

    /**
     * Whether two different words are one edit apart: a letter more, less or changed.
     */
    private static function oneEditApart(string $a, string $b): bool
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        if (abs(\count($a) - \count($b)) > 1) {
            return false;
        }
        $start = 0;
        $min = min(\count($a), \count($b));
        while ($start < $min && $a[$start] === $b[$start]) {
            ++$start;
        }
        $a = \array_slice($a, $start);
        $b = \array_slice($b, $start);
        if (\count($a) === \count($b)) {
            // Same length: one letter changed.
            return \array_slice($a, 1) === \array_slice($b, 1);
        }
        [$long, $short] = \count($a) > \count($b) ? [$a, $b] : [$b, $a];

        // The longer one has one letter too many.
        return \array_slice($long, 1) === $short;
    }
}
