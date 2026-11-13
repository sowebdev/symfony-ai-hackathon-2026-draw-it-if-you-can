# Draw It If You Can

A solo drawing and guessing game against an AI, built with Symfony AI and MCP.

- **You draw, the AI guesses**: it calls out single words as your drawing takes shape, like a player around the table.
- **The AI draws, you guess**: the model draws stroke by stroke, rendered to look hand-drawn.
- **A copilot over MCP**: an external agent connects to the game to see the drawing in progress and give you hints.
- **A duel over MCP**: the agent guesses against you instead, and the first side to find the word wins its points.

The game speaks English and French: the whole game follows the language of the page, the interface, the word to draw and the words of the AI.

Work in progress for the Symfony AI hackathon (November 2026).

## Requirements

- Docker and make: PHP, Composer and the tests all run in the container
- A model: a local OpenAI-compatible server (Ollama, llama.cpp, LM Studio) or an API key. The game needs an AI to play, there is no offline mode.

## Getting started

1. Have a model ready. By default the game expects [Ollama](https://ollama.com) on your machine with Qwen3.8 27B, the smallest local model that draws recognisably: `ollama pull hf.co/unsloth/Qwen3.8-27B-GGUF:UD-Q3_K_XL` (14 GB, for a GPU with 16 GB or a Mac with enough memory). On Linux, start Ollama with `OLLAMA_HOST=0.0.0.0`, or the game, in its container, cannot reach it. A smaller machine or an API key: see [Which model?](#which-model).
2. Install and start the game:

   ```bash
   make install
   ```

3. Open http://127.0.0.1:8040 (another port: `APP_PORT=8050 make start`).

The home page checks both models at once and tells you what is wrong if one does not answer, with where to fix it. `make check` does the same in a terminal, and `make` lists the other commands.

## The models

The AI has two roles, each with its own OpenAI-compatible endpoint, model and optional API key:

- **the drawer** draws while you guess;
- **the guesser** picks the word you draw, then guesses your drawing: its model must read images.

Set them in `.env.local` (defaults in `.env`):

```bash
AI_DRAWER_BASE_URL=https://api.anthropic.com
AI_DRAWER_MODEL=claude-sonnet-5-5
AI_DRAWER_API_KEY=...
AI_DRAWER_DISABLE_THINKING=false   # true turns reasoning off: for local models, an API may refuse it
```

Then `make check` tries each model on a tiny task whose answer is known (the guesser must name a circle drawn in an image) and tells what is wrong.

### Which model?

The game does not need the best model: a poor drawer makes you laugh, a good one makes you play. Drawing is the hard part; guessing works even with a tiny model. What we tried (November 2026):

| Setup | Guesser | Drawer | Notes |
|---|---|---|---|
| **Default**: Ollama, `hf.co/unsloth/Qwen3.8-27B-GGUF:UD-Q3_K_XL` for both roles | 0.7 s per word, finds a swan | a cat with whiskers, a snail with its shell, first stroke after 4 s | 14 GB, fully on a 16 GB GPU; the official `qwen3.8:27b` (18 GB) also works, but spills onto the CPU and draws its first stroke after 10 s. Not tried on a Mac |
| Anthropic API (`https://api.anthropic.com`) | `claude-haiku-5-5`, about 1 s | `claude-sonnet-5-5`, a whale you recognise in 10 s | the best we tried, on any machine |
| Mistral API (`https://api.mistral.ai`) | `mistral-small-latest`, 0.5 s, but misses easy drawings | `mistral-medium-3.5`, funny, rarely guessable | models that always reason, such as `mistral-large-4`, answer in a format the game cannot read yet |
| A small model on the CPU, without a GPU: Ollama, `lfm3-vl:2b` for both roles | finds the swan, 3 to 5 s per word | strokes, but nothing you can guess | 1.5 GB; play "I draw, the AI guesses" (LFM Open License) |

Smaller local models, from 2B to 12B (Qwen 3.5, Gemma 4, Ministral 3, Granite 4, LFM), all guess fairly well, but none drew anything recognisable, on a CPU or a GPU. Each role has its own endpoint: a small local guesser can play with a cloud drawer.

- **Local models must not reason**: keep `DISABLE_THINKING=true`, or a small model takes tens of seconds per word. Cloud models may reason: the answer stays short.
- **One model for both roles, or two that fit in memory together**: the roles take turns all the time, and loading a model takes 10 to 45 s.
- **Ollama on Linux** listens on 127.0.0.1 only: start it with `OLLAMA_HOST=0.0.0.0`, so that the game's container reaches it at `host.docker.internal`. Docker Desktop on macOS and Windows does it for you.

Any other OpenAI-compatible server works, llama.cpp or LM Studio. If the game must join a Docker network of yours to reach it, put that in a `compose.override.yaml` (not committed).

The strokes of the AI look drawn by a hand; `HUMANIZED_DRAWING=false` in `.env.local` draws straight segments instead.

## An agent over MCP: copilot or duel

The game is an MCP server (Symfony MCP Bundle). An agent connected to it plays along, in one of two modes chosen on the home page:

- **With a copilot**: when the AI draws, the agent looks at the drawing and whispers words in the margin. A click on one answers it: you decide, the copilot never answers by itself.
- **In a duel**: the agent guesses the same drawings as your side, those of the AI and yours, which your AI guesses for you. The first side to find the word wins its points. The agent answers once every 3 seconds at most, and only the exact word counts for it. Its answers show next to yours.

Six tools and two prompts:

- `round_status`: the round being played, the role of the agent, who draws, the theme, the time left, the wrong answers so far, the score of a duel, and what to do now. Never the word to find while the round is played; once it is over, the word it was. It also tells the agent to stop, when the game is over or the player left. It always answers at once.
- `wait_for_your_turn`: waits for something new for the agent, a round that starts or ends, new strokes on the drawing, and answers with the same status as `round_status`, or after 25 s with nothing new. An agent cannot wait by itself between two calls, so the game waits for it, and the agent only has to call again. Why: [ADR 0001](docs/adr/0001-agents-wait-on-the-server.md).
- `look_at_drawing`: the drawing as it is now, as a PNG image.
- `suggest_word`: for a copilot, a word shown to you, at most 5 per round.
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

## Licence and credits

The code of the game is under the [MIT license](LICENSE).

- **Patrick Hand**, the handwriting font, by Patrick Wagesreiter, under the [SIL Open Font License 1.1](assets/fonts/OFL.txt). It is served by the game, without any outside call, and is not covered by the MIT license.
- **SketchAgent** (CVPR 2025, [arXiv:2411.17673](https://arxiv.org/abs/2411.17673)) gave the method the AI draws with: a 50 x 50 grid of named cells, a stroke as the cells the pen goes through. The prompts, the tool and the hand-drawn rendering are the game's own; nothing is copied from the paper, which is under CC BY-NC-SA 4.0.
- Built with [Symfony](https://symfony.com), [Symfony AI](https://github.com/symfony/ai) and its MCP bundle, on the [MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk), and [Stimulus](https://stimulus.hotwired.dev).
- The dependencies are installed by Composer and the importmap, not shipped in this repository; each keeps its own license (MIT, BSD-3-Clause for Twig, Apache-2.0 for the MCP SDK and the opis libraries). `docker compose exec php composer licenses` lists them.

## Tests

```bash
make test-unit        # also run by the pre-commit hook (pre-commit install)
make test-functional  # against a fresh SQLite test database
```
