# The models

The AI has two roles, and each can use any provider, for instance the drawer on Anthropic and the guesser on a server of your own:

- **the drawer** draws while you guess, and looks at its drawing to add the details that are missing;
- **the guesser** guesses your drawing (the word to draw is drawn at random from a list the model gives). Until you give it a model of its own, it uses the one of the drawer.

Both models must read images, and the drawer must also call tools.

## Choosing the models on the screen

Open the game and follow the page (the home page invites you there while no model is chosen, its health card leads there when a model does not answer, and "Change the model" at the bottom of the home page always does). The screen asks **one question at a time**, with no JavaScript: each "Continue" is a round trip, and what you answered so far lives in your session.

**The summary** is the entry once a drawer is stored: « Les IA du jeu », one line per role that has a model of its own (« Il dessine · `claude-haiku-5-5` chez Anthropic · changer », « Il devine · `qwen3.8-27b-vision` sur ton serveur · changer »). A guesser that follows the drawer is a muted line, « Il devine aussi ton dessin. Une autre IA pour deviner ? », the question being the link that starts the guesser's questions. "Check and play" tries the models; the stored keys are folded at the bottom.

**The questions of a role** ("change", or the first visit, which starts on the drawer):

1. **Which AI draws?** (or guesses): four choices, nothing else: Anthropic, OpenAI, Mistral, "your server, or another service". A provider whose key is stored is tagged « clé enregistrée ». In the guesser's questions, "the same AI as the one that draws" gives the guesser no model of its own again.
2. **What is missing**: your key for a provider whose key is not stored; for a server, its address, with the key folded behind "Does it need a key?" (leave it empty if your server asks for none). A stored place goes straight to the next question; its key is changed from here too ("Change the key"). The key is a password field: empty means "keep the stored key", of which only the end is shown (`…3f9a`), and a stored key is never sent to another address than the one it was stored for. The game runs in Docker, so your machine is `host.docker.internal` there, not `localhost`; the usual ports are Ollama 11434, LM Studio 1234, llama.cpp 8080, among others (`http://host.docker.internal:11434`); the address starts empty, the game names no default server.
3. **Which model?** The list shows only what can play, as one card per family (its most recent version), from the cheapest family; the cheapest is preselected and marked « le moins cher » (`src/Ai/ModelFamilies.php`: no list of models carries prices, so the order of the families is kept by hand, to be reviewed when a provider adds one). A model stored for the role at this place stays selected. Older versions and unknown families are under "See all models". A field takes any model name: the typed name wins. For a server, the capabilities are unknown (the screen says so once), and the reasoning switch is folded behind "Advanced settings". In the drawer's questions, while the guesser follows the drawer, a muted line offers "Another AI to guess?": it saves this model and goes on with the guesser's questions.

While the questions of a role run, the other role already chosen is shown on top as one summary line with "change".

**The check** is on the spot, no separate page. "Check and play" tries each role (the guesser names a circle drawn in an image; the drawer also draws one stroke with its tool; a guesser that follows the drawer is not tried twice) and, when all pass, saves the setting and goes home. On a failure the summary shows ✓ or ✗ on each line, the cause and the advice under the line that failed, "change" and "Try again"; the role that passed stays visible and is not tried again. Nothing is saved until every role passes. The loading screen shows during the wait.

**Stored keys.** Folded at the bottom of the summary, "Saved keys (n)" lists the accounts (provider and `…3f9a`, or the address) with "Forget". An account stays when no role uses it any more, so that you can switch back without typing the key again; one that a role uses cannot be forgotten (the button is disabled, with « Il dessine avec »).

Between the questions the API keys are kept in your session, sealed with `APP_ENCRYPTION_KEY`, and never go back to the browser; the draft is dropped once saved, or when you leave for the home page. The screen has no login: the game listens on 127.0.0.1 only and must not be exposed.

## Providers

| Provider | Bridge | Address | API key | Reasoning |
|---|---|---|---|---|
| Anthropic | its own (`symfony/ai-anthropic-platform`) | fixed, `https://api.anthropic.com` | needed | the game decides: nothing is sent (thinking is off until asked) |
| OpenAI | its own (`symfony/ai-open-ai-platform`, Responses API) | fixed | needed | the game decides: nothing for a model that does not think; `reasoning.effort: low` for one the catalog says thinks |
| Mistral | its own (`symfony/ai-mistral-platform`; its streams are the Generic ones, so the strokes arrive as they are drawn) | fixed, `https://api.mistral.ai` | needed | the game decides: nothing is sent, thinking model or not |
| Another OpenAI-compatible service | Generic | the one you give: Ollama, LM Studio or llama.cpp on your machine, OpenRouter, Groq or the Hugging Face router on the internet | optional | **your switch** (below) |

Ollama is not a provider of its own: the game does not use its bridge, which sends no argument delta for a tool call (the strokes would come all at the end). Ollama is an address on the Generic bridge, like the others.

The limit of an answer is sent as `max_output_tokens`, which each bridge turns into the name its API wants (`max_tokens` for Generic, Anthropic and Mistral). A model the catalog does not know yet still works: the game does not trust a catalog, the check tries the model.

**Where the models are chosen.** Only here: the accounts and the role models stored in the database are the only source, there is no environment variable and no command to set it up. Until a drawer is stored the game has no model: the home page says so, "Start the game" leads to the models screen, and `make check` says no model is chosen yet and gives the address of the page. The stored accounts keep their API key encrypted with `APP_ENCRYPTION_KEY` (32 random bytes in base64): `make install` writes one into `.env.local` (`bin/console app:encryption-key`) if there is none and never replaces it. Without it, a setting with an API key cannot be saved; lose it and the stored key cannot be read again, so enter the key again. See [ADR 4](adr/0004-platforms-built-at-runtime-from-a-stored-setting.md) for what this protects and what it does not. Coming from 0.2: choose the model again on the page; the `AI_*` lines of `.env.local` can be deleted.

**The lists of models** are the provider's `GET /v1/models`, kept an hour for Anthropic, OpenAI and Mistral, 30 seconds for another service. They show only the models that can play, for each role:

- Anthropic, OpenAI: the models whose bridge catalog says they read images (and call tools, for the drawer). A model the catalog does not know, an embedding, an audio or an image model is not listed: type its name.
- Mistral: its own answer carries capabilities (vision, function calling) and decides when present; else the catalog of the bridge.
- Another service: no catalog. When its answer carries capabilities in a known shape (OpenRouter and the Hugging Face router: `architecture.input_modalities`, `output_modalities`, `supported_parameters`) the list is filtered on them; otherwise it is shown as it is, sorted by name, with "capabilities unknown, the check will tell". Past 50 models the list is folded under the text field.

The screen says only what is known now: "capabilities unknown" for a server whose list does not say. New models come out every month: nothing is "tested" or "suggested" by the game. The table "What I tried" below is a dated record of what I tried, not a promise.

`make check` tries the model of each role on a tiny task whose answer is known (each must name a circle drawn in an image; the drawer must also draw one stroke with its tool), says where each role answers and which role follows another, and tells what is wrong.

## Reasoning

The game needs short answers every few seconds, and the providers disagree on how to turn reasoning off: a wrong option is a 400.

- **Anthropic, OpenAI, Mistral**: the game decides from the catalog and you see no switch. A model the catalog marks as thinking gets the least effort its API takes (OpenAI: `low`; Anthropic and Mistral: nothing, which is the right thing for the first and what is safe for the second); one that does not think, or a model you typed by name, gets no reasoning option at all.
- **Another OpenAI-compatible service**: the game cannot know, so the screen shows a switch, "Turn reasoning off, for a local model that thinks for too long", under "Advanced settings". It belongs to the role: it is a property of the model chosen. It starts on when the address is local (`localhost`, `127.0.0.1`, `host.docker.internal`, a private network or a name without a dot) and off otherwise, since a cloud API may refuse `reasoning_effort: none` or `chat_template_kwargs`.
- **When the check fails** because of it (a refused option, a block answer, a very slow answer, no usable answer), the message says which way to flip the switch.
- **A model that always reasons** may answer in blocks: the bridges of Mistral read them; the Generic bridge may not (seen with `mistral-large-4` before the bridge of Mistral was used): choose another model, or turn reasoning off.

## What I tried

The game does not need the best model: a poor drawer makes you laugh, a good one makes you play. Drawing is the hard part; guessing works even with a tiny model. What I tried (November 2026):

| Setup | Guesser | Drawer | Notes |
|---|---|---|---|
| **Local**: Ollama, `hf.co/unsloth/Qwen3.8-27B-GGUF:UD-Q3_K_XL` for both roles | 0.7 s per word, finds a swan | a cat with whiskers, a snail with its shell, first stroke after 4 s | 14 GB, fully on a GPU with 16 GB; the official `qwen3.8:27b` (18 GB) also works, but spills onto the CPU and draws its first stroke after 10 s. Not tried on a Mac |
| Anthropic API (`https://api.anthropic.com`) | `claude-haiku-5-5`, about 1 s | `claude-haiku-5-5` too, the best and quickest drawer of my first tests (10 drawings out of 10, 1.5 to 4.5 s each); `claude-sonnet-5-5`, a whale you recognise in 10 s | the best I tried, on any machine; Haiku alone is plenty |
| Mistral API (`https://api.mistral.ai`) | `mistral-small-latest`, 0.5 s, but misses easy drawings | `mistral-medium-3.5`, funny, rarely guessable | models that always reason, such as `mistral-large-4`, answered in a format the Generic bridge could not read (the bridge of Mistral reads it since 19/11/2026; not tried again since) |
| A small model on the CPU, without a GPU: Ollama, `lfm3-vl:2b` for both roles | finds the swan, 3 to 5 s per word | strokes, but nothing you can guess | 1.5 GB; play "I draw, the AI guesses" (LFM Open License) |

Smaller local models, from 2B to 12B (Qwen 3.5, Gemma 4, Ministral 3, Granite 4, LFM), all guess fairly well, but none drew anything recognisable, on a CPU or a GPU. Each role has its own endpoint: a small local guesser can play with a cloud drawer.

- **Local models must not reason**: keep the reasoning switch on, or a small model takes tens of seconds per word. Cloud models may reason: the answer stays short.
- **One model for both roles, or two that fit in memory together**: the roles take turns all the time, and loading a model takes 10 to 45 s.
- **Ollama on Linux** listens on 127.0.0.1 only: start it with `OLLAMA_HOST=0.0.0.0`, so that the game's container reaches it at `host.docker.internal`. Docker Desktop on macOS and Windows does it for you.

Any other OpenAI-compatible server works, llama.cpp or LM Studio. If the game must join a Docker network of yours to reach it, put that in a `compose.override.yaml` (not committed).

The strokes of the AI look drawn by a hand; `HUMANIZED_DRAWING=false` in `.env.local` draws straight segments instead.

## When it does not play

The model screen, the home page and `make check` say what is wrong. The common cases:

- **Nothing answers at this address**: the server is not started, or the address is wrong. From inside Docker, your machine is `host.docker.internal`, not `localhost`.
- **The server refused the API key**: check it on the model screen. A local server may need one too, depending on how it is started.
- **The server does not have this model**: download it first (with Ollama, `ollama pull <model>`), or check its name.
- **The model answers, but the check fails**: it does not read images, or cannot call tools. Both roles need images, the drawer needs tools (it is asked for one stroke).
