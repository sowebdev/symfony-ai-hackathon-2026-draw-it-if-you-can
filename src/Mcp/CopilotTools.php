<?php

namespace App\Mcp;

use App\Drawing\StrokeRenderer;
use App\Entity\Game;
use App\Entity\Guess;
use App\Entity\Hint;
use App\Entity\Round;
use App\Entity\Stroke;
use App\Game\Copilot;
use App\Game\CopilotRefusal;
use App\Repository\GameRepository;
use App\Repository\GuessRepository;
use App\Repository\HintRepository;
use App\Repository\RoundRepository;
use App\Repository\StrokeRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Server\RequestContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\Uid\Uuid;

/**
 * The game as seen by the copilot, an agent connected over MCP: the state of the round, the
 * drawing in progress, and one way to act, a hint to the player. The word to find is never given.
 */
final readonly class CopilotTools
{
    /** A round over, and no next one for this long: the player has probably stopped. */
    public const int IDLE_S = 120;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private GameRepository $games,
        private RoundRepository $rounds,
        private StrokeRepository $strokes,
        private GuessRepository $guesses,
        private HintRepository $hints,
        private StrokeRenderer $renderer,
        private Copilot $copilot,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'round_status', description: 'The round being played in Draw It If You Can: who draws, the theme, the time left, the wrong answers of the player and your hints so far. Never the word to find.')]
    public function roundStatus(
        #[Schema(description: 'Id of a round; leave empty for the round being played')]
        ?string $round = null,
        ?RequestContext $context = null,
    ): array {
        [$game, $current] = $this->find($round, $context);
        [$stop, $advice] = $this->advice($game, $current);
        if (null === $current) {
            return [
                'game' => $game->getId()->toRfc4122(),
                'state' => $game->hasLeft() ? 'the player left' : 'waiting for the player to start the game',
                'theme' => $game->getTheme()->description(),
                'language' => $game->getLanguage()->nameInEnglish(),
                'stop' => $stop,
                'what_you_can_do' => $advice,
            ];
        }

        return [
            'round' => $current->getId()->toRfc4122(),
            'number' => $current->getNumber(),
            'of' => $game->getRoundCount(),
            'drawn_by' => $current->getDrawnBy()->value,
            'theme' => $game->getTheme()->description(),
            'language' => $game->getLanguage()->nameInEnglish(),
            'time_left_s' => intdiv($current->remainingMs(), 1000),
            'state' => match (true) {
                $game->hasLeft() => 'the player left',
                $current->isFound() => 'found',
                $current->isGivenUp() => 'given up',
                $current->isOver() => 'time is up',
                default => 'playing',
            },
            'strokes' => \count($this->strokes->findByRound($current)),
            'wrong_answers' => $current->isDrawnByAi() ? $this->wrongAnswers($current) : [],
            'your_hints' => array_map(static fn (Hint $hint): string => $hint->getWord(), $this->hints->findByRound($current)),
            'hints_left' => max(0, Copilot::MAX_HINTS - \count($this->hints->findByRound($current))),
            'stop' => $stop,
            'what_you_can_do' => $advice,
        ];
    }

    /**
     * @return list<TextContent|ImageContent>
     */
    #[McpTool(name: 'look_at_drawing', description: 'The drawing of the round as it is now, as a PNG image, black strokes on white. The AI may still be adding strokes: look again later.')]
    public function lookAtDrawing(
        #[Schema(description: 'Id of a round; leave empty for the round being played')]
        ?string $round = null,
        ?RequestContext $context = null,
    ): array {
        $current = $this->findRound($round, $context);
        $strokes = $this->strokes->findByRound($current);
        if ([] === $strokes) {
            return [new TextContent('The canvas is still empty: nothing drawn yet. Look again in a few seconds.')];
        }

        $png = $this->renderer->toPng(array_map(static fn (Stroke $stroke): array => $stroke->getPoints(), $strokes));

        return [
            new TextContent(\sprintf('Round %d, %d strokes, %d s left.', $current->getNumber(), \count($strokes), intdiv($current->remainingMs(), 1000))),
            new ImageContent(base64_encode($png), 'image/png'),
        ];
    }

    #[McpTool(name: 'suggest_word', description: 'Whisper a word to the player who guesses the drawing of the AI: it appears on screen, and only the player can answer it. At most 5 per round, in the language of the game.')]
    public function suggestWord(
        #[Schema(description: 'The word you think the AI is drawing, in the language of the game, e.g. "girafe" in French')]
        string $word,
        #[Schema(description: 'Id of a round; leave empty for the round being played')]
        ?string $round = null,
        ?RequestContext $context = null,
    ): string {
        $current = $this->findRound($round, $context);
        try {
            $hint = $this->copilot->suggest($current, $word);
        } catch (CopilotRefusal $refusal) {
            throw new ToolCallException($refusal->getMessage(), previous: $refusal);
        }

        return \sprintf('"%s" is shown to the player, who decides. %d hints left in this round.', $hint->getWord(), Copilot::MAX_HINTS - \count($this->hints->findByRound($current)));
    }

    /**
     * The game and round asked for, by default the last ones; the copilot is marked as seen on the game.
     *
     * @return array{Game, ?Round} No round yet while the game waits in its lobby
     */
    private function find(?string $round, ?RequestContext $context): array
    {
        if (null === $round || '' === trim($round)) {
            $game = $this->games->findLatest() ?? throw new ToolCallException('No game has started yet: the player starts one at http://127.0.0.1:8040, with a copilot.');
            $found = $this->rounds->findLastOfGame($game);
        } elseif (!Uuid::isValid($round)) {
            throw new ToolCallException('A round id looks like 019a6e2c-0000-7000-8000-000000000000; leave it empty for the round being played.');
        } else {
            $found = $this->rounds->find(Uuid::fromString($round)) ?? throw new ToolCallException('No round has this id.');
            $game = $found->getGame();
        }

        $client = $context?->getSession()->get('client_info');
        $game->copilotSeen(\is_array($client) && \is_string($client['name'] ?? null) ? $client['name'] : '', new DatePoint());
        $this->entityManager->flush();

        return [$game, $found];
    }

    private function findRound(?string $round, ?RequestContext $context): Round
    {
        return $this->find($round, $context)[1] ?? throw new ToolCallException('The game has not started: the player is in the lobby. Call round_status until it starts.');
    }

    /**
     * @return list<string>
     */
    private function wrongAnswers(Round $round): array
    {
        return array_values(array_map(
            static fn (Guess $guess): string => $guess->getWord(),
            array_filter($this->guesses->findByRound($round), static fn (Guess $guess): bool => !$guess->isAccepted()),
        ));
    }

    /**
     * Whether the copilot should stop, and what it can do.
     *
     * @return array{bool, string}
     */
    private function advice(Game $game, ?Round $round): array
    {
        $idle = $round?->msSinceEnd();

        return match (true) {
            $game->hasLeft() => [true, 'The player left the game. Stop playing; the player will call you again for another game.'],
            !$game->isWithCopilot() => [true, 'The player now plays alone, without a copilot. Stop playing.'],
            null === $round => [false, 'You are connected: the player sees it in the lobby. Call round_status again in a few seconds, until the game starts.'],
            $round->isOver() && $round->isLast() => [true, 'The game is over. Stop playing.'],
            null !== $idle && $idle > self::IDLE_S * 1000 => [true, \sprintf('No new round for %d minutes: the player has probably stopped. Stop playing; the player will call you again.', intdiv(self::IDLE_S, 60))],
            $round->isOver() => [false, 'This round is over: wait for the player to start the next one, then call round_status again.'],
            !$round->isDrawnByAi() => [false, 'The player draws and the AI guesses: nothing for you to do in this round. Call round_status again later.'],
            default => [false, 'The AI draws and the player guesses: call look_at_drawing, then suggest_word with your best guess. Look again as the drawing grows.'],
        };
    }
}
