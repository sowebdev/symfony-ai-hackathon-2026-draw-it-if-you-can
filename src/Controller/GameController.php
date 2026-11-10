<?php

namespace App\Controller;

use App\Ai\WordPicker;
use App\Entity\Game;
use App\Entity\Round;
use App\Game\Language;
use App\Game\Theme;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class GameController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WordPicker $wordPicker,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->renderHome();
    }

    #[Route('/games', name: 'game_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] NewGame $payload): Response
    {
        $game = new Game($payload->theme, $payload->language);

        try {
            $word = $this->wordPicker->pick($game->getTheme(), $game->getLanguage());
        } catch (\Throwable $exception) {
            $this->logger->error('No word to draw: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

            return $this->renderHome('The AI could not pick a word. Is the model running?', Response::HTTP_SERVICE_UNAVAILABLE);
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
