<?php

namespace App\Game;

/**
 * The name of an agent, as the player reads it: a nickname the agent chose, or else its MCP
 * client, which says "claude-code" when the game shows "Claude". A client the game does not know keeps its name, made readable; no name gives null,
 * and the screen shows "Rival" or "Copilot" instead.
 */
final class AgentName
{
    /** Names MCP clients give themselves, and how the game shows them. */
    private const array KNOWN = [
        'claude-code' => 'Claude',
        'claude-ai' => 'Claude',
        'claude' => 'Claude',
        'mistral-vibe' => 'Vibe',
        'vibe' => 'Vibe',
        'le-chat' => 'Le Chat',
        'codex' => 'Codex',
        'codex-mcp-client' => 'Codex',
        'gemini-cli' => 'Gemini',
        'cursor' => 'Cursor',
        'goose' => 'Goose',
        'opencode' => 'OpenCode',
        'pi' => 'Pi',
        'visual studio code' => 'Copilot',
    ];

    /** Names that say nothing of the client: the default of an MCP library, e.g. "mcp" for Mistral Vibe. */
    private const array GENERIC = ['mcp', 'mcp client', 'mcp-client', 'client', 'app'];

    /** Longer names are cut: the name sits in a bar and in headings. */
    private const int MAX_LENGTH = 20;

    /** The longest nickname an agent may choose. */
    public const int MAX_NICKNAME_LENGTH = 24;

    /**
     * A nickname the agent chose, cleaned: one line of printable characters.
     *
     * @throws \InvalidArgumentException When it is empty or too long, with the reason for the agent
     */
    public static function nickname(string $nickname): string
    {
        $nickname = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $nickname));
        if ('' === $nickname) {
            throw new \InvalidArgumentException('A nickname needs at least one letter.');
        }
        if (mb_strlen($nickname) > self::MAX_NICKNAME_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A nickname is %d characters at most: it sits in the score bar.', self::MAX_NICKNAME_LENGTH));
        }

        return $nickname;
    }

    public static function forDisplay(?string $clientName): ?string
    {
        $name = trim((string) $clientName);
        $key = mb_strtolower($name);
        if ('' === $name || \in_array($key, self::GENERIC, true)) {
            return null;
        }
        if (isset(self::KNOWN[$key])) {
            return self::KNOWN[$key];
        }

        $readable = trim((string) preg_replace('/[\s_-]+/', ' ', $name));
        $readable = mb_strtoupper(mb_substr($readable, 0, 1)).mb_substr($readable, 1);

        return mb_strlen($readable) > self::MAX_LENGTH ? rtrim(mb_substr($readable, 0, self::MAX_LENGTH - 1)).'…' : $readable;
    }
}
