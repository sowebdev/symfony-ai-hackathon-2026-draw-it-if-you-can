# 4. Platforms are built at runtime from a stored setting: accounts, and a model per role

- Status: accepted
- Date: 2026-11-19

## Context

Until 0.2 the two platforms (drawer and guesser) were declared in `config/packages/ai.yaml`, both on the Generic bridge, with their address and key read from `AI_*` variables when the container was built. Changing the model meant editing `.env.local` and restarting. In 0.3 the stored setting is the only source of the model: the `AI_*` variables are gone, and an install coming from 0.2 chooses its model again on the page. The next step of v0.3 is a screen where the player picks a provider, a key and a model; for that, the model must live in the database and the platform must follow it.

Each provider also has its own idea of the options a request takes. The Anthropic bridge merges every option into the request body, so `reasoning_effort` or `chat_template_kwargs` is a 400 there. OpenAI's Responses API wants `max_output_tokens`. The Generic bridge wants `max_tokens`, and converts `PlatformOptions::MAX_OUTPUT_TOKENS` into it, as the Anthropic bridge does.

## Options considered

1. **Keep the platforms in `ai.yaml`**, one per provider, with the keys in environment variables. Nothing to build at runtime, but the key cannot be entered in the game, and a platform whose key is empty still exists.
2. **Build the platform at runtime** from the current setting, with the `Factory::createPlatform()` of each bridge, and keep it in memory by provider, address and a hash of the key.
3. **Everything on the Generic bridge**, as today, with the OpenAI-compatible endpoints of the providers. Simplest, but each provider's own bridge knows its API (headers, versions, the catalog of models and what they can do, the format of tool calls).

## Decision

Option 2, with these parts:

- **`Provider`** (enum): Anthropic, OpenAI, Mistral, and any other OpenAI-compatible service. It knows its label, its address when it is fixed, whether it needs a key, how its platform is built, and how it takes the limit of an answer and the reasoning switch.
- **`ProviderAccount`** and **`RoleModel`** (entities): the stored setting. An account is a place the game can call: provider, address (null when the provider has a fixed one), API key sealed, unique on provider and address; there may be several. A role model is the model one role calls: its role (unique), the account it calls, the model, and the reasoning switch (a property of the model chosen, only offered for `Provider::Compatible`). **They are the only source of the model.** With no drawer stored, the game has no model: the home page invites the player to choose one, a game cannot start (it leads to the models screen), and `app:ai:check` says so and gives the address of the page. The `AI_*` variables, `make setup` and `app:setup` no longer exist; the tests that need a model store one.
- **`Role::fallback()`**: a role without a row of its own uses the model of its fallback: the guesser follows the drawer, the drawer follows nobody. A judge or a voice, later, is one more case with its fallback and needs no new column. `ModelSettings::forRole()` follows the fallback and builds the `RoleSetup` (provider, address, key, model, reasoning) the runtime already worked with.
- **Accounts stay until forgotten.** An account no role uses any more is kept, so that the player can switch back without typing the key again; the screen lists them, folded, and forgets one on request. An account a role uses cannot be forgotten. A key is asked only for a place not stored yet, and a stored key is reused for the same provider and the same address only.
- **`PlatformFactory`** (interface), **`RuntimePlatformFactory`** (the implementation, with the in-memory cache). Tests replace the interface with a fake model: no test calls a provider.
- **Options per provider** (`Provider::limit()`, `Provider::reasoningOff()`): the limit is always `PlatformOptions::MAX_OUTPUT_TOKENS`, which the bridges turn into the name of their API. Reasoning off: Generic providers send what the game always sent (`reasoning_effort: none` and llama.cpp's `chat_template_kwargs`); Anthropic sends nothing (thinking is off until asked); OpenAI sends nothing for a model the catalog does not say thinks, and the least effort (`low`, which every reasoning model takes) for one that does.
- **Which bridge** (the result of an exploration on 18/11/2026): Anthropic and OpenAI on their bridges (the first argument chunk of a tool call is not lost, arguments come as deltas; `ToolCallAssembler` stays, its repair of the opening `{` is harmless there). Mistral and any other OpenAI-compatible service (Ollama included) on the Generic bridge.
- **Why Ollama stays on the Generic bridge**: the Ollama bridge sends no argument delta for a tool call, only the complete call at the end. The drawer draws stroke by stroke as the model writes them; with that bridge, all the strokes would appear at the end. The Generic bridge talks to Ollama's OpenAI-compatible endpoint, as today.
- **Why Mistral stays on Generic for now**: whether its dedicated bridge streams strokes live is unknown without a real call. To try with a real key before moving it.
- **A model the catalog does not know must stay usable**: the platforms of Anthropic and OpenAI get the catalog of the bridge followed by `UnlistedModels`, which accepts any name. The game checks a model by trying it (`ModelCheck`: the circle, and for the drawer one stroke), not by trusting a catalog.
- **The list of models** of a provider (`ModelLister`) is its `GET /v1/models`, crossed with the catalog of its bridge: for each role, the models the catalog says can play it (guesser: reads images; drawer: reads images and calls tools), then the others, untested. Timeout 3 s, 6 s at most; kept one hour for Anthropic, OpenAI and Mistral, 30 s for another OpenAI-compatible service. The cache key holds a hash of the address and the key, never the key. When the list cannot be read it says why, and the caller lets the player type a model name.

### The API key is encrypted, with a key that lives next to the database

The API key is sealed with libsodium (`sodium_crypto_secretbox`, a fresh random nonce each time) before it reaches the database. The key is `APP_ENCRYPTION_KEY` (32 random bytes, base64): empty in `.env`, a fixed value in `.env.test`, generated into `.env.local` by `make install` when missing and never replaced. Without it, a setting with an API key cannot be saved, and the message says how to fix it.

What that protects: a copy of the database alone (a backup, a file shared in a bug report, a database dump, an SQL injection that reads tables) does not give the API key. Only the end of the key (`…3f9a`) is kept in clear, to be shown.

What it does not protect: the encryption key sits in `.env.local`, on the same machine, readable by the same user as the database. Anyone who can read both files, or run the game's code, has the API key; so does anyone with access to the process or its environment. It is not a vault, and it does not defend a machine that is compromised. It also does not hide the key from the game itself, which must send it to the provider. Losing `APP_ENCRYPTION_KEY` loses the stored key: the setting has to be saved again with its API key.

The key is never logged, never in an exception message, and never returned by a method the screen could render (`AccountView` has the masked form only; `RoleSetup` hides it from dumps).

## Consequences

- Nothing else chooses the model: no environment variable, no command-line setup. An install coming from 0.2 has no stored model and chooses its model again on the page; its `AI_*` lines of `.env.local` can be deleted.
- A new setting takes effect at once: the platform is built when needed, by provider, address and key.
- Two new bridges (`symfony/ai-anthropic-platform`, `symfony/ai-open-ai-platform` and, for the OpenAI one, `symfony/ai-open-responses-platform`), and the bridges' own catalogs, which the game does not maintain.
- The `ai.platform.generic.*` services of `ai.yaml` are gone; nothing else used them.
- The address of another OpenAI-compatible service is typed by the player, and the game calls it (the list of models, the model itself): the game is made to run on one's own machine, not to be exposed.
- The drawer's check asks for one stroke, because a drawer that cannot call tools cannot play.
- Only a real call confirms a few things: whether the dedicated bridge of Mistral streams strokes live, whether the OpenAI models that think take `reasoning.effort: low`, and the answers of each `/v1/models`. The tests use recorded answers.

## Amended 19/11/2026

Decided by Sören after the first step, while building the model screen:

- **Mistral has its own bridge** (`symfony/ai-mistral-platform`). Its stream converter is the Generic one (`CompletionsConversionTrait`), so the strokes arrive as they do on the Generic bridge and `ToolCallAssembler` keeps working; on top, it reads the block answers of the models that reason (a thinking block, then the text), and its catalog gives capabilities. In `vendor/`, its client takes the limit as `max_output_tokens` (turned into `max_tokens`, as the others) and any other option is merged into the body; which `reasoning_effort` each model takes is not in its catalog, so the game sends **nothing** for reasoning, thinking model or not. This replaces "Why Mistral stays on Generic for now".
- **Ollama is not a provider.** It is an address on the Generic bridge, like LM Studio or llama.cpp on one's machine, OpenRouter, Groq or the Hugging Face router on the internet. `Ollama` and `OwnServer` are one case, `Provider::Compatible` ("another OpenAI-compatible service"): an address (typed by the player, no default: the game stays agnostic of the engine) and an optional key. A migration turns a stored `ollama` or `own_server` setting into it, writing down the address Ollama had. Four providers remain.
- **The lists show only what can play** (the "others, untested" group is gone). Anthropic, OpenAI: the catalog of the bridge; Mistral: the capabilities of its own `/v1/models` answer, else the catalog; models the catalog does not know are typed by name. The Generic case is filtered on capabilities it carries in a known shape (OpenRouter's and the Hugging Face router's `architecture` and `supported_parameters`), else listed as it is, sorted by name. Nothing is labelled "tested" or "suggested"; the labels say what is known now, and "checked" only after the check of the screen passed.
- **Reasoning, per provider.** Where a catalog says whether a model thinks (Anthropic, OpenAI, Mistral) the game decides and the player sees no switch: OpenAI gets `reasoning.effort: low` for a thinking model and nothing otherwise, Anthropic and Mistral get nothing, a model typed by name gets nothing. For another OpenAI-compatible service the game cannot know: the player has a switch, on by default for a local address (`localhost`, `127.0.0.1`, `host.docker.internal`, a private network) and off otherwise, since a cloud API may refuse `reasoning_effort: none` or `chat_template_kwargs`. A check that fails because of it says which way to flip the switch.
- **The screen needs a CSRF protection**, since it can change the address the game calls and the saved key goes with it: `symfony/form` and `symfony/security-csrf` (the stateless protection of Symfony, which checks the headers of the browser and needs no script), hence `symfony/security-bundle`, which the AI bundle wants once the security component is there. A saved key is only reused for the same provider and the same address.

## Amended 19/11/2026 (second, before the release of v0.3): one AI per role, each at the provider of its choice

Sören found a flaw in the models screen: the provider was chosen once, on its own step, and held for both roles, so the drawer and the guesser were forced onto the same provider (and a judge or a voice, later, would have been too). A drawer on Anthropic and a guesser on a server of one's own was impossible. The runtime was already per role (`RoleSetup` carries provider, address, key and model; `RuntimePlatformFactory` caches a platform per provider, address and key, and is unchanged); what tied the roles together was the storage, a single `AiSetting` row, and the screen. v0.3 is not released, so this ADR is amended in place:

- **The storage is accounts and role models**, as above, instead of one row. A migration turns the existing row into one account (the key kept as it is sealed: nothing is decrypted), a drawer role model, and a guesser role model only if its model differs from the drawer's (else the guesser follows the fallback); going down does the reverse when there is one account and at most two roles on it, and says why when there are more.
- **The screen asks one question at a time** ("do not drown users in information"): which provider, then only what the place is missing (its key, or a server's address), then the model, then the check; no JavaScript, each "Continue" a round trip, the answers in the session (keys sealed). The 1-2-3 step indicator is gone. The check is run on the spot by "Check and play" and saves the setting when every role passes.
- **Options considered for the storage**: (a) keep one row and add the provider to the guesser's columns (a second set of columns per role: a judge would add a third); (b) one row per role, each carrying its own provider, address and key (the same key typed twice, and a key replaced in one place and not the other); (c) accounts and role models. Option (c) was chosen: a key lives once per place, a role is one row pointing at it, and a new role needs no column.
