# 4. Platforms are built at runtime from a stored setting

- Status: accepted
- Date: 2026-11-19

## Context

Until 0.2 the two platforms (drawer and guesser) were declared in `config/packages/ai.yaml`, both on the Generic bridge, with their address and key read from `AI_*` variables when the container was built. Changing the model meant editing `.env.local` and restarting. The next step of v0.3 is a screen where the player picks a provider, a key and a model; for that, the model must live in the database and the platform must follow it.

Each provider also has its own idea of the options a request takes. The Anthropic bridge merges every option into the request body, so `reasoning_effort` or `chat_template_kwargs` is a 400 there. OpenAI's Responses API wants `max_output_tokens`. The Generic bridge wants `max_tokens`, and converts `PlatformOptions::MAX_OUTPUT_TOKENS` into it, as the Anthropic bridge does.

## Options considered

1. **Keep the platforms in `ai.yaml`**, one per provider, with the keys in environment variables. Nothing to build at runtime, but the key cannot be entered in the game, and a platform whose key is empty still exists.
2. **Build the platform at runtime** from the current setting, with the `Factory::createPlatform()` of each bridge, and keep it in memory by provider, address and a hash of the key.
3. **Everything on the Generic bridge**, as today, with the OpenAI-compatible endpoints of the providers. Simplest, but each provider's own bridge knows its API (headers, versions, the catalog of models and what they can do, the format of tool calls).

## Decision

Option 2, with these parts:

- **`Provider`** (enum): Anthropic, OpenAI, Mistral, Ollama, own server. It knows its label, its default address, whether it needs a key, how its platform is built, and how it takes the limit of an answer and the reasoning switch.
- **`AiSetting`** (entity): one row for the installation: provider, address, model of each role, reasoning switch, API key sealed. **When there is no row, the game reads the `AI_*` variables exactly as before** (a server on the Generic bridge): `make setup`, `app:setup`, the CI and the tests do not change. The database wins over the environment as soon as there is a row.
- **`PlatformFactory`** (interface), **`RuntimePlatformFactory`** (the implementation, with the in-memory cache). Tests replace the interface with a fake model: no test calls a provider.
- **Options per provider** (`Provider::limit()`, `Provider::reasoningOff()`): the limit is always `PlatformOptions::MAX_OUTPUT_TOKENS`, which the bridges turn into the name of their API. Reasoning off: Generic providers send what the game always sent (`reasoning_effort: none` and llama.cpp's `chat_template_kwargs`); Anthropic sends nothing (thinking is off until asked); OpenAI sends nothing for a model the catalog does not say thinks, and the least effort (`low`, which every reasoning model takes) for one that does.
- **Which bridge** (the result of an exploration on 18/11/2026): Anthropic and OpenAI on their bridges (the first argument chunk of a tool call is not lost, arguments come as deltas; `ToolCallAssembler` stays, its repair of the opening `{` is harmless there). Mistral, Ollama and "own server" on the Generic bridge.
- **Why Ollama stays on the Generic bridge**: the Ollama bridge sends no argument delta for a tool call, only the complete call at the end. The drawer draws stroke by stroke as the model writes them; with that bridge, all the strokes would appear at the end. The Generic bridge talks to Ollama's OpenAI-compatible endpoint, as today.
- **Why Mistral stays on Generic for now**: whether its dedicated bridge streams strokes live is unknown without a real call. To try with a real key before moving it.
- **A model the catalog does not know must stay usable**: the platforms of Anthropic and OpenAI get the catalog of the bridge followed by `UnlistedModels`, which accepts any name. The game checks a model by trying it (`ModelCheck`: the circle, and for the drawer one stroke), not by trusting a catalog.
- **The list of models** of a provider (`ModelLister`) is its `GET /v1/models`, crossed with the catalog of its bridge: for each role, the models the catalog says can play it (guesser: reads images; drawer: reads images and calls tools), then the others, untested. Timeout 3 s, 6 s at most; kept one hour for Anthropic, OpenAI and Mistral, 30 s for Ollama and an own server. The cache key holds a hash of the address and the key, never the key. When the list cannot be read it says why, and the caller lets the player type a model name.

### The API key is encrypted, with a key that lives next to the database

The API key is sealed with libsodium (`sodium_crypto_secretbox`, a fresh random nonce each time) before it reaches the database. The key is `APP_ENCRYPTION_KEY` (32 random bytes, base64): empty in `.env`, a fixed value in `.env.test`, generated into `.env.local` by `make install` when missing and never replaced. Without it, a setting with an API key cannot be saved, and the message says how to fix it.

What that protects: a copy of the database alone (a backup, a file shared in a bug report, a database dump, an SQL injection that reads tables) does not give the API key. Only the end of the key (`…3f9a`) is kept in clear, to be shown.

What it does not protect: the encryption key sits in `.env.local`, on the same machine, readable by the same user as the database. Anyone who can read both files, or run the game's code, has the API key; so does anyone with access to the process or its environment. It is not a vault, and it does not defend a machine that is compromised. It also does not hide the key from the game itself, which must send it to the provider. Losing `APP_ENCRYPTION_KEY` loses the stored key: the setting has to be saved again with its API key.

The key is never logged, never in an exception message, and never returned by a method the screen could render (`SettingView` has the masked form only; `RoleSetup` hides it from dumps).

## Consequences

- A new setting takes effect at once: the platform is built when needed, by provider, address and key.
- Two new bridges (`symfony/ai-anthropic-platform`, `symfony/ai-open-ai-platform` and, for the OpenAI one, `symfony/ai-open-responses-platform`), and the bridges' own catalogs, which the game does not maintain.
- The `ai.platform.generic.*` services of `ai.yaml` are gone; nothing else used them.
- The address of an own server or Ollama is typed by the player, and the game calls it (the list of models, the model itself): the game is made to run on one's own machine, not to be exposed.
- The drawer's check asks for one stroke, because a drawer that cannot call tools cannot play.
- Only a real call confirms a few things: whether the dedicated bridge of Mistral streams strokes live, whether the OpenAI models that think take `reasoning.effort: low`, and the answers of each `/v1/models`. The tests use recorded answers.
