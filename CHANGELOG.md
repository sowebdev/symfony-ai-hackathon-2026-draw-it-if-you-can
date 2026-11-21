# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Olaf, a penguin, is the AI of the game (name set by `GAME_AI_NAME`). Each word he guesses pops in his bubble; he hops, sways and jumps for joy.
- A judge for every answer, the player's, Olaf's and the agent's: accepted, warm or refused. The word or one typo of it is accepted by code; otherwise the guessing model names the link with one label, kept per pair, and the code decides ([ADR 0006](docs/adr/0006-the-judge-a-strict-label-a-cache-and-the-order-of-the-words.md)).
- A walrus referee who whistles « ça chauffe » and « c'est juste » for everyone.
- The round in three sides: our team in yellow on the left, the sheet and the walrus in the middle, the agent in orange on the right as a robot with a terminal bubble. Alone, the walrus sits on a high chair. A top bar that never moves, with « Quitter » on the left.
- The game settings, on their own screen: themes, who draws, number and length of rounds, and the easy mode, where the judge accepts synonyms and very close words. A game keeps the settings it started with.
- `make smoke` plays a solo game and a duel in a real browser, at two widths, against a scripted model; the CI runs it.
- The walrus thinks aloud: while the judge decides on a word, he repeats it with one of ten phrases (« "chaton" ? Une seconde… »), in turn.
- The judge has its own line on the models screen: by default it uses the guesser's model, and it can be given another one, a faster one for instance (« Une autre IA pour juger ? »).
- In development, the Symfony profiler and its toolbar, whose AI panel shows every call to a model with its tokens, and the logs in `var/log/dev.log`.
- Every call to a model is logged in `var/log/ai.log`, one line each: role, model, time, tokens and cost. `bin/console app:ai:usage` says what each role and model took and cost.

### Changed

- The styles are split by screen (`assets/styles/`), and a round's layout is written once, without the layer that overrode the old one.

### Fixed

- A model that calls tools but drew no stroke during the check is told to try again, no longer that it cannot draw.
- A round on a tablet held upright: the three sides now go one under the other, the sheet first and whole in the window, and the top bar goes to the next line instead of overlapping. The fields of the settings screen are aligned on the left again, like those of the models screen.
- In a duel, the side that said the word first wins it, even when its word waits for the judge: a word said after it, accepted at once, no longer overtakes it.

## [0.3.0] - 2026-11-20

### Added

- Real providers behind the game's model (Anthropic and OpenAI on their own bridges, Mistral on its own bridge, any other OpenAI-compatible service, local like Ollama or on the internet like OpenRouter, on the generic one), a setting stored in the database with its API key encrypted by `APP_ENCRYPTION_KEY` (`app:encryption-key`, run by `make install`), and the list of each provider's models, which shows only those that can play, which is the only source of the model.
- A screen to choose the AI of each role, one question at a time and without JavaScript (which provider; only what the place is missing, its key or its address; which model; then « Vérifier et jouer »), in the five languages. Each role, the drawer and the guesser, can use any provider, for instance the drawer on Anthropic and the guesser on a server of one's own; a guesser without a model of its own uses the drawer's. A summary says what each role uses; keys are asked only for a place not stored, stay until forgotten (« Fournisseurs enregistrés »), and an account a role uses cannot be forgotten. One card per family, the older versions folded away; the check is on the spot, shows ✓ or ✗ on each role with the cause and the advice on a failure (a role that passed is not tried again), saves when all pass, and the loading screen shows while the models are tried. `make install` asks nothing any more: you choose the models on the page. The home page invites you there while no model is chosen, and leads there when a model does not answer; its health card has a line per role (« Il dessine et devine » when the guesser follows) and its messages are translated. Without a model, "Start the game" leads to the screen, and `make check` says so and gives its address; with models, it says where each role answers.
- The models screen preselects the middle of the provider's current range, by price, marked « le bon compromis »: `claude-sonnet-5-5` at Anthropic, `gpt-6.2-sol` at OpenAI, `mistral-small-2603` at Mistral on 20/11/2026. It comes from [models.dev](https://models.dev), an open catalog of models (MIT): the game ships an extract of it for its three providers (`data/models-dev.json`), and `make install` or `make models-dev` downloads a fresh one into `var/models-dev.json`, read instead; without network or with models.dev down, the installation goes on with the extract it has. Nothing is called while playing. The same file gives the capabilities, before the catalogs of the bridges, frozen in the release of Symfony AI (`claude-fable-5-2` was missing there), and the cards: the current range itself, one per family from the cheapest, without the models for another use (realtime) nor the families more than a year behind. The families kept by hand (`ModelFamilies`) are gone, and so is « capacités inconnues » for a model of a known family: a model nobody knows is typed.

### Changed

- Models screen: a stored key says « Sert à dessiner / à deviner » (or both on one line); the typed model name is folded with the list of models; a failed check says what is wrong with the model and what to do, without the test or the model's answer (kept in the logs).
- The word to draw is easier to read: the game now asks for words with one obvious meaning, under their usual name ("château fort" rather than "fort"), and shows them with their article: « À dessiner : un château fort ». The article does not count for the answer ("château fort" and "le château fort" both find it).
- The words to draw are common ones again: the game no longer asks for words "not only the most obvious", which led to "un quokka" in the animals; every word must now be known to a child of eight and easy to recognise in a quick drawing. The link at the bottom of the home page says « Configurer le modèle » instead of « Changer de modèle ».
- The waiting text says « Tirage du mot… » (the word is drawn at random) instead of « L'IA choisit un mot… ».
- The duel is easier to read: the player's team (« Toi et l'IA du jeu ») is in yellow and the rival agent in orange, in the top bar, in the words each side proposes, in the end-of-round note and in the final table. The end screen opens with a big banner in the winner's colour, with its name and both scores, and a clear tie.

### Fixed

- The AI that guesses may answer a short phrase of three words at most, not only one word: with "père noël" to find, it said "Noël", then "Père", and could never win. "Père Noël" now finds "père noël".
- A model too slow to draw the word of a round no longer ends on a PHP error page: after 25 seconds the game says the model takes too long, possibly because it is loading, and offers "Try again".

### Removed

- The `AI_*` variables of `.env` and `make setup` (`app:setup`): the model is chosen on the page and stored in the database. Coming from 0.2, choose your model again on the page; the `AI_*` lines of `.env.local` can be deleted.

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

[Unreleased]: https://github.com/sowebdev/symfony-ai-hackathon-2026-draw-it-if-you-can/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/sowebdev/symfony-ai-hackathon-2026-draw-it-if-you-can/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/sowebdev/symfony-ai-hackathon-2026-draw-it-if-you-can/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/sowebdev/symfony-ai-hackathon-2026-draw-it-if-you-can/releases/tag/v0.1.0
