# Draw It If You Can

AI doesn't always have to be about productivity. It can also be fun.

Draw It If You Can is a drawing and guessing game you play with an AI, taking turns. When you draw, the AI watches your sketch grow and shouts out one word at a time, like a friend around the table. When the AI draws, you guess: it picks up the pencil and draws live, stroke by stroke, with the wobble of a human hand.

Bring your own agent along over MCP: Claude Code, Mistral Vibe or any client whose model sees images joins from the lobby, picks itself a funny nickname, and plays as your copilot or races you to the answer in a duel.

Plug in the model you like, from a cloud API or on your own machine: this may be the first time you want a decent graphics card for something other than 3D games.

Built with Symfony AI for the Symfony AI hackathon (November 2026). The game speaks English and French.

## Play in three steps

You need Docker, make, and a model: a cloud API key, or a model server of your own.

1. `make install`, then answer its few questions: where the model runs (a cloud API compatible with OpenAI's, or a server you run), its address, the model, and the key if it needs one.
2. Open http://127.0.0.1:8040.
3. Draw, guess, laugh.

`make setup` changes the model later, and `make check` tells you whether it can play.

## Which model for the most fun?

A poor drawer makes you laugh, a good one makes you play.

- **Claude Haiku 5.5** is plenty, for drawing as for guessing.
- **Qwen 3.8 27B on your own machine** is the sweet spot for fun: it draws a cat with whiskers or a snail with its shell, for free, if you have a GPU with 16 GB or a Mac with enough memory.
- **Mistral Small 4** is quick, but a poor artist: expect kangaroos nobody will ever guess (sorry, Mistral).

The model must see images and call tools. Details, the models I tried, reasoning, and what to do when it does not play: [docs/models.md](docs/models.md).

## Play with your agent

The game is an MCP server. With Claude Code, while the game runs:

```bash
claude mcp add --transport http draw-it http://127.0.0.1:8040/mcp
```

Then pick "With a copilot" or "Duel" on the home page: the lobby tells you what to do next, and when your agent is in. Mistral Vibe, the tools and the rules: [docs/mcp.md](docs/mcp.md).

## Contributions to Symfony AI

Building the game on the beta of Symfony AI turned up a bug: the Generic bridge dropped the arguments carried by the chunk that starts a streamed tool call. The fix, [symfony/ai#2940](https://github.com/symfony/ai/pull/2940), is merged; until it is released, the game assembles streamed tool calls itself.

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
