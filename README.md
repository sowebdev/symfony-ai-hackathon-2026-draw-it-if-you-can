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

## The model

For now the game talks to a local llama.cpp server through the gate of the `local-model` setup: the container joins its Docker network (`local-model_default`) and calls `http://gate:8001`. The model is set by `AI_MODEL` (default `qwen3.8-27b-vision`, which must be the loaded one); `AI_MODEL=gemma4` in `.env.local` uses Gemma instead.

## Tests

```bash
make test-unit        # also run by the pre-commit hook (pre-commit install)
make test-functional  # against a fresh SQLite test database
```
