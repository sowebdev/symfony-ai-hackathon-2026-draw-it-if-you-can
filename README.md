# Draw It If You Can

A solo drawing and guessing game against an AI, built with Symfony AI and MCP.

- **You draw, the AI guesses**: it calls out single words as your drawing takes shape, like a player around the table.
- **The AI draws, you guess**: the model draws stroke by stroke, rendered to look hand-drawn.
- **A copilot over MCP**: an external agent connects to the game to see the drawing in progress and give you hints.

Work in progress for the Symfony AI hackathon (November 2026).

## Requirements

- Docker and make: PHP, Composer and the tests all run in the container
- A model: a local OpenAI-compatible server (llama.cpp) or an API key. The game needs an AI to play, there is no offline mode.

## Getting started

```bash
make install
```

Then open http://127.0.0.1:8040 (another port: `APP_PORT=8050 make start`). `make` lists the other commands.

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

Then `make check` tries each model on a tiny task whose answer is known (the guesser must name a circle drawn in an image) and tells what is wrong.

By default both roles use [Ollama](https://ollama.com) on your machine (`http://host.docker.internal:11434`) with `gemma3`, a model that reads images: `ollama pull gemma3`. Any other OpenAI-compatible server works, llama.cpp, LM Studio or a provider's API. If the game must join a Docker network of yours to reach it, put that in a `compose.override.yaml` (not committed).

## Tests

```bash
make test-unit        # also run by the pre-commit hook (pre-commit install)
make test-functional  # against a fresh SQLite test database
```
