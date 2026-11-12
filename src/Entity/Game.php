<?php

namespace App\Entity;

use App\Game\AgentName;
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

    /** The nickname the agent chose for itself, e.g. "Picasso du dimanche": shown instead of its name. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $agentNickname = null;

    #[ORM\Column(name: 'copilot_seen_at', nullable: true)]
    private ?\DateTimeImmutable $agentSeenAt = null;

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
     * The agent called a tool: it is connected.
     */
    public function agentSeen(string $name, \DateTimeImmutable $at): void
    {
        $this->agentName = '' === trim($name) ? null : mb_substr($name, 0, 100);
        $this->agentSeenAt = $at;
    }

    public function isAgentConnected(): bool
    {
        return null !== $this->agentSeenAt;
    }

    /**
     * The name the MCP client gave itself, e.g. "claude-code", null without one.
     */
    public function getAgentName(): ?string
    {
        return $this->agentName;
    }

    /**
     * The name the player reads: the nickname of the agent, else its client made readable, e.g.
     * "Claude"; null when there is neither.
     */
    public function getAgentDisplayName(): ?string
    {
        return $this->agentNickname ?? AgentName::forDisplay($this->agentName);
    }

    public function getAgentNickname(): ?string
    {
        return $this->agentNickname;
    }

    /**
     * @throws \InvalidArgumentException When the nickname is empty or too long, with the reason for the agent
     */
    public function nameAgent(string $nickname): void
    {
        $this->agentNickname = AgentName::nickname($nickname);
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
