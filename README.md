# Draw It If You Can

A solo drawing and guessing game against an AI, built with Symfony AI and MCP.

- **You draw, the AI guesses**: it calls out single words as your drawing takes shape, like a player around the table.
- **The AI draws, you guess**: the model draws stroke by stroke, rendered to look hand-drawn.
- **A copilot over MCP**: an external agent connects to the game to see the drawing in progress and give you hints.

The game speaks English and French: the whole game follows the language of the page, the interface, the word to draw and the words of the AI.

Work in progress for the Symfony AI hackathon (November 2026).

## Requirements

- Docker and make: PHP, Composer and the tests all run in the container
- A model: a local OpenAI-compatible server (llama.cpp) or an API key. The game needs an AI to play, there is no offline mode.

## Getting started

1. Have a model ready, one that reads images. The simplest: [Ollama](https://ollama.com) on your machine, then `ollama pull gemma3`. That is what the game expects by default. Another server or an API key: see [The models](#the-models).
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
AI_GUESSER_BASE_URL=https://api.mistral.ai
AI_GUESSER_MODEL=mistral-small-latest
AI_GUESSER_API_KEY=...
AI_GUESSER_DISABLE_THINKING=false   # true sends llama.cpp's chat_template_kwargs, which an API may refuse
```

The strokes of the AI look drawn by a hand; `HUMANIZED_DRAWING=false` in `.env.local` draws straight segments instead.

Then `make check` tries each model on a tiny task whose answer is known (the guesser must name a circle drawn in an image) and tells what is wrong.

By default both roles use [Ollama](https://ollama.com) on your machine (`http://host.docker.internal:11434`) with `gemma3`, a model that reads images: `ollama pull gemma3`. Any other OpenAI-compatible server works, llama.cpp, LM Studio or a provider's API. If the game must join a Docker network of yours to reach it, put that in a `compose.override.yaml` (not committed).

## The copilot, over MCP

When the AI draws, an agent can help you guess. The game is an MCP server (Symfony MCP Bundle) with three tools and one prompt:

- `round_status`: the round being played, who draws, the theme, the time left, your wrong answers and the hints given. Never the word to find.
- `look_at_drawing`: the drawing as it is now, as a PNG image.
- `suggest_word`: a word shown to you in the margin, at most 5 per round. A click on it answers it: you decide, the copilot never answers by itself.
- the `copilot` prompt tells the agent how to play.

With Claude Code, over HTTP while the game runs:

```bash
claude mcp add --transport http draw-it http://127.0.0.1:8040/mcp
```

Then tick "With a copilot" on the home page: the game waits in a lobby, which repeats these steps and tells you when the agent is connected. Open Claude Code in the folder of the game and run the `copilot` prompt of the `draw-it` server (`/mcp__draw-it__copilot`), then start the game. Clients that start their servers themselves can use STDIO instead: `docker compose exec -T php php bin/console mcp:server`. The HTTP endpoint only answers on localhost.

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
