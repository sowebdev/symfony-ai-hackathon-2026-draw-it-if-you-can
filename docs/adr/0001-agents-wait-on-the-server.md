# 1. Agents wait on the server

- Status: accepted
- Date: 2026-11-13

## Context

An agent connected over MCP, as a copilot or as the rival in a duel, spends most of a game waiting. It waits in the lobby until the player starts the game, between two rounds while the player reads the result, and during a round until the drawing grows.

An agent cannot wait by itself. It acts only when its model takes a turn, and a turn only calls tools. MCP has no tool or message that suspends an agent until something happens.

In the first version, `round_status` answered at once. Claude Code called it two or three times in a row in the lobby, then stopped and asked the player to say "go". The player had to go back to the terminal to start every game.

## Options considered

1. **The prompt tells the agent to try again every few seconds.** The model has nothing to wait with, except a tool of its own client, such as `sleep` in the shell of Claude Code. That tool asks for a permission, and clients without a shell lack it. Each try also costs a turn of the model: five minutes in the lobby at one try every 5 s make 60 turns, and the context grows with each. A model also decides by itself to stop after a few tries, which is the behaviour we saw.
2. **MCP notifications.** The server can send notifications (`progress`, `resources/updated`, `tools/list_changed`). They go to the client application, which may show a progress bar or read the list of tools again. None of them starts a turn of the model, so none can wake an agent up. Resource subscriptions are also tied to a session, and Claude Code speaks the stateless protocol (2026-07-28), which has none.
3. **Features of one client**, such as a channel that pushes messages into a Claude Code session. They would tie the game to one client, when it also plays with Mistral Vibe and others.
4. **The server waits: long polling in a tool.** The agent calls a tool, which answers as soon as there is something new for it, or after a bounded time with nothing new. The agent then calls it again.

## Decision

Option 4, with a tool of its own, `wait_for_your_turn`.

- It answers when a round starts, when the round ends, or when the drawing the agent plays on has strokes the agent has not seen. `look_at_drawing` keeps on the round how many strokes the agent saw.
- It answers after 25 s with nothing new. The bound stays under the 30 s `max_execution_time` of PHP. Claude Code and Mistral Vibe both played through waits of 25 s without a timeout (2026-11-12).
- It answers with the same status as `round_status`, plus a `news` field: `a new round started`, `the round is over`, `new strokes on the drawing`, `stop` or `nothing new yet`.
- `round_status` always answers at once.

A first version made `round_status` wait. A tool named "status" that blocks surprised both readers of the code and agents, and it delayed the first answer of an agent in the lobby by 25 s. The agent read that it should choose a nickname only after that, and the player saw the name of its client meanwhile. The wait now has its own tool, with a name that says it waits.

## Consequences

- The agent follows one loop: wait, look, answer, wait. The prompts and the `what_you_can_do` field of each status say so, for the clients that do not offer prompts.
- A waiting call holds one PHP thread of FrankenPHP for up to 25 s. That is fine for a game played on localhost by one player and one agent, and would not be for many agents on one server.
- The wait polls the database every 500 ms. On SQLite with one game, the cost is negligible.
- What is new is judged against the state of the game when the call starts. Something that happens between two calls is not reported as news, but the status shows it, e.g. `state: time is up`.
- In the tests, `game.agent_wait_s` is 0: the tool answers at once, and `App\Game\AgentTurn` is tested on its own for what counts as news.
