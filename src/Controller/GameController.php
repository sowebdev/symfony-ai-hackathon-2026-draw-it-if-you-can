<?php

namespace App\Controller;

use App\Drawing\InvalidStroke;
use App\Drawing\StrokePoints;
use App\Entity\Game;
use App\Entity\Stroke;
use App\Repository\StrokeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class GameController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StrokeRepository $strokes,
    ) {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('game/home.html.twig');
    }

    #[Route('/games', name: 'game_create', methods: ['POST'])]
    public function create(): Response
    {
        $game = new Game();
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $this->redirectToRoute('game_show', ['id' => $game->getId()]);
    }

    #[Route('/games/{id}', name: 'game_show', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(Game $game): Response
    {
        return $this->render('game/show.html.twig', ['game' => $game]);
    }

    #[Route('/games/{id}/strokes', name: 'game_strokes', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function strokes(Game $game): JsonResponse
    {
        return $this->json(array_map(
            static fn (Stroke $stroke): array => ['position' => $stroke->getPosition(), 'points' => $stroke->getPoints()],
            $this->strokes->findByGame($game),
        ));
    }

    #[Route('/games/{id}/strokes', name: 'game_stroke_add', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function addStroke(Game $game, #[MapRequestPayload] NewStroke $payload): JsonResponse
    {
        try {
            $points = StrokePoints::fromArray($payload->points);
        } catch (InvalidStroke $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $stroke = new Stroke($game, $this->strokes->nextPosition($game), $points);
        $this->entityManager->persist($stroke);
        $this->entityManager->flush();

        return $this->json(['position' => $stroke->getPosition()], Response::HTTP_CREATED);
    }

    #[Route('/games/{id}/strokes/last', name: 'game_stroke_undo', requirements: ['id' => Requirement::UUID_V7], methods: ['DELETE'])]
    public function undo(Game $game): Response
    {
        $stroke = $this->strokes->findLast($game) ?? throw $this->createNotFoundException('No stroke to undo.');
        $this->entityManager->remove($stroke);
        $this->entityManager->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
