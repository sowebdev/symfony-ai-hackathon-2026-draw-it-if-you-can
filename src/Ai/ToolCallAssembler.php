<?php

namespace App\Ai;

use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Rebuilds the tool calls of a streamed answer from its deltas, so that each call can be used as
 * soon as it is written, before the agent runs the tools at the end of the answer. A call is
 * written when the next one starts, or when the stream says all calls are complete.
 *
 * The Generic bridge of Symfony AI 1.0.0-BETA1 drops the arguments of the chunk that starts a call
 * (an elseif in CompletionsConversionTrait::yieldToolCallDeltas, still there on main on 10/11/2026),
 * and llama.cpp puts the opening "{" there. A rebuilt call that does not start with "{" gets it
 * back; one that still cannot be read waits for the complete call the agent sends when it runs the
 * tool. Calls always come out in the order they were started.
 */
final class ToolCallAssembler
{
    /** @var array<string, array{name: string, json: string}> Calls being written */
    private array $open = [];

    /** @var list<string> Ids of the calls written but not yet out, in order */
    private array $queue = [];

    /** @var array<string, array{id: string, name: string, arguments: array<string, mixed>}|null> Null: waiting for the agent */
    private array $written = [];

    /** @var array<string, true> */
    private array $out = [];

    /**
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}> The calls this delta lets out
     */
    public function feed(mixed $delta): array
    {
        if ($delta instanceof ToolCallStart) {
            $this->closeAll();
            $id = $delta->getId();
            if (!isset($this->written[$id]) && !isset($this->out[$id])) {
                $this->open[$id] ??= ['name' => $delta->getName(), 'json' => ''];
            }
        } elseif ($delta instanceof ToolInputDelta && isset($this->open[$delta->getId()])) {
            $this->open[$delta->getId()]['json'] .= $delta->getPartialJson();
        } elseif ($delta instanceof ToolCallComplete) {
            $this->closeAll();
        } elseif ($delta instanceof ToolCall) {
            $this->closeAll();
            $id = $delta->getId();
            if (\array_key_exists($id, $this->written) && null === $this->written[$id]) {
                $this->written[$id] = ['id' => $id, 'name' => $delta->getName(), 'arguments' => $delta->getArguments()];
            }
        }

        return $this->release();
    }

    /**
     * The calls still waiting when the stream ends: those that can be read.
     *
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    public function finish(): array
    {
        $this->closeAll();
        $calls = array_values(array_filter(array_map(fn (string $id): ?array => $this->written[$id], $this->queue)));
        $this->queue = [];

        return $calls;
    }

    private function closeAll(): void
    {
        foreach ($this->open as $id => ['name' => $name, 'json' => $json]) {
            $arguments = self::decode($json);
            $this->written[(string) $id] = null === $arguments ? null : ['id' => (string) $id, 'name' => $name, 'arguments' => $arguments];
            $this->queue[] = (string) $id;
        }
        $this->open = [];
    }

    /**
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    private function release(): array
    {
        $calls = [];
        while ([] !== $this->queue && null !== $this->written[$this->queue[0]]) {
            $id = array_shift($this->queue);
            $calls[] = $this->written[$id];
            $this->out[$id] = true;
        }

        return $calls;
    }

    /**
     * @return array<string, mixed>|null Null if the arguments are not a JSON object
     */
    private static function decode(string $json): ?array
    {
        $json = ltrim($json);
        if (!str_starts_with($json, '{')) {
            $json = '{'.$json;
        }
        $arguments = json_decode($json, true);

        return \is_array($arguments) && [] !== $arguments && !array_is_list($arguments) ? $arguments : null;
    }
}
