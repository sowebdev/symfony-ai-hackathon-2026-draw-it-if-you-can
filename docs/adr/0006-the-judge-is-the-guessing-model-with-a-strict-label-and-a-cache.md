# 6. The judge is the guessing model, with a strict label and a cache

- Status: accepted
- Date: 2026-11-20

## Context

Only the exact word counted: « pinguin » or « manchot » for « pingouin » were refused without a word. We want a verdict for every answer (accepted, warm, refused) and an easy mode. Judging meaning needs a model; what the game accepts must not.

## Options considered

1. **Code only**: a typo forgiven, nothing for meaning.
2. **A small decision model** (Liquid d1-omni-600M, d1-2B): 20 and 25 right out of 39 pairs in five languages on 20/11/2026, against 34 for the guessing model (Qwen 3.8 27B). Worse, and one more model to install.
3. **The guessing model**, asked for one label, with a cache.

## Decision

Option 3. `WordMatcher` first accepts the word, or one typo of it from six letters. Otherwise `Judge` asks the guessing model for exactly one label (`same_word`, `synonym`, `very_close`, `related`, `unrelated`), within 8 s, and keeps it per pair and language. `Verdict::of()` turns the label into a verdict, by mode. No label: refused, not kept.

## Consequences

- A new pair costs one call, up to 8 s; a pair seen before costs nothing and keeps its verdict, right or wrong.
- The model only names a link: an answer that tries to talk it into accepting gets no label.
