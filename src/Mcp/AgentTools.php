<?php

namespace App\Mcp;

use App\Drawing\StrokeRenderer;
use App\Entity\Game;
use App\Entity\Guess;
use App\Entity\Hint;
use App\Entity\Round;
use App\Entity\Stroke;
use App\Game\AgentRefusal;
use App\Game\AgentTurn;
use App\Game\Author;
use App\Game\Copilot;
use App\Game\Duel;
use App\Game\ScoreGauge;
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
use Mcp\Server\Stateless\RequestMeta;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The game as seen by an agent connected over MCP: the state of the round and the drawing in
 * progress. The copilot acts by a hint to the player, the rival of a duel by its own answers.
 * The word to find is never given.
 */
final readonly class AgentTools
{
    /** A round over, and no next one for this long: the player has probably stopped. */
    public const int IDLE_S = 120;

    /** While wait_for_your_turn waits, it looks at the game this often. */
    private const int WAIT_PAUSE_MS = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private GameRepository $games,
        private RoundRepository $rounds,
        private StrokeRepository $strokes,
        private GuessRepository $guesses,
        private HintRepository $hints,
        private StrokeRenderer $renderer,
        private ScoreGauge $gauge,
        private Copilot $copilot,
        private Duel $duel,
        #[Autowire('%game.agent_wait_s%')]
        private int $waitS,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'round_status', description: 'Start here. The round being played in Draw It If You Can: whether you are the copilot of the player or their rival in a duel, who draws, the theme, the time left, the wrong answers so far, and what you can do now. Never the word to find while the round is played; the word once it is over. Answers at once; to wait for the game, call wait_for_your_turn.')]
    public function roundStatus(
        #[Schema(description: 'Id of a round; leave empty for the round being played')]
        ?string $round = null,
        ?RequestContext $context = null,
    ): array {
        [$game, $current] = $this->find($round, $context);

        return $this->status($game, $current);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(name: 'wait_for_your_turn', description: 'Wait for the game: answers as soon as there is something new for you (a round starts, the round ends, new strokes on the drawing), or after 25 s with nothing new. Then it gives the same status as round_status. Call it whenever you have nothing to do, again and again, until it answers "stop": true; never stop before.')]
    public function waitForYourTurn(?RequestContext $context = null): array
    {
        // An agent cannot wait by itself between two calls: the game waits for it. See
        // docs/adr/0001-agents-wait-on-the-server.md.
        [$game, $current] = $this->find(null, $context);
        $turn = AgentTurn::from($current);
        $deadline = microtime(true) + $this->waitS;
        while (true) {
            [$stop] = $this->advice($game, $current);
            $strokes = null === $current ? 0 : \count($this->strokes->findByRound($current));
            $news = $stop ? 'stop' : $turn->news($game, $current, $strokes);
            if (null !== $news || microtime(true) >= $deadline) {
                break;
            }
            usleep(self::WAIT_PAUSE_MS * 1000);
            // The player plays in other requests.
            $this->entityManager->clear();
            [$game, $current] = $this->find(null, $context);
        }

        return ['news' => $news ?? 'nothing new yet'] + $this->status($game, $current);
    }

    /**
     * @return array<string, mixed>
     */
    private function status(Game $game, ?Round $current): array
    {
        [$stop, $advice] = $this->advice($game, $current);
        if (null === $current) {
            return [
                'game' => $game->getId()->toRfc4122(),
                'you_are' => $this->role($game),
                'your_nickname' => $game->getAgentNickname(),
                'state' => $game->hasLeft() ? 'the player left' : 'waiting for the player to start the game',
                'theme' => $game->getTheme()->description(),
                'language' => $game->getLanguage()->nameInEnglish(),
                'stop' => $stop,
                'what_you_can_do' => $advice,
            ];
        }

        // The agent now knows of these strokes: a wait answers only for more.
        $strokes = \count($this->strokes->findByRound($current));
        $current->agentTold($strokes);
        $this->entityManager->flush();

        $status = [
            'you_are' => $this->role($game),
            'your_nickname' => $game->getAgentNickname(),
            'round' => $current->getId()->toRfc4122(),
            'number' => $current->getNumber(),
            'of' => $game->getRoundCount(),
            'drawn_by' => $current->getDrawnBy()->value,
            'theme' => $game->getTheme()->description(),
            'language' => $game->getLanguage()->nameInEnglish(),
            'time_left_s' => intdiv($current->timeLeftMs(), 1000),
            'state' => match (true) {
                $game->hasLeft() => 'the player left',
                $current->isFound() => 'found',
                $current->isGivenUp() => 'given up',
                $current->isOver() => 'time is up',
                default => 'playing',
            },
            'strokes' => $strokes,
            'strokes_you_saw' => $current->getStrokesSeenByAgent(),
        ];
        // Once the round is over, nothing counts any more: the agent may know what it missed.
        if ($current->isOver()) {
            $status['the_word_was'] = $current->getTargetWord();
        }
        if ($game->isDuel()) {
            $rounds = $this->rounds->findByGame($game);
            $status += [
                'found_by' => $current->getFoundBy()?->value,
                'wrong_answers_of_the_others' => $this->wrongAnswers($current, [Author::Player, Author::Ai]),
                'your_answers' => $this->wrongAnswers($current, [Author::Agent]),
                'score' => ['you' => $this->gauge->rivalTotal($rounds), 'the_player' => $this->gauge->total($rounds)],
            ];
        } else {
            $status += [
                'wrong_answers' => $current->isDrawnByAi() ? $this->wrongAnswers($current, [Author::Player]) : [],
                'your_hints' => array_map(static fn (Hint $hint): string => $hint->getWord(), $this->hints->findByRound($current)),
                'hints_left' => max(0, Copilot::MAX_HINTS - \count($this->hints->findByRound($current))),
            ];
        }

        return $status + ['stop' => $stop, 'what_you_can_do' => $advice];
    }

    /**
     * @return list<TextContent|ImageContent>
     */
    #[McpTool(name: 'look_at_drawing', description: 'The drawing of the round as it is now, as a PNG image, black strokes on white. Strokes may still be coming: wait_for_your_turn tells you when there are new ones.')]
    public function lookAtDrawing(
        #[Schema(description: 'Id of a round; leave empty for the round being played')]
        ?string $round = null,
        ?RequestContext $context = null,
    ): array {
        $current = $this->findRound($round, $context);
        $strokes = $this->strokes->findByRound($current);
        if ([] === $strokes) {
            return [new TextContent('The canvas is still empty: nothing drawn yet. Call wait_for_your_turn: it answers when strokes come.')];
        }

        $current->agentLooked(\count($strokes));
        $this->entityManager->flush();
        $png = $this->renderer->toPng(array_map(static fn (Stroke $stroke): array => $stroke->getPoints(), $strokes));

        return [
            new TextContent(\sprintf('Round %d, %d strokes, %d s left.', $current->getNumber(), \count($strokes), intdiv($current->timeLeftMs(), 1000))),
            new ImageContent(base64_encode($png), 'image/png'),
        ];
    }

    #[McpTool(name: 'suggest_word', description: 'For a copilot only. Whisper a word to the player who guesses the drawing of the AI: it appears on screen, and only the player can answer it. At most 5 per round, in the language of the game.')]
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
        } catch (AgentRefusal $refusal) {
            throw new ToolCallException($refusal->getMessage(), previous: $refusal);
        }

        return \sprintf('"%s" is shown to the player, who decides. %d hints left in this round.', $hint->getWord(), Copilot::MAX_HINTS - \count($this->hints->findByRound($current)));
    }

    #[McpTool(name: 'choose_nickname', description: 'Choose the name the player sees for you: invent a funny nickname of your own, in the language of the game, 24 characters at most. Best done in the lobby, before the game starts.')]
    public function chooseNickname(
        #[Schema(description: 'Your nickname, 24 characters at most')]
        string $nickname,
        ?RequestContext $context = null,
    ): string {
        [$game] = $this->find(null, $context);
        if (!$game->isWithAgent()) {
            throw new ToolCallException('The player plays this game alone, without an agent: stop playing.');
        }
        try {
            $game->nameAgent($nickname);
        } catch (\InvalidArgumentException $invalid) {
            throw new ToolCallException($invalid->getMessage(), previous: $invalid);
        }
        $this->entityManager->flush();

        return \sprintf('The player now sees you as "%s".', $game->getAgentNickname());
    }

    #[McpTool(name: 'submit_answer', description: 'For a rival in a duel only. Answer the drawing of the round, whoever draws it: the first side to find the word wins its points. Only the exact word counts, in the language of the game. One answer every 3 seconds at most.')]
    public function submitAnswer(
        #[Schema(description: 'The word you think is drawn, in the language of the game, e.g. "girafe" in French')]
        string $word,
        #[Schema(description: 'Id of a round; leave empty for the round being played')]
        ?string $round = null,
        ?RequestContext $context = null,
    ): string {
        $current = $this->findRound($round, $context);
        try {
            $answer = $this->duel->answer($current, $word);
        } catch (AgentRefusal $refusal) {
            throw new ToolCallException($refusal->getMessage(), previous: $refusal);
        }

        return $current->isFound() && $answer->isAccepted()
            ? \sprintf('Right! "%s" was the word: you win %d points. Call wait_for_your_turn for the next round.', $current->getTargetWord(), $this->gauge->pointsOf($current))
            : \sprintf('"%s" is wrong. Look at the drawing again, and try another word in %d s.', $answer->getWord(), intdiv(Duel::ANSWER_GAP_MS, 1000));
    }

    /**
     * The game and round asked for, by default the last ones; the agent is marked as seen on the game.
     *
     * @return array{Game, ?Round} No round yet while the game waits in its lobby
     */
    private function find(?string $round, ?RequestContext $context): array
    {
        if (null === $round || '' === trim($round)) {
            $game = $this->games->findLatest() ?? throw new ToolCallException('No game has started yet: the player starts one at http://127.0.0.1:8040, with a copilot or in a duel.');
            $found = $this->rounds->findLastOfGame($game);
        } elseif (!Uuid::isValid($round)) {
            throw new ToolCallException('A round id looks like 019a6e2c-0000-7000-8000-000000000000; leave it empty for the round being played.');
        } else {
            $found = $this->rounds->find(Uuid::fromString($round)) ?? throw new ToolCallException('No round has this id.');
            $game = $found->getGame();
        }

        $game->agentSeen(self::clientName($context), new DatePoint());
        $this->entityManager->flush();

        return [$game, $found];
    }

    /**
     * The name the MCP client gives itself, e.g. "claude-code": in the session opened by
     * "initialize", or with each request on the stateless protocol (2026-07-28), which has no session.
     */
    private static function clientName(?RequestContext $context): string
    {
        $session = $context?->getSession();
        $meta = $session?->get(RequestMeta::class);
        if ($meta instanceof RequestMeta && null !== $meta->clientInfo) {
            return $meta->clientInfo->name;
        }
        $client = $session?->get('client_info');

        return \is_array($client) && \is_string($client['name'] ?? null) ? $client['name'] : '';
    }

    /**
     * The round to look at or to play: only in a game played with an agent.
     */
    private function findRound(?string $round, ?RequestContext $context): Round
    {
        [$game, $found] = $this->find($round, $context);
        if (!$game->isWithAgent()) {
            throw new ToolCallException('The player plays this game alone, without an agent: stop playing.');
        }

        return $found ?? throw new ToolCallException('The game has not started: the player is in the lobby. Call wait_for_your_turn: it answers when the game starts.');
    }

    /**
     * @param list<Author> $authors
     *
     * @return list<string>
     */
    private function wrongAnswers(Round $round, array $authors): array
    {
        return array_values(array_map(
            static fn (Guess $guess): string => $guess->getWord(),
            array_filter($this->guesses->findByRound($round), static fn (Guess $guess): bool => !$guess->isAccepted() && \in_array($guess->getAuthor(), $authors, true)),
        ));
    }

    private function role(Game $game): string
    {
        return $game->isDuel()
            ? 'the rival of the player in a duel: you guess the same drawings, and the first side to find the word wins its points'
            : 'the copilot of the player: you whisper words, and the player decides';
    }

    /**
     * Whether the agent should stop, and what it can do.
     *
     * @return array{bool, string}
     */
    private function advice(Game $game, ?Round $round): array
    {
        [$stop, $advice] = $this->adviceForTheRound($game, $round);
        // The name arrives late if the agent chooses it after the start: the player saw its client.
        if (!$stop && null !== $round && null === $game->getAgentNickname()) {
            $advice = 'First choose a funny nickname with choose_nickname: the player sees your client name meanwhile. '.$advice;
        }

        return [$stop, $advice];
    }

    /**
     * @return array{bool, string}
     */
    private function adviceForTheRound(Game $game, ?Round $round): array
    {
        $idle = $round?->msSinceEnd();

        return match (true) {
            $game->hasLeft() => [true, 'The player left the game. Stop playing; the player will call you again for another game.'],
            !$game->isWithAgent() => [true, 'The player now plays alone, without an agent. Stop playing.'],
            null === $round => [false, null === $game->getAgentNickname()
                ? 'You are connected: the player sees it in the lobby. Choose a funny nickname with choose_nickname now, then call wait_for_your_turn: it answers when the game starts. Keep calling it; the player may take a minute.'
                : 'You are connected: the player sees it in the lobby. Call wait_for_your_turn: it answers when the game starts. Keep calling it; the player may take a minute.'],
            $round->isOver() && $round->isLast() => [true, 'The game is over. Stop playing.'],
            null !== $idle && $idle > self::IDLE_S * 1000 => [true, \sprintf('No new round for %d minutes: the player has probably stopped. Stop playing; the player will call you again.', intdiv(self::IDLE_S, 60))],
            $round->isOver() => [false, 'This round is over: call wait_for_your_turn, it answers when the player starts the next one. Keep calling it.'],
            $game->isDuel() => [false, \sprintf('%s: call look_at_drawing, then submit_answer with your best guess, one word in the language of the game. Wrong? Call wait_for_your_turn: it answers when the drawing grows. Then look again and answer again, every %d seconds at most. Play fair: see the game through these tools only.', $round->isDrawnByAi() ? 'The AI draws, the player and you guess' : 'The player draws, the AI of the player and you guess', intdiv(Duel::ANSWER_GAP_MS, 1000))],
            !$round->isDrawnByAi() => [false, 'The player draws and the AI guesses: nothing for you to do in this round. Call wait_for_your_turn: it answers when the next round starts. Keep calling it.'],
            default => [false, 'The AI draws and the player guesses: call look_at_drawing, then suggest_word with your best guess. Then call wait_for_your_turn: it answers when the drawing grows, and you look again.'],
        };
    }
}
