<?php

namespace App\Tests\Functional;

use App\Ai\Drawer;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Replaces the models with a script: the word to draw is "cat", the guesses are taken in order,
 * and the AI draws the given strokes.
 */
trait ScriptedModel
{
    /**
     * @param list<string>                $guesses
     * @param list<array{string, string}> $strokes        [part, cells] pairs, as the model writes them
     * @param \Throwable|null             $drawingFailure Thrown by the drawing model after its strokes
     * @param list<array{string, string}> $details        Strokes drawn each time the AI is asked for details
     */
    private static function scriptModel(KernelBrowser $client, array $guesses = [], string $word = 'cat', array $strokes = [], ?\Throwable $drawingFailure = null, array $details = []): ScriptedDrawer
    {
        // The model lives in the container: it must survive from one request to the next.
        $client->disableReboot();

        $platform = MockPlatformFactory::createPlatform(
            static function ($model, $input) use ($word, &$guesses): string {
                if (str_contains((string) json_encode($input), 'List words the player could draw')) {
                    return $word;
                }

                return array_shift($guesses) ?? 'nothing';
            },
        );
        $client->getContainer()->set('ai.platform.generic.guesser', $platform);
        $client->getContainer()->set('ai.platform.generic.drawer', $platform);
        $client->getContainer()->set(Drawer::class, $drawer = new ScriptedDrawer($strokes, $drawingFailure, $details));

        return $drawer;
    }
}
