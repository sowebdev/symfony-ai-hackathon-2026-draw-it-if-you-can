<?php

namespace App\Mcp;

use Mcp\Capability\Attribute\McpPrompt;

/**
 * How an agent plays the copilot: the prompt a client such as Claude Code offers to its user.
 */
final class CopilotPrompt
{
    /**
     * @return list<array{role: string, content: string}>
     */
    #[McpPrompt(name: 'copilot', description: 'Play the copilot of the player in Draw It If You Can: watch the drawing of the AI and whisper your guesses.')]
    public function copilot(): array
    {
        return [[
            'role' => 'user',
            'content' => <<<'PROMPT'
                You are the copilot of a player in Draw It If You Can, a drawing and guessing game against an AI. When the AI draws, the player guesses, and you help by whispering words; the player decides what to answer.

                Play until the game is over:
                1. Call round_status. If the AI draws and the round is playing, go on; otherwise wait a few seconds and call it again.
                2. Call look_at_drawing and think about what it shows, given the theme.
                3. Call suggest_word with your best guess, in the language of the game, as a single common noun. Avoid the wrong answers of the player and your earlier hints.
                4. The AI keeps adding strokes: look again every 10 seconds or so, and suggest a new word when the drawing tells you more. You have 5 hints per round, do not waste them.
                PROMPT,
        ]];
    }
}
