<?php

namespace App\Ai;

use App\Drawing\GridStroke;
use App\Game\Theme;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

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
     * reads the answer any more (10/11/2026).
     */
    private const int MAX_TOKENS_PER_ANSWER = 1500;

    public function __construct(private Models $models)
    {
    }

    public function draw(string $word, Theme $theme): \Generator
    {
        $max = self::MAX_STROKES;
        $messages = new MessageBag(
            Message::forSystem(<<<PROMPT
                You draw quick sketches for a drawing and guessing game, like a person with a pen. Someone watches the strokes appear one by one and tries to guess what you draw.
                The canvas is a grid of 50 x 50 cells. A cell is named xNyM: x goes from 1 (left) to 50 (right), y goes from 1 (top) to 50 (bottom), like screen coordinates. The centre is x25y25.
                Draw with the draw_stroke tool, ONE call per stroke: the cells the pen goes through, in order, comma-separated (e.g. "x10y12,x15y20,x18y25"). Repeat a cell for a sharp corner, end on the starting cell to close a shape.
                Use the whole canvas, 5 to {$max} strokes, no letters, digits or words. Start with the main shape, then the details that make it recognizable.
                When the sketch is done, answer "done" without calling the tool.
                PROMPT),
            Message::ofUser("Theme: {$theme->description()}. Draw: {$word}"),
        );

        $agent = $this->models->agent(Role::Drawer, new Pen($max), maxToolCalls: $max + 5);
        $execution = $agent->call($messages, $this->models->options(Role::Drawer, ['stream' => true, 'temperature' => 0.7, 'max_tokens' => self::MAX_TOKENS_PER_ANSWER]));

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
     * @param array{id: string, name: string, arguments: array<string, mixed>} $call
     */
    private static function stroke(array $call): ?GridStroke
    {
        ['name' => $name, 'arguments' => $arguments] = $call;
        if ('draw_stroke' !== $name || !\is_string($arguments['cells'] ?? null)) {
            return null;
        }

        return GridStroke::fromWritten(\is_string($arguments['part'] ?? null) ? $arguments['part'] : '', $arguments['cells']);
    }
}
