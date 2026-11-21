<?php

namespace App\Tests\Smoke;

use App\Ai\PlatformFactory;
use App\Ai\Provider;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Test\MockPlatformFactory;

/**
 * The model of the smoke test, whatever the provider: the word is always "cat", the AI guesses "dog",
 * "kitten", then "cat", and the judge finds "kitten" very close to "cat" and everything else unrelated.
 * It keeps no state: each request of the game builds it again, so it reads what was said from the prompt.
 */
final readonly class ScriptedPlatforms implements PlatformFactory
{
    public const string WORD = 'cat';
    public const array GUESSES = ['dog', 'kitten', 'cat'];

    public function create(Provider $provider, ?string $address, #[\SensitiveParameter] ?string $apiKey, ?int $maxDurationS = null): PlatformInterface
    {
        return MockPlatformFactory::createPlatform(static fn ($model, $input): string|ResultInterface => self::answer((string) json_encode($input)));
    }

    private static function answer(string $prompt): string|ResultInterface
    {
        // The checks of the models, on the home page: one stroke, and the shape of an image.
        if (str_contains($prompt, 'with one single stroke')) {
            return self::oneStroke();
        }
        if (str_contains($prompt, 'What shape is drawn')) {
            return 'circle';
        }
        if (str_contains($prompt, 'which link there is')) {
            preg_match('/Answer of the player: \\\\"(.+?)\\\\"/', $prompt, $answer);

            return 'kitten' === ($answer[1] ?? null) ? 'very_close' : 'unrelated';
        }
        if (str_contains($prompt, 'List words the player could draw')) {
            return self::WORD;
        }
        preg_match('/Words already said: (.+?)\. Your word\?/', $prompt, $said);
        $said = 'none yet' === ($said[1] ?? 'none yet') ? [] : explode(', ', $said[1]);

        return array_values(array_diff(self::GUESSES, $said))[0] ?? self::WORD;
    }

    private static function oneStroke(): StreamResult
    {
        $cells = 'x25y10,x40y25,x25y40,x10y25,x25y10';

        return new StreamResult((static function () use ($cells): \Generator {
            yield new ToolCallStart('call_1', 'draw_stroke');
            yield new ToolInputDelta('call_1', 'draw_stroke', json_encode(['part' => 'ring', 'cells' => $cells], \JSON_THROW_ON_ERROR));
            yield new ToolCallComplete([new ToolCall('call_1', 'draw_stroke', ['part' => 'ring', 'cells' => $cells])]);
        })());
    }
}
