<?php

namespace App\Tests\Unit\Ai;

use App\Ai\ToolCallAssembler;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\ToolCall;

final class ToolCallAssemblerTest extends TestCase
{
    public function testACallIsCompleteWhenTheNextOneStarts(): void
    {
        $assembler = new ToolCallAssembler();

        self::assertSame([], $assembler->feed(new ToolCallStart('a', 'draw_stroke')));
        self::assertSame([], $assembler->feed(new ToolInputDelta('a', 'draw_stroke', '{"part":"head",')));
        self::assertSame([], $assembler->feed(new ToolInputDelta('a', 'draw_stroke', '"cells":"x1y1"}')));
        self::assertSame(
            [['id' => 'a', 'name' => 'draw_stroke', 'arguments' => ['part' => 'head', 'cells' => 'x1y1']]],
            $assembler->feed(new ToolCallStart('b', 'draw_stroke')),
        );

        $assembler->feed(new ToolInputDelta('b', 'draw_stroke', '{"part":"tail","cells":"x2y2"}'));
        $completed = $assembler->feed(new ToolCallComplete([new ToolCall('b', 'draw_stroke', [])]));
        self::assertSame(['part' => 'tail', 'cells' => 'x2y2'], $completed[0]['arguments']);

        self::assertSame([], $assembler->finish(), 'Each call comes out once');
    }

    public function testTheLastCallComesOutWhenTheStreamEnds(): void
    {
        $assembler = new ToolCallAssembler();
        $assembler->feed(new ToolCallStart('a', 'draw_stroke'));
        $assembler->feed(new ToolInputDelta('a', 'draw_stroke', '{"part":"body","cells":"x3y3"}'));
        $assembler->feed(new TextDelta('ignored'));

        self::assertSame('a', $assembler->finish()[0]['id'] ?? null);
    }

    public function testArgumentsThatAreNotAnObjectAreDropped(): void
    {
        $assembler = new ToolCallAssembler();
        $assembler->feed(new ToolCallStart('a', 'draw_stroke'));
        $assembler->feed(new ToolInputDelta('a', 'draw_stroke', '{"part": "cut in the mid'));

        self::assertSame([], $assembler->finish());
    }

    public function testTheOpeningBraceDroppedByTheBridgeIsPutBack(): void
    {
        // What the Generic bridge of the beta passes on from llama.cpp: the first chunk is lost.
        $assembler = new ToolCallAssembler();
        $assembler->feed(new ToolCallStart('a', 'draw_stroke'));
        $assembler->feed(new ToolInputDelta('a', 'draw_stroke', '"cells": "x1y1"}'));

        self::assertSame(['cells' => 'x1y1'], $assembler->feed(new ToolCallStart('b', 'draw_stroke'))[0]['arguments']);
    }

    public function testAnUnreadableCallWaitsForTheAgentAndKeepsItsPlace(): void
    {
        $assembler = new ToolCallAssembler();
        $assembler->feed(new ToolCallStart('a', 'draw_stroke'));
        $assembler->feed(new ToolInputDelta('a', 'draw_stroke', 'lls": "x1y1"}'));
        $assembler->feed(new ToolCallStart('b', 'draw_stroke'));
        $assembler->feed(new ToolInputDelta('b', 'draw_stroke', '{"cells": "x2y2"}'));

        // "b" is written, but "a" comes first: both wait.
        self::assertSame([], $assembler->feed(new ToolCallComplete([])));

        $calls = $assembler->feed(new ToolCall('a', 'draw_stroke', ['cells' => 'x1y1']));
        self::assertSame(['a', 'b'], array_column($calls, 'id'));
        self::assertSame(['cells' => 'x1y1'], $calls[0]['arguments']);

        // The agent runs "b" too: it does not come out twice.
        self::assertSame([], $assembler->feed(new ToolCall('b', 'draw_stroke', ['cells' => 'x2y2'])));
    }
}
