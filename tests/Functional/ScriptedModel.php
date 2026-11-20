<?php

namespace App\Tests\Functional;

use App\Ai\Drawer;
use App\Ai\ModelSettings;
use App\Ai\PlatformFactory;
use App\Ai\Provider;
use App\Ai\Role;
use App\Game\Dealer;
use Random\Engine;
use Random\Randomizer;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Chooses a model and replaces it with a script: the word to draw is "cat", the guesses are taken in order,
 * and the AI draws the given strokes. The dealer always takes its first choice: the AI draws first
 * unless the test says otherwise, and the first round is about animals.
 */
trait ScriptedModel
{
    /**
     * @param list<string>                          $guesses
     * @param list<array{string, string}>           $strokes        [part, cells] pairs, as the model writes them
     * @param \Throwable|null                       $drawingFailure Thrown by the drawing model after its strokes
     * @param list<array{string, string}>           $details        Strokes drawn each time the AI is asked for details
     * @param bool                                  $chosen         Whether a model is stored (false: the game has none yet)
     * @param \ArrayObject<string, \Throwable>|null $outage         Set its "failure" to make the model fail at each call from then on: a model that never answers
     * @param \ArrayObject<int, mixed>|null         $calls          Gets the input of each call to the model
     * @param \ArrayObject<string, mixed>|null      $judgement      What the judge answers, in its "label" (a link, "unrelated" by default), or a \Throwable it throws; the judge's calls never take a scripted guess
     */
    private static function scriptModel(KernelBrowser $client, array $guesses = [], string $word = 'cat', array $strokes = [], ?\Throwable $drawingFailure = null, array $details = [], bool $chosen = true, ?\ArrayObject $outage = null, ?\ArrayObject $calls = null, ?\ArrayObject $judgement = null): ScriptedDrawer
    {
        // The model lives in the container: it must survive from one request to the next.
        $client->disableReboot();

        $platform = MockPlatformFactory::createPlatform(
            static function ($model, $input) use ($word, &$guesses, $outage, $calls, $judgement): string|ResultInterface {
                $calls?->append($input);
                if (($failure = $outage['failure'] ?? null) instanceof \Throwable) {
                    throw $failure;
                }
                // The check of the drawer asks for one stroke: the fake model draws it.
                if (str_contains((string) json_encode($input), 'with one single stroke')) {
                    return self::oneStroke();
                }
                // The judge asks for a label: it takes no scripted guess.
                if (str_contains((string) json_encode($input), 'which link there is')) {
                    $label = $judgement['label'] ?? 'unrelated';
                    if ($label instanceof \Throwable) {
                        throw $label;
                    }

                    return (string) $label;
                }
                if (str_contains((string) json_encode($input), 'List words the player could draw')) {
                    return $word;
                }

                return array_shift($guesses) ?? 'nothing';
            },
        );
        $client->getContainer()->set(PlatformFactory::class, new ScriptedPlatforms($platform));
        // The stored setting is the only source of the model: one is chosen, the platform behind it is the script.
        if ($chosen) {
            $settings = $client->getContainer()->get(ModelSettings::class);
            $settings->choose(Role::Drawer, Provider::Compatible, 'http://scripted:8000', 'scripted', true);
            $settings->choose(Role::Guesser, Provider::Compatible, 'http://scripted:8000', 'scripted', true);
        }
        $client->getContainer()->set(Drawer::class, $drawer = new ScriptedDrawer($strokes, $drawingFailure, $details));
        $client->getContainer()->set(Dealer::class, new Dealer(new Randomizer(new class implements Engine {
            public function generate(): string
            {
                return "\0\0\0\0\0\0\0\0";
            }
        })));

        return $drawer;
    }

    /**
     * The answer of a drawer that calls its tool once, as a model streams it.
     */
    private static function oneStroke(): StreamResult
    {
        $cells = 'x25y10,x40y25,x25y40,x10y25,x25y10';

        return new StreamResult((static function () use ($cells): \Generator {
            yield new ToolCallStart('call_1', 'draw_stroke');
            yield new ToolInputDelta('call_1', 'draw_stroke', json_encode(['part' => 'ring', 'cells' => $cells], \JSON_THROW_ON_ERROR));
            yield new ToolCallComplete([new ToolCall('call_1', 'draw_stroke', ['part' => 'ring', 'cells' => $cells])]);
        })());
    }

    /**
     * Starts a game as the home form does, through the page where the card of its first theme
     * turns over, and returns the URL of its first round.
     *
     * @param array<string, string> $form
     */
    private static function startFirstRound(KernelBrowser $client, string $locale = 'en', array $form = []): string
    {
        $client->request('POST', "/{$locale}/games", $form);
        self::assertResponseRedirects();
        $client->request('POST', $client->getResponse()->headers->get('Location').'/rounds');
        self::assertResponseRedirects();

        return (string) $client->getResponse()->headers->get('Location');
    }
}
