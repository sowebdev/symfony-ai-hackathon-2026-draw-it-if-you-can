<?php

namespace App\Ai;

use App\Drawing\GridStroke;
use App\Drawing\StrokeRenderer;
use App\Drawing\StrokeTracer;
use App\Game\Theme;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * The model draws with the draw_stroke tool, on the 50 x 50 grid of SketchAgent, with our own
 * prompt. The answer is streamed: each stroke comes out as soon as its call is written, at the
 * rhythm of a hand (test B, 10/11/2026: one stroke every 2 to 3 s with Qwen, no reasoning).
 */
final readonly class Drawer implements DrawsStrokes
{
    public const int MAX_STROKES = 20;

    /**
     * Longest answer of the model, between two runs of the tool. A stroke takes 40 to 100 tokens;
     * without a cap, Gemma once looped for 95,000 tokens, and llama.cpp goes on even when nobody
     * reads the answer any more (10/11/2026). Room for a cloud model that reasons before it draws.
     */
    private const int MAX_TOKENS_PER_ANSWER = 4000;

    public function __construct(
        private Models $models,
        private StrokeTracer $tracer,
        private StrokeRenderer $renderer,
    ) {
    }

    /**
     * Strokes for one call: the first sketch, then a few details at a time.
     */
    public const int MAX_DETAIL_STROKES = 3;

    public function draw(string $word, Theme $theme, array $drawn = [], array $wrongAnswers = []): \Generator
    {
        $max = [] === $drawn ? self::MAX_STROKES : self::MAX_DETAIL_STROKES;
        $messages = new MessageBag(
            Message::forSystem(<<<PROMPT
                You draw quick sketches for a drawing and guessing game, like a person with a pen. Someone watches the strokes appear one by one and tries to guess what you draw.
                The canvas is a grid of 50 x 50 cells. A cell is named xNyM: x goes from 1 (left) to 50 (right), y goes from 1 (top) to 50 (bottom), like screen coordinates. The centre is x25y25.
                Draw with the draw_stroke tool, ONE call per stroke: the cells the pen goes through, in order, comma-separated (e.g. "x10y12,x15y20,x18y25"). Repeat a cell for a sharp corner, end on the starting cell to close a shape.
                No letters, digits or words.
                {$this->task($drawn, $wrongAnswers, $max)}
                PROMPT),
            $this->request($word, $theme, $drawn),
        );

        $agent = $this->models->agent(Role::Drawer, new Pen($max), maxToolCalls: $max + 5, maxDurationS: null);
        $execution = $agent->call($messages, $this->models->options(Role::Drawer, ['stream' => true], self::MAX_TOKENS_PER_ANSWER));

        $assembler = new ToolCallAssembler();
        $drawn = 0;
        foreach ($execution as $update) {
            $calls = $assembler->feed($update instanceof Progress ? $update->getPayload() : null);
            foreach ($calls as $call) {
                if (null !== $stroke = self::stroke($call)) {
                    yield $stroke;
                    if (++$drawn >= $max) {
                        return;
                    }
                }
            }
        }
        foreach ($assembler->finish() as $call) {
            if ($drawn < $max && null !== $stroke = self::stroke($call)) {
                yield $stroke;
                ++$drawn;
            }
        }
    }

    /**
     * What to draw, and for details, the drawing so far as an image: without it, a model adds its
     * details blind and draws over its own strokes (Qwen 3.8, 13/11/2026).
     *
     * @param list<GridStroke> $drawn
     */
    private function request(string $word, Theme $theme, array $drawn): UserMessage
    {
        $text = "Theme: {$theme->description()}. Draw: {$word}";
        if ([] === $drawn) {
            return Message::ofUser($text);
        }
        $strokes = [];
        foreach ($drawn as $i => $stroke) {
            $strokes[] = $this->tracer->draw($stroke, 0, $i + 1)->points;
        }

        return Message::ofUser($text.'. Your drawing so far, with the lines of the grid:', new Image($this->renderer->toPng($strokes, grid: true), 'image/png'));
    }

    /**
     * @param list<GridStroke> $drawn
     * @param list<string>     $wrongAnswers
     */
    private function task(array $drawn, array $wrongAnswers, int $max): string
    {
        if ([] === $drawn) {
            return <<<TASK
                Use the whole canvas, 5 to {$max} strokes. Start with the main shape, then the details that make it recognizable.
                When the sketch is done, answer "done" without calling the tool.
                TASK;
        }

        $strokes = implode("\n", array_map(
            static fn (GridStroke $stroke): string => \sprintf('- %s: %s', '' === $stroke->part ? 'stroke' : $stroke->part, $stroke->written),
            $drawn,
        ));
        $aim = [] === $wrongAnswers
            ? 'The player has not answered yet. Add the most telling part that is still missing, or one thing it is usually seen with.'
            : 'The player answered, wrongly: '.implode(', ', $wrongAnswers).'. Think of what the thing you draw has and these have not, and draw that.';

        return <<<TASK
            You already drew these strokes, they stay on the canvas, and you see them in the image:
            {$strokes}
            Look at the image first: place what you add where it belongs, in the free space or on the right part, never on top of what is there.
            Nobody has guessed it yet. {$aim}
            Add 1 to {$max} strokes. Each one is a new part, with a name that is not in the list above.
            No texture, shading, shine, crumbs, drops or other repeated marks: they make a drawing busier, not clearer. Do not redraw what is there.
            When you have added them, or if nothing would help, answer "done" without calling the tool.
            TASK;
    }

    /**
     * @param array{id: string, name: string, arguments: array<string, mixed>} $call
     */
    public static function stroke(array $call): ?GridStroke
    {
        ['name' => $name, 'arguments' => $arguments] = $call;
        if ('draw_stroke' !== $name || !\is_string($arguments['cells'] ?? null)) {
            return null;
        }

        return GridStroke::fromWritten(\is_string($arguments['part'] ?? null) ? $arguments['part'] : '', $arguments['cells']);
    }
}
