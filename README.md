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

## Tests

```bash
make test-unit        # also run by the pre-commit hook (pre-commit install)
make test-functional  # against a fresh SQLite test database
```
