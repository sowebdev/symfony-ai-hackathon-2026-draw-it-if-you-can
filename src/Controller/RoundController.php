<?php

namespace App\Controller;

use App\Drawing\InvalidStroke;
use App\Drawing\StrokePoints;
use App\Entity\Guess;
use App\Entity\Round;
use App\Entity\Stroke;
use App\Game\DrawingLoop;
use App\Game\GuessLoop;
use App\Game\ScoreGauge;
use App\Game\WordMatcher;
use App\Repository\GuessRepository;
use App\Repository\RoundRepository;
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
use Symfony\Component\Translation\LocaleSwitcher;

#[Route('/rounds/{id}', requirements: ['id' => Requirement::UUID_V7])]
final class RoundController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StrokeRepository $strokes,
        private readonly GuessRepository $guesses,
        private readonly RoundRepository $rounds,
        private readonly ScoreGauge $gauge,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    #[Route('', name: 'round_show', methods: ['GET'])]
    public function show(Round $round): Response
    {
        // A round speaks the language of its game, whatever the browser says.
        $this->localeSwitcher->setLocale($round->getGame()->getLanguage()->value);

        return $this->render($round->isDrawnByAi() ? 'round/guess.html.twig' : 'round/show.html.twig', [
            'round' => $round,
            'guesses' => $this->guesses->findByRound($round),
            'gauge' => $this->gauge->steps($round->getDurationMs()),
            'points' => $this->gauge->pointsOf($round),
            'team_score' => $this->gauge->total($this->rounds->findByGame($round->getGame())),
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
        $this->denyUnlessPlayerDraws($round);
        if ($round->isOver()) {
            return $this->roundIsOver();
        }
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
        $this->denyUnlessPlayerDraws($round);
        if ($round->isOver()) {
            return $this->roundIsOver();
        }
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
        $this->denyUnlessPlayerDraws($round);
        return new EventStreamResponse(static fn (): \Generator => $loop->run($round));
    }

    /**
     * "Yes!": the player accepts a near word as the right one.
     */
    #[Route('/guesses/{guessId}/accept', name: 'round_guess_accept', requirements: ['guessId' => Requirement::DIGITS], methods: ['POST'])]
    public function accept(Round $round, #[MapEntity(id: 'guessId')] Guess $guess): JsonResponse
    {
        $this->denyUnlessPlayerDraws($round);
        if ($guess->getRound() !== $round) {
            throw $this->createNotFoundException('This guess belongs to another round.');
        }
        if ($round->isOver()) {
            return $this->roundIsOver();
        }
        // The points count from the click: that is when the answer is validated.
        $round->markFound($guess, $round->elapsedMs());
        $this->entityManager->flush();

        return $this->json(['foundAfterMs' => $round->getFoundAfterMs()]);
    }

    /**
     * The strokes of the AI, pushed to the browser as server-sent events while the player guesses.
     */
    #[Route('/drawing', name: 'round_drawing', methods: ['GET'])]
    public function drawing(Round $round, DrawingLoop $loop): EventStreamResponse
    {
        $this->denyUnlessAiDraws($round);

        return new EventStreamResponse(static fn (): \Generator => $loop->run($round));
    }

    /**
     * A word the player types to guess the drawing of the AI, judged by code.
     */
    #[Route('/answers', name: 'round_answer', methods: ['POST'])]
    public function answer(Round $round, #[MapRequestPayload] NewAnswer $payload, WordMatcher $matcher): JsonResponse
    {
        $this->denyUnlessAiDraws($round);
        if ($round->isOver()) {
            return $this->roundIsOver();
        }

        $guess = new Guess($round, trim($payload->word), $round->elapsedMs());
        $this->entityManager->persist($guess);
        if ($matcher->matches($guess->getWord(), $round->getTargetWord())) {
            $round->markFound($guess);
        }
        $this->entityManager->flush();

        return $this->json([
            'word' => $guess->getWord(),
            'found' => $round->isFound(),
            'foundAfterMs' => $round->getFoundAfterMs(),
            'targetWord' => $round->isFound() ? $round->getTargetWord() : null,
        ], Response::HTTP_CREATED);
    }

    /**
     * The player gives up: the word is revealed.
     */
    #[Route('/give-up', name: 'round_give_up', methods: ['POST'])]
    public function giveUp(Round $round): JsonResponse
    {
        $this->denyUnlessAiDraws($round);
        $round->giveUp();
        $this->entityManager->flush();

        return $this->json(['targetWord' => $round->getTargetWord(), 'found' => $round->isFound()]);
    }

    /**
     * Found, given up or out of time: the player is told to look at the result.
     */
    private function roundIsOver(): JsonResponse
    {
        return $this->json(['error' => 'The round is over.'], Response::HTTP_CONFLICT);
    }

    private function denyUnlessPlayerDraws(Round $round): void
    {
        if ($round->isDrawnByAi()) {
            throw $this->createNotFoundException('The AI draws in this round.');
        }
    }

    private function denyUnlessAiDraws(Round $round): void
    {
        if (!$round->isDrawnByAi()) {
            throw $this->createNotFoundException('The player draws in this round.');
        }
    }
}
