<?php

namespace App\Game;

use App\Ai\Link;

/**
 * What the judge says of an answer: accepted, warm ("ça chauffe") or refused. A word that is exact, or one
 * typo away, is accepted by code before any label (WordMatcher); for the others, this table is the only
 * place that turns the label of the model into a verdict.
 */
enum Verdict: string
{
    case Accepted = 'accepted';
    case Warm = 'warm';
    case Refused = 'refused';

    /**
     * @param Link|null $link The label of the judge; null when it gave none: the answer is refused
     * @param bool      $easy The easy mode accepts the synonyms and the very close words, and says warm more often
     */
    public static function of(?Link $link, bool $easy): self
    {
        return match ($link) {
            Link::SameWord => self::Accepted,
            Link::Synonym, Link::VeryClose => $easy ? self::Accepted : self::Warm,
            Link::Related => $easy ? self::Warm : self::Refused,
            Link::Unrelated, null => self::Refused,
        };
    }

    public function isAccepted(): bool
    {
        return self::Accepted === $this;
    }

    public function isWarm(): bool
    {
        return self::Warm === $this;
    }
}
