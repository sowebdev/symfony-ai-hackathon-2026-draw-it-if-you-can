<?php

namespace App\Controller;

use App\Ai\DraftStore;
use App\Ai\ModelHealth;
use App\Ai\Models;
use App\Ai\ModelSettings;
use App\Entity\Game;
use App\Game\Dealer;
use App\Game\DrawnBy;
use App\Game\GameSettings;
use App\Game\Language;
use App\Game\RoundStarter;
use App\Game\ScoreGauge;
use App\Game\Theme;
use App\Game\Turns;
use App\Repository\RoundRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Translation\LocaleSwitcher;

final class GameController extends AbstractController
{
    public function __construct(
        private readonly RoundStarter $starter,
        private readonly EntityManagerInterface $entityManager,
        private readonly RoundRepository $rounds,
        private readonly ModelSettings $settings,
        private readonly ScoreGauge $gauge,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly LoggerInterface $logger,
        private readonly GameSettings $gameSettings,
    ) {
    }

    /**
     * The language of the browser, if the game speaks it.
     */
    #[Route('/', name: 'root', methods: ['GET'])]
    public function root(Request $request): Response
    {
        $languages = array_map(static fn (Language $language): string => $language->value, Language::cases());

        return $this->redirectToRoute('home', ['_locale' => $request->getPreferredLanguage($languages) ?? Language::English->value]);
    }

    #[Route('/{_locale}', name: 'home', requirements: ['_locale' => new EnumRequirement(Language::class)], methods: ['GET'])]
    public function home(Request $request, DraftStore $drafts): Response
    {
        // Back on the home page, the model screen is abandoned: its draft, with the sealed key, is dropped.
        if ($request->hasPreviousSession()) {
            $drafts->clear();
        }

        return $this->renderHome();
    }

    /**
     * Whether each role can play, for the home page: the checks of make check, kept a few minutes.
     */
    #[Route('/{_locale}/health', name: 'health', requirements: ['_locale' => new EnumRequirement(Language::class)], methods: ['GET'])]
    public function health(Request $request, ModelHealth $health): JsonResponse
    {
        $report = ModelHealth::lines($health->report($request->query->getBoolean('fresh')));

        return $this->json(['ok' => [] !== $report && [] === array_filter($report, static fn (array $role): bool => !$role['ok']), 'roles' => $report]);
    }

    /**
     * The game is played in the language of the page: the interface, the words and the guesses.
     */
    #[Route('/{_locale}/games', name: 'game_create', requirements: ['_locale' => new EnumRequirement(Language::class)], methods: ['POST'])]
    public function create(Request $request, Dealer $dealer, #[MapRequestPayload] NewGame $payload = new NewGame()): Response
    {
        // No model is chosen yet: nobody could draw or guess, so the player goes and chooses one.
        if (!$this->settings->isStored()) {
            return $this->redirectToRoute('models');
        }
        // The game keeps the settings it starts with: changing them later changes nothing in it.
        $settings = $this->gameSettings->get();
        $first = match ($settings->turns) {
            Turns::PlayerDraws => DrawnBy::Player,
            Turns::AiDraws => DrawnBy::Ai,
            Turns::Alternate => $payload->drawnBy ?? $dealer->firstDrawer(),
        };
        $game = new Game(Language::from($request->getLocale()), $settings->rounds, $first, $payload->mode, $settings->turns, $settings->roundSeconds, $settings->themes, $settings->easyMode);
        $this->starter->open($game);

        return $this->redirectToRoute($game->isWithAgent() ? 'game_lobby' : 'game_show', ['id' => $game->getId()]);
    }

    /**
     * "Next round": the other one draws. After the last round, the final score.
     */
    #[Route('/games/{id}/rounds', name: 'game_next_round', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function nextRound(Game $game): Response
    {
        $this->speak($game);
        try {
            $round = $this->starter->startNext($game);
        } catch (\Throwable $exception) {
            return $this->noWord($exception, fn (string $error, int $status): Response => $this->render('game/no_word.html.twig', ['game' => $game, 'number' => ($this->rounds->findLastOfGame($game)?->getNumber() ?? 0) + 1, 'error' => $error], new Response(status: $status)));
        }

        return null === $round
            ? $this->redirectToRoute('game_show', ['id' => $game->getId()])
            : $this->redirectToRoute('round_show', ['id' => $round->getId()]);
    }

    /**
     * The final score, once the last round is over; until then, the round being played.
     */
    #[Route('/games/{id}', name: 'game_show', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(Game $game): Response
    {
        $this->opened($game);
        $rounds = $this->rounds->findByGame($game);
        if ([] === $rounds && $game->isWithAgent()) {
            return $this->redirectToRoute('game_lobby', ['id' => $game->getId()]);
        }
        if ([] === $rounds) {
            // The card of the first theme turns over while the AI picks the word.
            $this->speak($game);

            return $this->render('game/deal.html.twig', ['game' => $game, 'dealing' => null !== $game->getNextTheme()]);
        }
        $last = $rounds[array_key_last($rounds)];
        if (!$last->isLast() || !$last->isOver()) {
            return $this->redirectToRoute('round_show', ['id' => $last->getId()]);
        }
        $this->speak($game);

        return $this->render('game/show.html.twig', [
            'game' => $game,
            'rounds' => array_map(fn ($round): array => ['round' => $round, 'points' => $this->gauge->pointsOf($round)], $rounds),
            'total' => $this->gauge->total($rounds),
            'rival_total' => $this->gauge->rivalTotal($rounds),
        ]);
    }

    /**
     * Before a game with a copilot or in a duel: how to connect the agent, and whether it is there.
     */
    #[Route('/games/{id}/lobby', name: 'game_lobby', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function lobby(Game $game): Response
    {
        $this->opened($game);
        $last = $this->rounds->findLastOfGame($game);
        if (null !== $last) {
            return $this->redirectToRoute('round_show', ['id' => $last->getId()]);
        }
        $this->speak($game);

        return $this->render('game/lobby.html.twig', ['game' => $game]);
    }

    /**
     * The player leaves the game for the home page: the agent is told to stop.
     */
    #[Route('/games/{id}/leave', name: 'game_leave', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function leave(Game $game): Response
    {
        $game->leave(new DatePoint());
        $this->entityManager->flush();

        return $this->redirectToRoute('home', ['_locale' => $game->getLanguage()->value]);
    }

    /**
     * Whether the agent, copilot or rival, has called the game yet, and its name: for the lobby.
     */
    #[Route('/games/{id}/agent', name: 'game_agent', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function agent(Game $game): JsonResponse
    {
        return $this->json(['connected' => $game->isAgentConnected(), 'name' => $game->getAgentDisplayName()]);
    }

    /**
     * A page of the game is loaded: the agent now follows this game.
     */
    private function opened(Game $game): void
    {
        $game->opened(new DatePoint());
        $this->entityManager->flush();
    }

    /**
     * A game speaks its own language, whatever the browser says.
     */
    private function speak(Game $game): void
    {
        $this->localeSwitcher->setLocale($game->getLanguage()->value);
    }

    /**
     * @param \Closure(string, int): Response $render
     */
    private function noWord(\Throwable $exception, \Closure $render): Response
    {
        $this->logger->error('No word to draw: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

        // A model too slow is not a model that gave nothing: the player is told to wait and try again.
        return Models::isTooSlow($exception)
            ? $render('error.model_timeout', Response::HTTP_GATEWAY_TIMEOUT)
            : $render('error.no_word', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function renderHome(?string $error = null, int $status = Response::HTTP_OK): Response
    {
        return $this->render('game/home.html.twig', [
            'languages' => Language::cases(),
            'error' => $error,
            'has_model' => $this->settings->isStored(),
            'settings' => $this->gameSettings->get(),
            'theme_count' => \count(Theme::cases()),
        ], new Response(status: $status));
    }
}
