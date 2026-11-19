# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Real providers behind the game's model (Anthropic and OpenAI on their own bridges, Mistral on its own bridge, any other OpenAI-compatible service, local like Ollama or on the internet like OpenRouter, on the generic one), a setting stored in the database with its API key encrypted by `APP_ENCRYPTION_KEY` (`app:encryption-key`, run by `make install`), and the list of each provider's models, which shows only those that can play; without a stored setting the game still reads the `AI_*` variables.
- A screen to choose the model, in three steps without JavaScript (provider and key, models, check, then "Save and play"), in the five languages. `make install` asks nothing any more: you choose the model on the page. The home page leads there when no model answers, and its health messages are translated.

## [0.2.0] - 2026-11-18

### Fixed

- Your agent now plays the game you are looking at: it follows the last game page you opened, instead of the last game created. With several games or tabs open, it no longer plays one you left behind.

### Added

- The game speaks German, Spanish and Italian too, and the language menu on the home page is now a drop-down.
- Continuous integration on GitHub: every push and pull request runs the same checks as `make ci`.
- `make ci` runs everything a pull request must pass: code style, static analysis, the Symfony linters, a check of the database migrations, a dependency audit and the tests.
- A demo video of the game in the README.

### Changed

- Functional tests each run in a transaction that is rolled back, so they no longer leave data behind for one another.

## [0.1.0] - 2026-11-16

First public version, submitted to the Symfony AI hackathon.

### Added

- A drawing and guessing game played with an AI, in turns: the AI shouts out guesses as your sketch grows, and draws live, stroke by stroke, when you guess.
- Your own agent can join over MCP (Claude Code, Mistral Vibe or any client whose model sees images), as a copilot or in a duel.
- Any model that sees images and calls tools, from a cloud API or on your own machine.
- English and French.

[Unreleased]: https://github.com/sowebdev/symfony-ai-hackathon-2026-draw-it-if-you-can/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/sowebdev/symfony-ai-hackathon-2026-draw-it-if-you-can/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/sowebdev/symfony-ai-hackathon-2026-draw-it-if-you-can/releases/tag/v0.1.0
