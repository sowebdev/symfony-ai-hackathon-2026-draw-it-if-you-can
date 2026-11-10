<?php

namespace App\Controller;

use App\Drawing\InvalidStroke;
use App\Drawing\StrokePoints;
use App\Entity\Guess;
use App\Entity\Round;
use App\Entity\Stroke;
use App\Game\GuessLoop;
use App\Repository\GuessRepository;
use App\Repository\StrokeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route('/rounds/{id}', requirements: ['id' => Requirement::UUID_V7])]
final class RoundController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StrokeRepository $strokes,
        private readonly GuessRepository $guesses,
    ) {
    }

    #[Route('', name: 'round_show', methods: ['GET'])]
    public function show(Round $round): Response
    {
        return $this->render('round/show.html.twig', [
            'round' => $round,
            'guesses' => $this->guesses->findByRound($round),
        ]);
    }

    #[Route('/strokes', name: 'round_strokes', methods: ['GET'])]
    public function strokes(Round $round): JsonResponse
    {
        return $this->json(array_map(
            static fn (Stroke $stroke): array => ['position' => $stroke->getPosition(), 'points' => $stroke->getPoints()],
            $this->strokes->findByRound($round),
        ));
    }

    #[Route('/strokes', name: 'round_stroke_add', methods: ['POST'])]
    public function addStroke(Round $round, #[MapRequestPayload] NewStroke $payload): JsonResponse
    {
        try {
            $points = StrokePoints::fromArray($payload->points);
        } catch (InvalidStroke $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $stroke = new Stroke($round, $this->strokes->nextPosition($round), $points);
        $this->entityManager->persist($stroke);
        $this->entityManager->flush();

        return $this->json(['position' => $stroke->getPosition()], Response::HTTP_CREATED);
    }

    #[Route('/strokes/last', name: 'round_stroke_undo', methods: ['DELETE'])]
    public function undo(Round $round): Response
    {
        $stroke = $this->strokes->findLast($round) ?? throw $this->createNotFoundException('No stroke to undo.');
        $this->entityManager->remove($stroke);
        $this->entityManager->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * The words of the AI, pushed to the browser as server-sent events while the player draws.
     */
    #[Route('/guesses', name: 'round_guesses', methods: ['GET'])]
    public function guesses(Round $round, GuessLoop $loop): EventStreamResponse
    {
        return new EventStreamResponse(static fn (): \Generator => $loop->run($round));
    }

    /**
     * "Yes!": the player accepts a near word as the right one.
     */
    #[Route('/guesses/{guessId}/accept', name: 'round_guess_accept', requirements: ['guessId' => Requirement::DIGITS], methods: ['POST'])]
    public function accept(Round $round, #[MapEntity(id: 'guessId')] Guess $guess): JsonResponse
    {
        if ($guess->getRound() !== $round) {
            throw $this->createNotFoundException('This guess belongs to another round.');
        }
        $round->markFound($guess);
        $this->entityManager->flush();

        return $this->json(['foundAfterMs' => $round->getFoundAfterMs()]);
    }
}
