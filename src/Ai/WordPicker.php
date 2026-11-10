<?php

namespace App\Ai;

use App\Game\Language;
use App\Game\Theme;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * The AI picks the word the player will draw, in the theme and the player's language.
 */
final readonly class WordPicker
{
    private const int ATTEMPTS = 3;

    public function __construct(private OneWordModel $model)
    {
    }

    public function pick(Theme $theme, Language $language): string
    {
        $messages = new MessageBag(
            Message::forSystem(<<<PROMPT
                You run a drawing and guessing game. Pick the word the player will draw.
                Theme: {$theme->description()}.
                The word is a concrete noun in {$language->nameInEnglish()}, something one can draw in less than a minute with a black pen, known to everyone.
                Answer with the word only, in lower case, without article.
                PROMPT),
            Message::ofUser('Pick a word.'),
        );

        for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
            // A high temperature, so that two games do not get the same word.
            if (null !== $word = $this->model->ask(Role::Guesser, $messages, 1.0)) {
                return $word;
            }
        }

        throw new \RuntimeException(\sprintf('The model gave no usable word in %d attempts.', self::ATTEMPTS));
    }
}
