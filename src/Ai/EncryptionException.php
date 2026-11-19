<?php

namespace App\Ai;

/**
 * The API key cannot be sealed or opened. The message says how to fix it, and never contains a key.
 */
final class EncryptionException extends \RuntimeException
{
}
