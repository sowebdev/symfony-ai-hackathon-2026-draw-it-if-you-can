# 6. The judge is the guessing model, with a strict label and a cache

- Status: accepted
- Date: 2026-11-20, completed on 2026-11-21 (the order of the verdicts)

## Context

Only the exact word counted: « pinguin » or « manchot » for « pingouin » were refused without a word. We want a verdict for every answer (accepted, warm, refused) and an easy mode. Judging meaning needs a model; what the game accepts must not.

A model takes time, up to 8 s, and the exact word none. In a duel, the first verdict given won the round: the rival's exact word, said two seconds after the player's synonym, was accepted while the synonym was still with the model, and won. Two requests settling the same round at once could also overwrite each other.

## Options considered

1. **Code only**: a typo forgiven, nothing for meaning.
2. **A small decision model** (Liquid d1-omni-600M, d1-2B): 20 and 25 right out of 39 pairs in five languages on 20/11/2026, against 34 for the guessing model (Qwen 3.8 27B). Worse, and one more model to install.
3. **The guessing model**, asked for one label, with a cache.

And for the order of the verdicts:

1. **The first verdict given wins**, as before: unfair as soon as the model is slow.
2. **The earliest word wins afterwards**: a round already said found could change sides a few seconds later.
3. **A circuit**: every word is kept as soon as it is said, pending, then judged, and the round is settled in the order the words were said, under one lock per round.

## Decision

Option 3. `WordMatcher` first accepts the word, or one typo of it from six letters. Otherwise `Judge` asks the guessing model for exactly one label (`same_word`, `synonym`, `very_close`, `related`, `unrelated`), within 8 s, and keeps it per pair and language. `Verdict::of()` turns the label into a verdict, by mode. No label: refused, not kept.

The circuit, option 3, is `Referee`, for the player, the AI that guesses and the rival alike. `hear()` keeps the word, pending, at the moment it was said. `judge()` gets the verdict, then `Round::settle()` walks the words in the order they were said: the first accepted finds the round, and a word still pending holds back those said after it. A word whose judge is gone (`Referee::JUDGING_LIMIT_MS`) is refused. The request that said a word answers once the words said before it are settled; refused and warm do not change who wins, but they come in the same answer. `symfony/lock` keeps two requests from settling a round at once.

## Consequences

- A new pair costs one call, up to 8 s; a pair seen before costs nothing and keeps its verdict, right or wrong.
- The model only names a link: an answer that tries to talk it into accepting gets no label.
- The side that said the word first wins it, whatever the judge takes. An exact word said just after a synonym waits for the synonym's verdict, up to the judge's limit: the walrus repeats the word while he thinks, so the wait shows.
- The streams of Olaf and of the agent say a word twice: `heard` when it is said, then its verdict (`guess`, `answer`).
- The judge's latency is now on the path of every new pair: to be measured with each model the game recommends.
