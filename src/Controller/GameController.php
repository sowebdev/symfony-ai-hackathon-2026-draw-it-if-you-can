<?php

namespace App\Controller;

use App\Ai\WordPicker;
use App\Entity\Game;
use App\Entity\Round;
use App\Game\Language;
use App\Game\Theme;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;

final class GameController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WordPicker $wordPicker,
        private readonly LoggerInterface $logger,
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
     * The game is played in the language of the page: the interface, the word and the guesses.
     */
    #[Route('/{_locale}/games', name: 'game_create', requirements: ['_locale' => new EnumRequirement(Language::class)], methods: ['POST'])]
    public function create(Request $request, #[MapRequestPayload] NewGame $payload): Response
    {
        $game = new Game($payload->theme, Language::from($request->getLocale()));

        try {
            $word = $this->wordPicker->pick($game->getTheme(), $game->getLanguage());
        } catch (\Throwable $exception) {
            $this->logger->error('No word to draw: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

            return $this->renderHome('error.no_word', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $round = new Round($game, $word);
        $this->entityManager->persist($game);
        $this->entityManager->persist($round);
        $this->entityManager->flush();

        return $this->redirectToRoute('round_show', ['id' => $round->getId()]);
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
