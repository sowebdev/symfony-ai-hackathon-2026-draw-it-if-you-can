<?php

namespace App\Ai;

/**
 * The list of the models of a provider cannot be read. The problem is for the player and never
 * contains the API key.
 */
final class ModelListException extends \RuntimeException
{
    public function __construct(public readonly Problem $problem)
    {
        parent::__construct($problem->id);
    }
}
