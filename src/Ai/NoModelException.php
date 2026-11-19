<?php

namespace App\Ai;

/**
 * No model is chosen yet: the setting stored from the models screen is the only source of the model.
 */
final class NoModelException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No model is chosen yet: choose one on the models screen of the game.');
    }
}
