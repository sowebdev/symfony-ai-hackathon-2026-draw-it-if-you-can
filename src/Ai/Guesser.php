<?php

namespace App\Ai;

use App\Game\Language;
use App\Game\Theme;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * The AI looks at the drawing in progress and calls out one new word, like a player around the table.
 */
final readonly class Guesser
{
    public function __construct(private OneWordModel $model)
    {
    }

    /**
     * @param list<string> $alreadySaid
     *
     * @return string|null A word, or null if the model gave no usable one
     */
    public function guess(string $png, Theme $theme, Language $language, array $alreadySaid): ?string
    {
        $said = [] === $alreadySaid ? 'none yet' : implode(', ', $alreadySaid);

        $messages = new MessageBag(
            Message::forSystem(<<<PROMPT
                You play a drawing and guessing game, like a player around the table. Someone is drawing, and you call out what you think the drawing shows.
                Theme: {$theme->description()}.
                Answer with one single noun in {$language->nameInEnglish()}, your best guess, without article. Never repeat a word already said: if you still see the same thing, say a close alternative.
                PROMPT),
            Message::ofUser(
                new Image($png, 'image/png'),
                "Words already said: {$said}. Your word?",
            ),
        );

        return $this->model->ask($messages, 0.7);
    }
}
