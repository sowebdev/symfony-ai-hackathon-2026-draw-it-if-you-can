<?php

namespace App\Entity;

use App\Game\AgentName;
use App\Game\DrawnBy;
use App\Game\Language;
use App\Game\Mode;
use App\Game\Theme;
use App\Game\Turns;
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

    /** When the player last opened a page of the game, or created it: the agent follows the game opened last. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    /** Dealt ahead of the next round, so that its card is shown while the AI picks the word. */
    #[ORM\Column(enumType: Theme::class, nullable: true)]
    private ?Theme $nextTheme = null;

    /** @var list<string> The values of the themes dealt, kept with the game: changing the settings changes nothing in it */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $themes;

    /**
     * @param list<Theme>|null $themes The themes dealt in this game, as the settings said when it started; null for all of them
     */
    public function __construct(
        #[ORM\Column(enumType: Language::class)]
        private Language $language,
        #[ORM\Column(options: ['default' => 1])]
        private int $roundCount = 6,
        #[ORM\Column(length: 10, enumType: DrawnBy::class, options: ['default' => 'player'])]
        private DrawnBy $firstDrawnBy = DrawnBy::Ai,
        /** With an agent connected over MCP, copilot or rival, the game waits for it in a lobby. */
        #[ORM\Column(length: 10, enumType: Mode::class, options: ['default' => 'solo'])]
        private Mode $mode = Mode::Solo,
        /** Who draws, as the settings said when the game started. */
        #[ORM\Column(length: 10, enumType: Turns::class, options: ['default' => 'alternate'])]
        private Turns $turns = Turns::Alternate,
        #[ORM\Column(options: ['default' => 60])]
        private int $roundSeconds = 60,
        ?array $themes = null,
        /** The easy mode of the judge, as the settings said when the game started. */
        #[ORM\Column(options: ['default' => false])]
        private bool $easyMode = false,
    ) {
        if ($roundCount < 1) {
            throw new \InvalidArgumentException('A game has at least one round.');
        }
        if ($roundSeconds < 1) {
            throw new \InvalidArgumentException('A round lasts at least a second.');
        }
        if ([] === $themes) {
            throw new \InvalidArgumentException('A game has at least one theme.');
        }
        $this->themes = array_map(static fn (Theme $theme): string => $theme->value, $themes ?? Theme::cases());
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->openedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getNextTheme(): ?Theme
    {
        return $this->nextTheme;
    }

    public function dealNextTheme(Theme $theme): void
    {
        $this->nextTheme = $theme;
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

    public function getTurns(): Turns
    {
        return $this->turns;
    }

    public function isEasyMode(): bool
    {
        return $this->easyMode;
    }

    public function getRoundSeconds(): int
    {
        return $this->roundSeconds;
    }

    /**
     * The themes dealt in this game.
     *
     * @return list<Theme>
     */
    public function getThemes(): array
    {
        return array_values(array_filter(Theme::cases(), fn (Theme $theme): bool => \in_array($theme->value, $this->themes, true)));
    }

    /**
     * Who draws round $number, from 1: with turns, the one who starts draws the odd rounds; else
     * the same side draws every round.
     */
    public function drawnByInRound(int $number): DrawnBy
    {
        return $this->turns->drawnByInRound($number, $this->firstDrawnBy);
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

    /**
     * The player loaded a page of the game: its page, its lobby or one of its rounds.
     */
    public function opened(\DateTimeImmutable $at): void
    {
        $this->openedAt = $at;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt ?? $this->createdAt;
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
