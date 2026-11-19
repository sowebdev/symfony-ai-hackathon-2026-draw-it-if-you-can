<?php

namespace App\Game;

/**
 * A word the model listed, split from its indefinite article ("un château fort"): the word is what
 * counts for the answer, the article is only shown. A candidate without article stays as it is.
 */
final readonly class WordCandidate
{
    /** Indefinite articles by language, the elided ones ending with an apostrophe. */
    private const array ARTICLES = [
        'en' => ['a', 'an'],
        'fr' => ['un', 'une', 'des'],
        'de' => ['ein', 'eine'],
        'es' => ['un', 'una', 'unos', 'unas'],
        'it' => ['un', 'uno', 'una', "un'"],
    ];

    public function __construct(
        public string $word,
        public ?string $article = null,
    ) {
    }

    /**
     * Whether a line starts with an indefinite article of one of the languages.
     */
    public static function hasArticle(string $line): bool
    {
        if (!preg_match("/^\\s*(\\p{L}+)(?:['’]|\\s)/u", $line, $match)) {
            return false;
        }
        $first = mb_strtolower($match[1]);
        foreach (self::ARTICLES as $articles) {
            if (\in_array($first, $articles, true) || \in_array($first."'", $articles, true)) {
                return true;
            }
        }

        return false;
    }

    public static function parse(string $candidate, Language $language): self
    {
        $candidate = trim($candidate);
        // "un'amica": the article is glued to the word.
        if (preg_match("/^(\\p{L}+)['’]\\s*(\\p{L}.*)$/u", $candidate, $match) && \in_array(mb_strtolower($match[1]."'"), self::ARTICLES[$language->value], true)) {
            return new self($match[2], mb_strtolower($match[1])."'");
        }
        if (preg_match('/^(\p{L}+)\s+(\S.*)$/u', $candidate, $match) && \in_array(mb_strtolower($match[1]), self::ARTICLES[$language->value], true)) {
            return new self($match[2], mb_strtolower($match[1]));
        }

        return new self($candidate);
    }
}
