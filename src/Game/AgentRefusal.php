<?php

namespace App\Game;

/**
 * The game does not take what an agent connected over MCP asks: a hint, an answer. The message is meant for the agent.
 */
final class AgentRefusal extends \RuntimeException
{
}
