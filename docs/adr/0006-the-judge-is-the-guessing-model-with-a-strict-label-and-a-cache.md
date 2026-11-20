# 6. The judge is the guessing model, with a strict label and a cache

- Status: accepted
- Date: 2026-11-20

## Context

Until now only the exact word counted (`WordMatcher`: case, accents, article, final s or x). « pinguin »
for « pingouin » was refused, « manchot » too, without a word. We want a verdict for every answer, whoever
gives it (the player, Olaf, the agent of a duel): **accepted**, **warm** (« ça chauffe ») or **refused**,
and an easy mode that is more generous. Judging a meaning needs a model; what the game accepts must not
depend on what a model feels like saying.

## Options considered

1. **Code only**: one typo forgiven, nothing for meaning. Cheap and deterministic, but « manchot » for
   « pingouin » stays refused.
2. **A small decision model** dedicated to judging (Liquid d1-omni-600M and d1-2B). Tried on 20/11/2026
   on 39 pairs in five languages, in normal mode: 20 and 25 right out of 39, against 34 for the guessing
   model (Qwen 3.8 27B, local). They judge meaning worse, and would add a role, a model to install and a
   screen to configure.
3. **The model that already guesses** (`Role::Guesser`), asked for a link, with a strict output and a cache.

## Decision

Option 3, in two steps, with the code deciding:

- **Code first.** `WordMatcher` accepts the word as before, and one typo (edit distance 1 on the normalized
  words) when the word to find has six letters or more. The model is not asked.
- **Then one question** to the guessing model (`App\Ai\Judge`, no new role, no new setting): which link
  between the answer and the word, in the language of the game. It must answer exactly one label:
  `same_word`, `synonym`, `very_close`, `related`, `unrelated`. The code reads the label (case and
  punctuation ignored, `<think>` blocks removed); anything else is no label. A short answer budget and a
  time limit of 8 s, well below the other calls.
- **The code decides** the verdict from the label, in one table (`App\Game\Verdict::of()`), by mode:
  `same_word` is accepted; `synonym` and `very_close` are warm, or accepted in the easy mode; `related` is
  refused, or warm in the easy mode; `unrelated` is refused.
- **A cache**: a judged pair (normalized word to find, normalized answer, language) keeps its label in the
  database, unique on the three, across games. A pair judged once is never asked again, which also keeps
  the verdict of a pair stable.
- **No label** (timeout, error, unreadable answer): the answer is refused, silently, logged as a warning,
  and nothing is cached.

## Consequences

- An answer costs a model call the first time a pair is seen, up to 8 s for the player who waits; a pair
  seen before, an exact word or a typo costs nothing.
- The model can only name a link: a model that talks, argues or is told to accept by the answer itself
  gets no label or a label the table refuses. The cache keeps its first label, right or wrong.
- A game keeps the mode it started with (a column on `Game`), like its rounds and turns.
- `DrawCommand` keeps the plain matcher's role: it never asks a model to judge.
- The verdict is stored on each `Guess`, and sent to the screen and to the agent.
- Not decided here: the referee who will say « ça chauffe » on screen.
