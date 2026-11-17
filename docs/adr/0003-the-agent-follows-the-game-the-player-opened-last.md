# 3. The agent follows the game the player opened last

- Status: accepted
- Date: 2026-11-17

## Context

A tool of the MCP agent called without a round id plays "the" game. `AgentTools::find()` took the last game created. With two games open, an old tab or a game created elsewhere, the agent played a game the player was not looking at.

The agent has no session on the stateless protocol (2026-07-28) and nothing in its calls says which game the player means: the game has to guess it from what the player does.

## Options considered

1. **The game created last** (the first version). Simple, and right with one game. Wrong as soon as the player goes back to an older game, or something else creates one.
2. **The game whose page the player opened last.** A page load of the game, its lobby or one of its rounds says where the player looks. Polling and event streams do not count: an old tab keeps polling.
3. **A code to join**, shown in the game and typed by the agent. It is exact, but the player must copy it to the terminal for every game, which is the friction the lobby was made to remove.
4. **A game id in the MCP address** (`/mcp/<game>`). Exact too, but the address is set once with `claude mcp add`, and changes with each game.

## Decision

Option 2. `Game` records `openedAt`, set when the game is created and when its page, its lobby or one of its rounds is loaded. `GameRepository::findToFollow()` returns the game with the latest `openedAt` among those the player did not leave (`leftAt`), the latest id breaking a tie, as the dates keep the second only.

- Solo games count. If the player's last page is a solo game, the agent gets the answer it always got for one: "the player plays this game alone: stop playing". Leaving them out made a copilot play on a game nobody looks at.
- A game left by the player is never followed while another one is open. When the player left them all, the agent still gets the one opened last, to be told to stop.
- A tool called with a round id is unchanged.

## Consequences

- The agent follows the player from one game to another, with no step for the player.
- Two windows side by side: the last page opened wins, and a reload of the other window takes the agent back. The game cannot see which window the player looks at.
- A page load writes the game (one row), where it only read it.
- Tests share one database, so `McpClient::connect()` empties the games first: a game left by an earlier test, opened ahead by a sleep of the mocked clock, would be followed.
