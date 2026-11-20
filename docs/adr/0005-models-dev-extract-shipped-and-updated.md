# 5. An extract of models.dev, shipped with the game and updated by the installation

- Status: accepted
- Date: 2026-11-20

## Context

The models screen must know, for Anthropic, OpenAI and Mistral, which models can play, how to group them and which one to preselect. The capabilities came from the catalogs of the bridges of Symfony AI, frozen in its release (`claude-fable-5-2`, released on 09/11/2026, is not in 1.0.0-BETA1; the weekly job that regenerates them from models.dev fails since 02/11/2026). The families and their order by price were a table kept by hand, which aged badly (OpenAI moved from `nano` and `mini` to `luna`, `terra`, `sol`, `astra`).

[models.dev](https://models.dev) is an open catalog (MIT) of models: family, release date, price, capabilities, status. Symfony AI has a bridge on it, `symfony/ai-models-dev-platform`, whose catalog takes the path of a file.

## Options considered

1. **Wait for Symfony AI** to update its catalogs. Nothing to write, but the game follows the release cycle of Symfony AI and a job that fails today; prices and families stay by hand.
2. **The data package `symfony/models-dev`**, republished daily. Pinned by `composer.lock`: a player gets the data of the game's release, 7 MB for 324 providers.
3. **Download models.dev, never ship it**. Always fresh, but a fresh clone without network has nothing, and the game would call models.dev on its own when the file is missing.
4. **Ship an extract of the three providers, and let the installation update it.**

## Decision

Option 4 (Sören, 20/11/2026: the shipped extract is the default; the game updates it at install, not on a schedule, so that it makes no regular calls).

- `data/models-dev.json` is versioned. `make install` and `make models-dev` write `var/models-dev.json`, read instead when it exists. The game never calls models.dev by itself.
- The file feeds the capabilities (before the catalogs of the bridges), the cards and the preselection. The rules are in [docs/models.md](../models.md).
- `ModelFamilies`, the table kept by hand, is gone.

## Consequences

- A fresh clone works offline with the extract of its release; an installation knows the models released since, without a new version of the game.
- Before a release, `make models-dev-default` refreshes the shipped extract.
- The preselection is a rule on price and dates, not a test of the models.
- Only the three providers are kept. models.dev also gives the address of 299 providers' APIs: recognising an address typed for another service (Scaleway, OVHcloud) is possible later; an aggregator such as OpenRouter needs another rule than "the middle of the provider's range".
