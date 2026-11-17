<?php

namespace App\Ai;

use App\Drawing\StrokeRenderer;
use App\Game\WordMatcher;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Tries the model of each role on a tiny task whose answer is known, and lets code judge it:
 * an endpoint does not tell whether its model sees images, and some silently drop them.
 */
final readonly class ModelCheck
{
    /** What a model that sees the circle may answer, once normalized. */
    private const array CIRCLE = ['circle', 'ring', 'round', 'oval', 'o', 'zero', 'cercle', 'rond', 'anneau', 'ovale'];

    public function __construct(
        private OneWordModel $model,
        private StrokeRenderer $renderer,
    ) {
    }

    /**
     * @return string|null What is wrong, or null if the role can play
     */
    public function check(Role $role): ?string
    {
        // Both roles read images: the guesser names the drawing, the drawer looks at its own to add
        // details. Tool calling is checked once the AI draws.
        return $this->checkSeesImages($role);
    }

    /**
     * A word for "what shape is this?", judged by code.
     */
    public static function isCircle(?string $answer): bool
    {
        return null !== $answer && \in_array(WordMatcher::normalize($answer), self::CIRCLE, true);
    }

    /**
     * A black circle on white, in canonical strokes: the simplest drawing to name.
     *
     * @return non-empty-list<array{int, int, int}>
     */
    public static function circle(): array
    {
        return array_map(static function (int $i): array {
            $angle = 2 * M_PI * $i / 36;

            return [(int) round(500 + 300 * cos($angle)), (int) round(500 + 300 * sin($angle)), $i * 20];
        }, range(0, 36));
    }

    /** Said when a model gives nothing usable: the most likely cause after a missing skill. */
    private const string REASONING_HINT = 'A model that reasons before answering may spend all its tokens on it: choose one that does not reason.';

    private function checkSeesImages(Role $role): ?string
    {
        $messages = new MessageBag(Message::ofUser(
            new Image($this->renderer->toPng([self::circle()]), 'image/png'),
            'What shape is drawn in this image? Answer with one word in English.',
        ));
        $answer = $this->model->ask($role, $messages);

        if (null === $answer) {
            return 'The model gave no usable answer about an image: it must be a model that reads images. '.self::REASONING_HINT;
        }

        return self::isCircle($answer) ? null : \sprintf('The model does not recognize a circle in an image (it answered "%s"): it must be a model that reads images.', $answer);
    }
}
