<?php

namespace App\Ai;

use App\Drawing\StrokeRenderer;
use App\Game\WordMatcher;
use Symfony\AI\Agent\Exception\MaxIterationsExceededException;
use Symfony\AI\Agent\Execution\Update\Progress;
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

    /** Room for a model that reasons a little before its stroke; a ceiling, not a budget. */
    private const int MAX_TOKENS_FOR_A_STROKE = 2000;

    public function __construct(
        private OneWordModel $model,
        private StrokeRenderer $renderer,
        private Models $models,
    ) {
    }

    /**
     * The same checks on other models: the setting of the model screen, not saved yet.
     */
    public function with(Models $models): self
    {
        return new self(new OneWordModel($models), $this->renderer, $models);
    }

    /**
     * @return Problem|null What is wrong, or null if the role can play
     */
    public function check(Role $role): ?Problem
    {
        // Both roles read images: the guesser names the drawing, the drawer looks at its own to add
        // details. The drawer must also call a tool, or it cannot draw at all.
        $problem = $this->checkSeesImages($role);

        return null === $problem && Role::Drawer === $role ? $this->checkCallsTools() : $problem;
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

    /**
     * Said when a model gives nothing usable, the most likely cause after a missing skill: it spent
     * its tokens reasoning. Where there is a switch, which way to flip it.
     */
    private function reasoningAdvice(Role $role): string
    {
        $setup = $this->models->setup($role);
        if (!$setup->provider->hasReasoningSwitch()) {
            return 'advice.reasoning_choose';
        }

        return $setup->reasoningOff ? 'advice.reasoning_choose' : 'advice.reasoning_tick';
    }

    /**
     * One stroke on a circle, asked the way the game asks for the drawing: streamed, with the
     * draw_stroke tool. The check stops at the first stroke it can read.
     */
    private function checkCallsTools(): ?Problem
    {
        $messages = new MessageBag(
            Message::forSystem(<<<PROMPT
                You draw with the draw_stroke tool on a canvas of 50 x 50 cells, each named xNyM (x from 1 on the left to 50, y from 1 at the top to 50). The centre is x25y25.
                ONE call to the tool draws one stroke: the cells the pen goes through, in order, comma-separated (e.g. "x10y12,x15y20,x18y25"). End on the starting cell to close a shape.
                PROMPT),
            Message::ofUser('Draw a circle in the middle of the canvas, with one single stroke. Then answer "done".'),
        );
        $execution = $this->models->agent(Role::Drawer, new Pen(1), maxToolCalls: 3)
            ->call($messages, $this->models->options(Role::Drawer, ['stream' => true], self::MAX_TOKENS_FOR_A_STROKE));

        $assembler = new ToolCallAssembler();
        try {
            foreach ($execution as $update) {
                foreach ($assembler->feed($update instanceof Progress ? $update->getPayload() : null) as $call) {
                    if (null !== Drawer::stroke($call)) {
                        return null;
                    }
                }
            }
        } catch (MaxIterationsExceededException) {
            // It keeps calling the tool with cells the pen cannot read: no stroke came out.
        }
        foreach ($assembler->finish() as $call) {
            if (null !== Drawer::stroke($call)) {
                return null;
            }
        }

        return new Problem('problem.no_stroke', advice: $this->reasoningAdvice(Role::Drawer));
    }

    private function checkSeesImages(Role $role): ?Problem
    {
        $messages = new MessageBag(Message::ofUser(
            new Image($this->renderer->toPng([self::circle()]), 'image/png'),
            'What shape is drawn in this image? Answer with one word in English.',
        ));
        $answer = $this->model->ask($role, $messages);

        if (null === $answer) {
            return new Problem('problem.no_image_answer', advice: $this->reasoningAdvice($role));
        }

        return self::isCircle($answer) ? null : new Problem('problem.not_a_circle', ['%answer%' => $answer]);
    }
}
