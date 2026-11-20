# 5. The catalog of models.dev, downloaded at install: capabilities, cards and the model preselected

- Status: accepted
- Date: 2026-11-20

## Context

The models screen must know, for Anthropic, OpenAI and Mistral, which models can play (images, and tools for the drawer), how to group them (one card per family) and which one to preselect. Until now:

- the capabilities came from the catalog of each bridge of Symfony AI, a PHP file frozen in its release: `claude-fable-5-2`, released on 09/11/2026, is not in 1.0.0-BETA1. Symfony AI generates these catalogs from models.dev every Monday, but the job fails since 02/11/2026 (exit code 255), so even its main branch misses it;
- the families and their order by price were a table kept by hand (`ModelFamilies`), which aged badly: OpenAI moved from `nano` and `mini` to `luna`, `terra`, `sol`, `astra`;
- the cheapest family was preselected, which is not always playable (Ministral at Mistral draws nothing anyone can guess).

[models.dev](https://models.dev) is an open catalog (MIT) of models: per provider, each model's family, release date, price per million tokens, input modalities, tool calling and status. Symfony AI has a bridge on it, `symfony/ai-models-dev-platform`, whose `ModelCatalog` takes the path of the file.

## Options considered

1. **Update Symfony AI** when its catalogs catch up. Nothing to write, but the game depends on the release cycle of Symfony AI and on a job that fails today; prices and families stay by hand.
2. **The data package `symfony/models-dev`** (models.dev, republished daily). Fresh at each `composer update`, but pinned by `composer.lock`: a player who installs the game gets the data of its release.
3. **Download models.dev at install**, into the installation and not into the repository, and give the file to the catalog of the bridge of models.dev; the game reads the same file for families and prices.

## Decision

Option 3 (Sören, 20/11/2026: "the game lasts, and we need no new version").

- **`ModelsDev`**: `make install` (`app:models-dev`) downloads `https://models.dev/api.json` and keeps the three providers, in the format of models.dev, in `var/models-dev.json` (75 KB). The models screen downloads it when it is missing, with a 5-second limit; a failure is silent. `MODELS_DEV_FILE` points elsewhere (the tests use `tests/Fixtures/models-dev.json`, a few real entries).
- **Capabilities**: `Provider::bridgeCatalog()` is the catalog of models.dev on this file, with the model class of each bridge (`Claude`, `Gpt`, `Mistral`), then the bridge's own catalog. A model neither knows is not listed: the player types its name. Mistral's own answer, which carries capabilities, still decides first.
- **Cards**: one per family of models.dev, its newest listed model, from the cheapest; a deprecated model or one made by another company (Mistral also serves DeepSeek and GLM) is never a card.
- **The model preselected**, marked « le bon compromis »: the middle of the provider's current range, by price (output). The range: per family, the newest listed model that reads images, calls tools, is not deprecated, is of the provider's make, and is less than a year older than the provider's newest model (not today: an old file gives the same answer). Of two middles, the cheaper. On 20/11/2026: `claude-sonnet-5-5`, `gpt-6.2-sol`, `mistral-small-2603`. Sören's limit: never Opus or Fable.
- **Without the file**: the catalogs of the bridges alone, no cards, nothing preselected. No fallback by hand: `ModelFamilies` is gone.

## Consequences

- A new model or a new family needs no new version of the game: `make models-dev`, or deleting the file, is enough.
- The rule is code, not a test of the models: it says which model is in the middle of the range, not which one draws best. "What I tried" in `docs/models.md` stays the record of real tries.
- The list of models of a provider is cached an hour (`model_list.v5`): a new file shows on the next list.
- If models.dev changes its format or goes away, the game falls back to the catalogs of Symfony AI, without cards nor preselection; the tests do not see it (they never call models.dev).
