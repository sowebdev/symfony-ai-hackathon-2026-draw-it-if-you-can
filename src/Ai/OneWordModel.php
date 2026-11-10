<?php

namespace App\Ai;

use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks the model for a single word, and checks the answer by code: the model never decides
 * what the game accepts.
 */
final readonly class OneWordModel
{
    private const int MAX_LENGTH = 40;
    private const int MAX_WORDS = 3;

    public function __construct(
        private PlatformInterface $platform,
        #[Autowire('%env(AI_MODEL)%')]
        private string $model,
    ) {
    }

    /**
     * @return string|null The word, or null if the answer is not a single word
     */
    public function ask(MessageBag $messages, float $temperature): ?string
    {
        $answer = $this->platform->invoke($this->model, $messages, [
            // No reasoning: the game needs a word every few seconds (tests of 10/11/2026).
            'chat_template_kwargs' => ['enable_thinking' => false],
            'temperature' => $temperature,
            'max_tokens' => 20,
        ])->asText();

        return self::clean($answer);
    }

    public static function clean(string $answer): ?string
    {
        $answer = (string) preg_replace('/<think>.*?<\/think>/s', '', $answer);
        $line = strtok(trim($answer), "\n");
        $word = trim(false === $line ? '' : $line, " \t\"'`*.,;:!?«»“”");

        if ('' === $word || mb_strlen($word) > self::MAX_LENGTH || !preg_match("/^\p{L}[\p{L}\p{M}'’ -]*$/u", $word)) {
            return null;
        }
        if (\count(preg_split('/\s+/', $word) ?: []) > self::MAX_WORDS) {
            return null;
        }

        return $word;
    }
}
