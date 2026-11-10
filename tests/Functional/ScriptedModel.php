<?php

namespace App\Tests\Functional;

use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Replaces the model with a script: the word to draw is "cat", and the guesses are taken in order.
 */
trait ScriptedModel
{
    /**
     * @param list<string> $guesses
     */
    private static function scriptModel(KernelBrowser $client, array $guesses = [], string $word = 'cat'): void
    {
        // The model lives in the container: it must survive from one request to the next.
        $client->disableReboot();

        $client->getContainer()->set('ai.platform.generic.local', MockPlatformFactory::createPlatform(
            static function ($model, $input) use ($word, &$guesses): string {
                if (str_contains((string) json_encode($input), 'Pick the word')) {
                    return $word;
                }

                return array_shift($guesses) ?? 'nothing';
            },
        ));
    }
}
