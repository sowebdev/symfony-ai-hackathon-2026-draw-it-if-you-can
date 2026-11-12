<?php

namespace App\Controller;

use App\Entity\Game;
use App\Game\Language;
use App\Game\RoundStarter;
use App\Game\ScoreGauge;
use App\Game\Theme;
use App\Repository\RoundRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\DatePoint;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        private readonly ScoreGauge $gauge,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly LoggerInterface $logger,
        #[Autowire('%game.rounds%')]
        private readonly int $roundCount,
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
    public function home(): Response
    {
        return $this->renderHome();
    }

    /**
     * The game is played in the language of the page: the interface, the words and the guesses.
     */
    #[Route('/{_locale}/games', name: 'game_create', requirements: ['_locale' => new EnumRequirement(Language::class)], methods: ['POST'])]
    public function create(Request $request, #[MapRequestPayload] NewGame $payload): Response
    {
        $game = new Game($payload->theme, Language::from($request->getLocale()), $this->roundCount, $payload->drawnBy, $payload->copilot);
        if ($game->isWithCopilot()) {
            $this->starter->openLobby($game);

            return $this->redirectToRoute('game_lobby', ['id' => $game->getId()]);
        }
        try {
            $round = $this->starter->startGame($game);
        } catch (\Throwable $exception) {
            return $this->noWord($exception, fn (string $error, int $status): Response => $this->renderHome($error, $status));
        }

        return $this->redirectToRoute('round_show', ['id' => $round->getId()]);
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
            return $this->noWord($exception, fn (string $error, int $status): Response => $this->render('game/no_word.html.twig', ['game' => $game, 'error' => $error], new Response(status: $status)));
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
        $rounds = $this->rounds->findByGame($game);
        if ([] === $rounds) {
            return $this->redirectToRoute('game_lobby', ['id' => $game->getId()]);
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
        ]);
    }

    /**
     * Before a game with a copilot: how to connect the agent, and whether it is there.
     */
    #[Route('/games/{id}/lobby', name: 'game_lobby', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function lobby(Game $game): Response
    {
        $last = $this->rounds->findLastOfGame($game);
        if (null !== $last) {
            return $this->redirectToRoute('round_show', ['id' => $last->getId()]);
        }
        $this->speak($game);

        return $this->render('game/lobby.html.twig', ['game' => $game]);
    }

    /**
     * The player leaves the game for the home page: the copilot is told to stop.
     */
    #[Route('/games/{id}/leave', name: 'game_leave', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function leave(Game $game): Response
    {
        $game->leave(new DatePoint());
        $this->entityManager->flush();

        return $this->redirectToRoute('home', ['_locale' => $game->getLanguage()->value]);
    }

    /**
     * Whether the copilot has called the game yet, for the lobby and the scoreboard.
     */
    #[Route('/games/{id}/copilot', name: 'game_copilot', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function copilot(Game $game): JsonResponse
    {
        return $this->json(['connected' => $game->isCopilotConnected(), 'name' => $game->getCopilotName()]);
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

        return $render('error.no_word', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function renderHome(?string $error = null, int $status = Response::HTTP_OK): Response
    {
        return $this->render('game/home.html.twig', [
            'themes' => Theme::cases(),
            'languages' => Language::cases(),
            'error' => $error,
        ], new Response(status: $status));
    }
}
