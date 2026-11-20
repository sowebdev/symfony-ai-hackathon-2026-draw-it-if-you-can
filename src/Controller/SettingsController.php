<?php

namespace App\Controller;

use App\Form\GameSettingsData;
use App\Form\GameSettingsType;
use App\Game\GameSettings;
use App\Game\Language;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;

/**
 * The screen where the player sets the game up, once: the themes, who draws, the number and the
 * length of the rounds. Server-rendered, no JavaScript; "Save" goes back to the home page.
 */
#[Route('/{_locale}/settings', name: 'settings', requirements: ['_locale' => new EnumRequirement(Language::class)], methods: ['GET', 'POST'])]
final class SettingsController extends AbstractController
{
    public function __construct(private readonly GameSettings $settings)
    {
    }

    public function __invoke(Request $request): Response
    {
        $data = GameSettingsData::from($this->settings->get());
        $form = $this->createForm(GameSettingsType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->settings->save($data->toSettings());

            return $this->redirectToRoute('home');
        }

        return $this->render('settings/form.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
