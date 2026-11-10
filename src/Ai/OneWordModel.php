<?php

namespace App\Ai;

use App\Game\WordMatcher;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Asks the model for a single word, and checks the answer by code: the model never decides
 * what the game accepts.
 */
final readonly class OneWordModel
{
    private const int MAX_LENGTH = 40;
    private const int MAX_WORDS = 3;

    public function __construct(private Models $models)
    {
    }

    /**
     * @return string|null The word, or null if the answer is not a single word
     */
    public function ask(Role $role, MessageBag $messages, float $temperature): ?string
    {
        $answer = $this->models->invoke($role, $messages, [
            'temperature' => $temperature,
            'max_tokens' => 20,
        ])->asText();

        return self::clean($answer);
    }

    /**
     * Asks for a list, one word per line, and keeps the usable words, each once.
     *
     * @return list<string>
     */
    public function askList(Role $role, MessageBag $messages, float $temperature): array
    {
        $answer = $this->models->invoke($role, $messages, [
            'temperature' => $temperature,
            'max_tokens' => 300,
        ])->asText();

        return self::cleanList($answer);
    }

    /**
     * @return list<string>
     */
    public static function cleanList(string $answer): array
    {
        $words = [];
        foreach (explode("\n", self::withoutReasoning($answer)) as $line) {
            // Numbered or bulleted lists: "1. mouse", "- mouse".
            $line = (string) preg_replace('/^\s*(?:\d+[.)]|[-*•])\s*/u', '', $line);
            $word = self::clean($line);
            if (null !== $word) {
                $words[WordMatcher::normalize($word)] ??= $word;
            }
        }

        return array_values($words);
    }

    public static function clean(string $answer): ?string
    {
        $answer = self::withoutReasoning($answer);
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

    private static function withoutReasoning(string $answer): string
    {
        return (string) preg_replace('/<think>.*?<\/think>/s', '', $answer);
    }
}
