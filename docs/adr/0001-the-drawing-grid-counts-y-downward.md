# 1. The drawing grid counts y downward

- Status: accepted
- Date: 2026-11-10 (recorded on 2026-11-13)

## Context

The AI draws on a grid of 50 x 50 cells, one stroke at a time, by naming the cells the pen goes through (`x10y12,x15y20,...`). The method comes from SketchAgent (MIT and Stanford, CVPR 2025, arXiv 2411.17673), where a frozen multimodal model draws on a numbered grid without any training.

In the paper, `x1y1` is the bottom left cell: y grows upward, as in a maths plot. The canonical stroke of the game, shared by the player and the AI, uses screen coordinates instead: `[x, y, t]` with x and y from 0 to 1000 and the origin at the top left, as in a canvas or an SVG.

Before writing the game, test A (2026-11-10) asked several models to draw five words (cat, bicycle, tree, rocket, insect) with two seeds each, with our own prompt and a JSON schema for the output. Each drawing was then shown blind, without the theme, to a model that had to name it. The first runs kept the convention of the paper.

## Options considered

1. **y upward, as in the paper.** The prompts of SketchAgent were tuned with it, but for Claude 3.5 Sonnet, not for the models we can run.
2. **y downward, as on a screen.** The convention of the canonical stroke, of the canvas, of SVG, and of most of the images and code the models were trained on.

Both were measured in test A, with the same words, seeds and prompt, the grid convention being the only change:

| Drawer | y upward | y downward |
|---|---|---|
| Gemma 4 12B, local, no reasoning | 2 / 10 | 5 / 10 |
| Gemma 4 12B, local, with reasoning | 2 / 10 | 4 / 10 |
| Mistral Small | 1 / 10 | 1 / 10 |

With y upward, many drawings came out upside down: a cat with its ears at the bottom, a rocket flying to the ground. With y downward, cats, rockets and insects could be told apart. Reasoning did not help once the grid was fixed, and cost 10 to 20 times more time.

## Decision

Option 2: on the grid, x goes from 1 (left) to 50 (right) and y from 1 (top) to 50 (bottom). The prompt of the drawer says so in those words, "like screen coordinates" (`App\Ai\Drawer`), and `App\Drawing\GridStroke` reads the cells the same way.

## Consequences

- The grid and the canonical stroke follow the same orientation. Turning a cell into canvas coordinates is a scale and an offset, with no flip, in `App\Drawing\StrokeTracer`.
- Our prompts depart from those of the paper, which are under CC BY-NC-SA 4.0 anyway: we reuse the method and write our own prompts.
- Every later model was measured with y downward only (test A widened to Claude Haiku 5.5, Qwen3.8 27B, Mistral Large and others, judged by a single judge). A model that draws upside down would be a reason to test the other convention again, not to make it a setting.
- The scores of test A are a lower bound: the judge saw each drawing blind, with one word to give. In a game, the guesser knows the theme and makes several guesses.
