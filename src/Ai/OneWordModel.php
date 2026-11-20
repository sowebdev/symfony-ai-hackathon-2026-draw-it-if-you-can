<?php

namespace App\Ai;

use App\Game\WordCandidate;
use App\Game\WordMatcher;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Asks the model for a single word or a short phrase (three words at most: "père noël"), and checks the answer by code: the model never decides
 * what the game accepts.
 */
final readonly class OneWordModel
{
    private const int MAX_LENGTH = 40;
    private const int MAX_WORDS = 3;

    /**
     * Ceilings, not budgets: a model stops by itself after its word, a cloud model that reasons
     * spends a few hundred tokens first, and a local model that loops is cut here.
     */
    public const int MAX_TOKENS_FOR_A_WORD = 1000;

    private const int MAX_TOKENS_FOR_A_LIST = 2000;

    public function __construct(private Models $models)
    {
    }

    /**
     * @param int|null $maxDurationS The longest the call may last; null: no limit of its own
     *
     * @return string|null The word or short phrase, or null if the answer is neither
     */
    public function ask(Role $role, MessageBag $messages, ?int $maxDurationS = Models::REQUEST_LIMIT_S): ?string
    {
        // No temperature: the default of each provider suits its models, and some refuse others.
        $answer = $this->models->invoke($role, $messages, maxTokens: self::MAX_TOKENS_FOR_A_WORD, maxDurationS: $maxDurationS)->asText();

        return self::clean($answer);
    }

    /**
     * Asks for a list, one word per line, and keeps the usable words, each once.
     *
     * @param int|null $maxDurationS The longest the call may last; null: no limit of its own
     *
     * @return list<string>
     */
    public function askList(Role $role, MessageBag $messages, ?int $maxDurationS = Models::REQUEST_LIMIT_S): array
    {
        $answer = $this->models->invoke($role, $messages, maxTokens: self::MAX_TOKENS_FOR_A_LIST, maxDurationS: $maxDurationS)->asText();

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
            $word = self::clean($line, WordCandidate::hasArticle($line) ? self::MAX_WORDS + 1 : self::MAX_WORDS);
            if (null !== $word) {
                $words[WordMatcher::normalize($word)] ??= $word;
            }
        }

        return array_values($words);
    }

    /**
     * @param int $maxWords A phrase of this many words at most (a candidate of a list carries its article, one more)
     */
    public static function clean(string $answer, int $maxWords = self::MAX_WORDS): ?string
    {
        $answer = self::withoutReasoning($answer);
        $line = strtok(trim($answer), "\n");
        $word = trim(false === $line ? '' : $line, " \t\"'`*.,;:!?«»“”");

        if ('' === $word || mb_strlen($word) > self::MAX_LENGTH || !preg_match("/^\p{L}[\p{L}\p{M}'’ -]*$/u", $word)) {
            return null;
        }
        if (\count(preg_split('/\s+/', $word) ?: []) > $maxWords) {
            return null;
        }

        return $word;
    }

    public static function withoutReasoning(string $answer): string
    {
        return (string) preg_replace('/<think>.*?<\/think>/s', '', $answer);
    }
}
