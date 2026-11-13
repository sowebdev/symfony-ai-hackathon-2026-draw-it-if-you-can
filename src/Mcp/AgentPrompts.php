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
    #[McpPrompt(name: 'copilot', description: 'Play the copilot of the player in Draw It If You Can: whisper your guesses when the AI draws, give tips when the player draws.')]
    public function copilot(): array
    {
        return [[
            'role' => 'user',
            'content' => <<<'PROMPT'
                You are the copilot of a player in Draw It If You Can, a drawing and guessing game with an AI. When the AI draws, the player guesses, and you help by whispering words; the player decides what to answer. When the player draws, the AI guesses, and you help the player draw with tips; you know the word then, like the player.

                Play until round_status or wait_for_your_turn answers "stop": true (the game is over, the player left or stopped), then stop and say so:
                1. Call round_status at once: the player waits in the lobby and sees that you are connected.
                2. Choose a funny nickname with choose_nickname right away, in the language of the game: the player sees it in the lobby.
                3. Call wait_for_your_turn. It answers as soon as there is something new for you (a round starts or ends, new strokes on the drawing of the AI, new guesses of the AI on the drawing of the player), or after 25 s with nothing new. Whenever you have nothing to do, call it again. The player may take a few minutes: keep calling it, and never stop on your own while "stop" is false.
                4. When the AI draws and the drawing has new strokes, call look_at_drawing and think about what it shows, given the theme.
                5. Call suggest_word with your best guess, in the language of the game, as a single common noun, when the drawing tells you more than your earlier hints. Avoid the wrong answers of the player and your earlier hints. You have 5 hints per round, do not waste them. Then back to wait_for_your_turn.
                6. When the player draws, call look_at_drawing and read the wrong guesses of the AI in the status. Call give_tip with one short, concrete tip in the language of the game: what to add, change or make bigger so that the AI finds the word (e.g. "Add stripes, the AI sees a horse"). Never just repeat the word: the player knows it. One tip every 5 seconds at most, and only when you have something new to say. Then back to wait_for_your_turn.
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

                Play until round_status or wait_for_your_turn answers "stop": true (the game is over, the player left or stopped), then stop and give the final score:
                1. Call round_status at once: the player waits in the lobby and sees that you are connected.
                2. Choose a funny nickname with choose_nickname right away, in the language of the game: the player plays against it.
                3. Call wait_for_your_turn. It answers as soon as there is something new for you (a round starts or ends, new strokes on the drawing), or after 25 s with nothing new. Whenever you have nothing to do, call it again. The player may take a few minutes: keep calling it, and never stop on your own while "stop" is false.
                4. When the round is playing, call look_at_drawing and think about what it shows, given the theme.
                5. Call submit_answer with your best guess, in the language of the game, as a single common noun. Only the exact word counts. Avoid the wrong answers already given, yours and the others'.
                6. Wrong? Call wait_for_your_turn for new strokes, look again and answer again, one answer every 3 seconds at most. Be quick, the points fall with time.
                Play fair. See the game through these tools only: do not read the files, the database or the logs of the game to find the word.
                PROMPT,
        ]];
    }
}
