<?php

namespace App\Ai;

/**
 * The list of the models of a provider cannot be read. The message is for the player and never
 * contains the API key.
 */
final class ModelListException extends \RuntimeException
{
}
