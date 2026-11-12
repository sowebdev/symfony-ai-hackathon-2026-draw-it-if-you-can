<?php

namespace App\Entity;

use App\Game\DrawnBy;
use App\Game\Language;
use App\Game\Mode;
use App\Game\Theme;
use App\Repository\GameRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A game: rounds where the player and the AI take turns to draw, for a score they share. In a
 * duel, an agent connected over MCP guesses too, and wins the points of the words it finds first.
 */
#[ORM\Entity(repositoryClass: GameRepository::class)]
class Game
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** The name the MCP client of the agent gave, e.g. "claude-code": said by the client, never checked. */
    #[ORM\Column(name: 'copilot_name', length: 100, nullable: true)]
    private ?string $agentName = null;

    #[ORM\Column(name: 'copilot_seen_at', nullable: true)]
    private ?\DateTimeImmutable $agentSeenAt = null;

    /** The MCP session of the agent that plays this game: one agent per game. */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $agentSession = null;

    /** The player left the game before its end: the copilot is told to stop. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $leftAt = null;

    public function __construct(
        #[ORM\Column(enumType: Theme::class)]
        private Theme $theme,
        #[ORM\Column(enumType: Language::class)]
        private Language $language,
        #[ORM\Column(options: ['default' => 1])]
        private int $roundCount = 6,
        #[ORM\Column(length: 10, enumType: DrawnBy::class, options: ['default' => 'player'])]
        private DrawnBy $firstDrawnBy = DrawnBy::Ai,
        /** With an agent connected over MCP, copilot or rival, the game waits for it in a lobby. */
        #[ORM\Column(length: 10, enumType: Mode::class, options: ['default' => 'solo'])]
        private Mode $mode = Mode::Solo,
    ) {
        if ($roundCount < 1) {
            throw new \InvalidArgumentException('A game has at least one round.');
        }
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTheme(): Theme
    {
        return $this->theme;
    }

    public function getLanguage(): Language
    {
        return $this->language;
    }

    public function getRoundCount(): int
    {
        return $this->roundCount;
    }

    public function getFirstDrawnBy(): DrawnBy
    {
        return $this->firstDrawnBy;
    }

    /**
     * Who draws round $number, from 1: the one who starts draws the odd rounds.
     */
    public function drawnByInRound(int $number): DrawnBy
    {
        return 1 === $number % 2 ? $this->firstDrawnBy : $this->firstDrawnBy->other();
    }

    public function getMode(): Mode
    {
        return $this->mode;
    }

    public function isWithCopilot(): bool
    {
        return Mode::Copilot === $this->mode;
    }

    public function isDuel(): bool
    {
        return Mode::Duel === $this->mode;
    }

    public function isWithAgent(): bool
    {
        return $this->mode->withAgent();
    }

    /**
     * Whether the agent of this MCP session may play this game: the first one to call it takes it.
     * Another one takes its place in the lobby, where the player sees who is connected, or once the
     * agent has been silent for $silentS seconds, after a restart of its client for instance.
     */
    public function mayBePlayedBy(string $session, bool $inLobby, \DateTimeImmutable $now, int $silentS): bool
    {
        return null === $this->agentSession
            || $this->agentSession === $session
            || $inLobby
            || null === $this->agentSeenAt
            || $now->getTimestamp() - $this->agentSeenAt->getTimestamp() > $silentS;
    }

    /**
     * The agent called a tool: it is connected, and plays this game.
     */
    public function agentSeen(string $name, ?string $session, \DateTimeImmutable $at): void
    {
        $this->agentName = mb_substr('' === trim($name) ? 'MCP client' : $name, 0, 100);
        $this->agentSession = $session ?? $this->agentSession;
        $this->agentSeenAt = $at;
    }

    public function isAgentConnected(): bool
    {
        return null !== $this->agentSeenAt;
    }

    public function getAgentName(): ?string
    {
        return $this->agentName;
    }

    public function getAgentSeenAt(): ?\DateTimeImmutable
    {
        return $this->agentSeenAt;
    }

    public function leave(\DateTimeImmutable $at): void
    {
        $this->leftAt ??= $at;
    }

    public function hasLeft(): bool
    {
        return null !== $this->leftAt;
    }
}
