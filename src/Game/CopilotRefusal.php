<?php

namespace App\Game;

/**
 * The game does not take a hint of the copilot. The message is meant for the agent.
 */
final class CopilotRefusal extends \RuntimeException
{
}
