<?php

namespace App\Game;

/**
 * The link between an answer and the word to find: a word of the game, which the judge's model only
 * chooses, as exactly one of these labels (App\Ai\Judge). What the game accepts is decided by code (Verdict).
 */
enum Link: string
{
    /** The same word misspelled, or in another gender or number: "lionne" for "lion". */
    case SameWord = 'same_word';
    case Synonym = 'synonym';
    case VeryClose = 'very_close';
    case Related = 'related';
    case Unrelated = 'unrelated';
}
