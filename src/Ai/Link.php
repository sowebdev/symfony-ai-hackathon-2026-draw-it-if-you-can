<?php

namespace App\Ai;

/**
 * The link between an answer and the word to find, as the judge says it: exactly one of these labels.
 * The model only names the link; what the game accepts is decided by code (App\Game\Verdict).
 */
enum Link: string
{
    /** The same word misspelled, or in another gender or number: "lionne" for "lion". */
    case SameWord = 'same_word';
    case Synonym = 'synonym';
    case VeryClose = 'very_close';
    case Related = 'related';
    case Unrelated = 'unrelated';

    /**
     * Reads the answer of the model: one label, whatever its case and the punctuation around it, once its
     * reasoning is removed. Anything else is no label.
     */
    public static function fromAnswer(string $answer): ?self
    {
        $label = mb_strtolower(trim(OneWordModel::withoutReasoning($answer), " \t\n\r\0\x0B\"'`*.,;:!?"));

        return self::tryFrom($label);
    }
}
