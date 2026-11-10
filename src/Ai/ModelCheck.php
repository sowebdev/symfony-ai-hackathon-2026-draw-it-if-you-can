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
        return match ($role) {
            Role::Drawer => $this->checkAnswers(),
            Role::Guesser => $this->checkSeesImages(),
        };
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
     * @return list<array{int, int, int}>
     */
    public static function circle(): array
    {
        $points = [];
        for ($i = 0; $i <= 36; ++$i) {
            $angle = 2 * M_PI * $i / 36;
            $points[] = [(int) round(500 + 300 * cos($angle)), (int) round(500 + 300 * sin($angle)), $i * 20];
        }

        return $points;
    }

    private function checkAnswers(): ?string
    {
        // Tool calling is checked once the AI draws; for now, the model must answer at all.
        $answer = $this->model->ask(Role::Drawer, new MessageBag(Message::ofUser('Answer with the single word: ready')), 0.0);

        return null === $answer ? 'The model gave no usable answer to a one-word question.' : null;
    }

    private function checkSeesImages(): ?string
    {
        $messages = new MessageBag(Message::ofUser(
            new Image($this->renderer->toPng([self::circle()]), 'image/png'),
            'What shape is drawn in this image? Answer with one word in English.',
        ));
        $answer = $this->model->ask(Role::Guesser, $messages, 0.0);

        return self::isCircle($answer) ? null : \sprintf(
            'The model does not recognize a circle in an image (it answered "%s"): it must be a model that reads images.',
            $answer ?? 'nothing usable',
        );
    }
}
