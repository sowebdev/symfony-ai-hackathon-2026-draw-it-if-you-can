<?php

namespace App\Mcp;

use Mcp\Capability\Attribute\McpPrompt;

/**
 * How an agent plays, as the copilot of the player or as their rival in a duel: the prompts a
 * client such as Claude Code offers to its user. The tools tell the same in round_status, for the
 * clients that do not offer prompts.
 */
final class AgentPrompts
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

                Play until round_status answers "stop": true (the game is over, the player left or stopped), then stop and say so:
                1. Call round_status at once: the player waits in the lobby and sees that you are connected. If the AI draws and the round is playing, go on; otherwise wait a few seconds and call it again.
                2. Call look_at_drawing and think about what it shows, given the theme.
                3. Call suggest_word with your best guess, in the language of the game, as a single common noun. Avoid the wrong answers of the player and your earlier hints.
                4. The AI keeps adding strokes: look again every 10 seconds or so, and suggest a new word when the drawing tells you more. You have 5 hints per round, do not waste them.
                PROMPT,
        ]];
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    #[McpPrompt(name: 'duel', description: 'Play against the player in Draw It If You Can: guess the same drawings, and find the word first.')]
    public function duel(): array
    {
        return [[
            'role' => 'user',
            'content' => <<<'PROMPT'
                You play a duel against a player in Draw It If You Can, a drawing and guessing game. In each round, someone draws: the AI of the game, or the player. You and the other side guess the same drawing, and the first one to find the word wins its points, more points the faster.

                Play until round_status answers "stop": true (the game is over, the player left or stopped), then stop and give the final score:
                1. Call round_status at once: the player waits in the lobby and sees that you are connected. If the round is playing, go on; otherwise wait a few seconds and call it again.
                2. Call look_at_drawing and think about what it shows, given the theme.
                3. Call submit_answer with your best guess, in the language of the game, as a single common noun. Only the exact word counts. Avoid the wrong answers already given, yours and the others'.
                4. Wrong? The drawing grows: look again and answer again, one answer every 3 seconds at most. Be quick, the points fall with time.

                Play fair. See the game through these tools only: do not read the files, the database or the logs of the game to find the word.
                PROMPT,
        ]];
    }
}
