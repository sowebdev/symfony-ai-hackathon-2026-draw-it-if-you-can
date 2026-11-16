# An agent over MCP: copilot or duel

The game is an MCP server (Symfony MCP Bundle). An agent connected to it plays along, in one of two modes chosen on the home page:

- **With a copilot**: when the AI draws, the agent looks at the drawing and whispers words in the margin. A click on one answers it: you decide, the copilot never answers by itself. When you draw, it knows the word like you, watches your drawing and the wrong guesses of your AI, and gives you tips on post-its, e.g. "make the neck longer, the AI sees a duck".
- **In a duel**: the agent guesses the same drawings as your side, those of the AI and yours, which your AI guesses for you. The first side to find the word wins its points. The agent answers once every 3 seconds at most, and only the exact word counts for it. Its answers show next to yours.

Seven tools and two prompts:

- `round_status`: the round being played, the role of the agent, who draws, the theme, the time left, the wrong answers so far, the score of a duel, and what to do now. Never the word to find while the round is played, except to the copilot of a player who draws, who knows it anyway; once it is over, the word it was. It also tells the agent to stop, when the game is over or the player left. It always answers at once.
- `wait_for_your_turn`: waits for something new for the agent, a round that starts or ends, new strokes on the drawing of the AI, new guesses of your AI while you draw, and answers with the same status as `round_status`, or after 25 s with nothing new. An agent cannot wait by itself between two calls, so the game waits for it, and the agent only has to call again. Why: [ADR 0002](adr/0002-agents-wait-on-the-server.md).
- `look_at_drawing`: the drawing as it is now, as a PNG image.
- `suggest_word`: for a copilot, a word shown to you, at most 5 per round.
- `give_tip`: for a copilot, a tip shown to you while you draw, one every 5 seconds at most.
- `submit_answer`: for a rival, an answer of its own.
- `choose_nickname`: the name you see for the agent, a funny one chosen by the agent in the lobby. Without one, the game shows the name of its client, e.g. "Claude" for `claude-code`.
- the `copilot` and `duel` prompts tell the agent how to play. A client without prompts can start from `round_status`, which explains the rules too.

The agent needs a model that sees images, since it looks at the drawing: a text-only model fails at the first `look_at_drawing` (seen with Mistral Vibe on `zai-glm-5-3`). The game cannot check it, as MCP does not say what the model of a client reads.

The game does not tell two agents apart: recent clients such as Claude Code speak the stateless protocol (2026-07-28), which has no session. Connect one agent at a time.

With Claude Code, over HTTP while the game runs:

```bash
claude mcp add --transport http draw-it http://127.0.0.1:8040/mcp
```

Then choose the mode on the home page: the game waits in a lobby, which repeats these steps and tells you when the agent is connected. Open Claude Code in the folder of the game and run the prompt of the `draw-it` server, `/mcp__draw-it__copilot` or `/mcp__draw-it__duel`, then start the game. Clients that start their servers themselves can use STDIO instead: `docker compose exec -T php php bin/console mcp:server`. The HTTP endpoint only answers on localhost.

With Mistral Vibe, add the server by hand to `~/.vibe/config.toml` (`vibe mcp add` only adds OAuth servers, and the game asks for none):

```toml
[[mcp_servers]]
name = "draw-it"
url = "http://127.0.0.1:8040/mcp"
transport = "streamable-http"
```

Then ask it in plain words, e.g. "play the duel with draw-it, starting with round_status", and allow the tools of `draw-it` for the session: approving each call makes it too slow to play.

The rival of a duel is asked to play fair, and nothing more: an agent opened in the folder of the game could read the word in the SQLite database. Run it from another folder to rule that out.
