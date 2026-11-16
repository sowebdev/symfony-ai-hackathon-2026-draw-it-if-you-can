# The models

The AI has two roles, each with its own OpenAI-compatible endpoint, model and optional API key:

- **the drawer** draws while you guess, and looks at its drawing to add the details that are missing;
- **the guesser** picks the word you draw, then guesses your drawing.

Both models must read images, and the drawer must also call tools.

`make setup` gives both roles the same model, which is what you want most of the time. To give each role its own, set them by hand in `.env.local` (defaults in `.env`):

```bash
AI_DRAWER_BASE_URL=https://api.anthropic.com
AI_DRAWER_MODEL=claude-sonnet-5-5
AI_DRAWER_API_KEY=...
AI_DRAWER_DISABLE_THINKING=false   # true turns reasoning off: for local models, an API may refuse it
```

Then `make check` tries each model on a tiny task whose answer is known (each must name a circle drawn in an image) and tells what is wrong.

## Reasoning

What tells a cloud API from a server of your own is not the address but **reasoning**:

- **A local model must not reason**: keep `DISABLE_THINKING=true` (`make setup` sets it for a local server), or a 27B model takes tens of seconds per word. The game then sends `reasoning_effort: none` and, for llama.cpp, `chat_template_kwargs`.
- **A cloud model may reason**: its answers stay short and fast. Some APIs refuse the option that turns reasoning off: keep `DISABLE_THINKING=false` there.
- **A model that always reasons** may answer in blocks the game cannot read yet (seen with `mistral-large-4`): choose one that does not.

## What I tried

The game does not need the best model: a poor drawer makes you laugh, a good one makes you play. Drawing is the hard part; guessing works even with a tiny model. What I tried (November 2026):

| Setup | Guesser | Drawer | Notes |
|---|---|---|---|
| **Default**: Ollama, `hf.co/unsloth/Qwen3.8-27B-GGUF:UD-Q3_K_XL` for both roles | 0.7 s per word, finds a swan | a cat with whiskers, a snail with its shell, first stroke after 4 s | 14 GB, fully on a 16 GB GPU; the official `qwen3.8:27b` (18 GB) also works, but spills onto the CPU and draws its first stroke after 10 s. Not tried on a Mac |
| Anthropic API (`https://api.anthropic.com`) | `claude-haiku-5-5`, about 1 s | `claude-haiku-5-5` too, the best and quickest drawer of my first tests (10 drawings out of 10, 1.5 to 4.5 s each); `claude-sonnet-5-5`, a whale you recognise in 10 s | the best I tried, on any machine; Haiku alone is plenty |
| Mistral API (`https://api.mistral.ai`) | `mistral-small-latest`, 0.5 s, but misses easy drawings | `mistral-medium-3.5`, funny, rarely guessable | models that always reason, such as `mistral-large-4`, answer in a format the game cannot read yet |
| A small model on the CPU, without a GPU: Ollama, `lfm3-vl:2b` for both roles | finds the swan, 3 to 5 s per word | strokes, but nothing you can guess | 1.5 GB; play "I draw, the AI guesses" (LFM Open License) |

Smaller local models, from 2B to 12B (Qwen 3.5, Gemma 4, Ministral 3, Granite 4, LFM), all guess fairly well, but none drew anything recognisable, on a CPU or a GPU. Each role has its own endpoint: a small local guesser can play with a cloud drawer.

- **Local models must not reason**: keep `DISABLE_THINKING=true`, or a small model takes tens of seconds per word. Cloud models may reason: the answer stays short.
- **One model for both roles, or two that fit in memory together**: the roles take turns all the time, and loading a model takes 10 to 45 s.
- **Ollama on Linux** listens on 127.0.0.1 only: start it with `OLLAMA_HOST=0.0.0.0`, so that the game's container reaches it at `host.docker.internal`. Docker Desktop on macOS and Windows does it for you.

Any other OpenAI-compatible server works, llama.cpp or LM Studio. If the game must join a Docker network of yours to reach it, put that in a `compose.override.yaml` (not committed).

The strokes of the AI look drawn by a hand; `HUMANIZED_DRAWING=false` in `.env.local` draws straight segments instead.

## When it does not play

The home page and `make check` say what is wrong. The common cases:

- **Nothing answers at this address**: the server is not started, or the address is wrong. From inside Docker, your machine is `host.docker.internal`, not `localhost`.
- **The server refused the API key**: check `AI_<ROLE>_API_KEY`. A local server may need one too, depending on how it is started.
- **The server does not have this model**: download it first (with Ollama, `ollama pull <model>`), or check its name.
- **The model answers, but the check fails**: it does not read images, or cannot call tools. Both roles need images, the drawer needs tools.
