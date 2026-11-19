<?php

namespace App\Controller;

use App\Ai\DraftStore;
use App\Ai\EncryptionException;
use App\Ai\ModelCheck;
use App\Ai\ModelChoice;
use App\Ai\ModelDraft;
use App\Ai\ModelHealth;
use App\Ai\ModelLister;
use App\Ai\Models;
use App\Ai\ModelSettings;
use App\Ai\Problem;
use App\Ai\Provider;
use App\Ai\Role;
use App\Form\ModelsStep;
use App\Form\ModelsStepType;
use App\Form\ProviderStep;
use App\Form\ProviderStepType;
use App\Game\Language;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The screen where the player chooses the model: provider and key, then the models, then the check.
 * Server-rendered, no JavaScript: each step is a round trip. The API key lives in the session,
 * sealed, between the steps, and is never sent back to the browser.
 *
 * It has no login: the game listens on 127.0.0.1 only and must not be exposed.
 */
#[Route('/{_locale}/models', requirements: ['_locale' => new EnumRequirement(Language::class)])]
final class ModelsController extends AbstractController
{
    /** Past this many models a role's list is folded away: the text field comes first, on a phone too. */
    public const int LONG_LIST = 50;

    /** An answer that takes longer is said to be slow: the game needs short answers every few seconds. */
    private const int SLOW_S = 20;

    public function __construct(
        private readonly ModelSettings $settings,
        private readonly DraftStore $drafts,
        private readonly ModelLister $lister,
        private readonly ModelCheck $modelCheck,
        private readonly Models $models,
        private readonly ModelHealth $health,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Step 1: the provider, its key, its address. Coming back from step 2 keeps the draft; any other
     * way in starts again, and drops the key of an earlier draft.
     */
    #[Route('', name: 'models_provider', methods: ['GET', 'POST'])]
    public function provider(Request $request): Response
    {
        $view = $this->settings->view();
        if ($request->isMethod('GET') && !$request->query->getBoolean('back')) {
            $this->drafts->clear();
        }
        $draft = $this->drafts->get();

        // The draft, else what is saved, else the first card; the address is empty unless one of them has it.
        $provider = Provider::Anthropic;
        $address = null;
        if (null !== $draft) {
            $provider = $draft->provider;
            $address = $draft->address;
        } elseif (null !== $view) {
            $provider = $view->provider;
            $address = $view->address;
        }
        $step = new ProviderStep($provider, null, $address);
        $form = $this->createForm(ProviderStepType::class, $step);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $step->provider) {
            $address = $step->provider->hasAddress() ? trim((string) $step->address) : null;
            try {
                $key = trim((string) $step->apiKey);
                $key = '' !== $key ? $key : $this->settings->savedKeyFor($step->provider, $address);
                if (null === $key && $step->provider->needsKey()) {
                    $form->get('apiKey')->addError(new FormError($this->translator->trans('error.key_needed', ['%provider%' => $this->translator->trans('provider.'.$step->provider->value)])));
                } else {
                    $this->drafts->put($this->nextDraft($step->provider, $address, null === $key ? null : $this->drafts->seal($key), $draft));

                    return $this->redirectToRoute('models_choose');
                }
            } catch (EncryptionException $exception) {
                $form->get('apiKey')->addError(new FormError($this->translator->trans('error.encryption', ['%detail%' => $exception->getMessage()])));
            }
        }

        return $this->render('models/provider.html.twig', [
            'form' => $form,
            'saved' => $view,
            'providers' => Provider::cases(),
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Step 2: a model for each role, from the list of the provider or typed.
     */
    #[Route('/choose', name: 'models_choose', methods: ['GET', 'POST'])]
    public function choose(Request $request): Response
    {
        $draft = $this->drafts->get();
        if (null === $draft) {
            return $this->redirectToRoute('models_provider');
        }
        try {
            $key = $this->drafts->open($draft);
        } catch (EncryptionException) {
            $this->drafts->clear();

            return $this->redirectToRoute('models_provider');
        }

        $list = $this->lister->list($draft->provider, $draft->address, $key, fresh: $request->query->getBoolean('fresh'));
        $choices = [];
        foreach (Role::cases() as $role) {
            foreach ($list->for($role) as $choice) {
                $choices[$role->value][$this->label($choice)] = $choice->name;
            }
        }
        $step = new ModelsStep(reasoningOff: $draft->reasoningOff);
        foreach ([Role::Guesser, Role::Drawer] as $role) {
            $current = Role::Guesser === $role ? $draft->guesserModel : $draft->drawerModel;
            $listed = \in_array($current, $choices[$role->value] ?? [], true);
            if (Role::Guesser === $role) {
                [$step->guesserChoice, $step->guesserName] = [$listed ? $current : null, $listed || '' === $current ? null : $current];
            } else {
                [$step->drawerChoice, $step->drawerName] = [$listed ? $current : null, $listed || '' === $current ? null : $current];
            }
        }
        $form = $this->createForm(ModelsStepType::class, $step, [
            'guesser_choices' => $choices[Role::Guesser->value] ?? [],
            'drawer_choices' => $choices[Role::Drawer->value] ?? [],
            'reasoning_switch' => $draft->provider->hasReasoningSwitch(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->drafts->put($draft->withModels($step->guesser(), $step->drawer(), $draft->provider->hasReasoningSwitch() && $step->reasoningOff));

            return $this->redirectToRoute('models_check');
        }

        return $this->render('models/choose.html.twig', [
            'form' => $form,
            'draft' => $draft,
            'problem' => $list->problem?->trans($this->translator),
            'counts' => [Role::Guesser->value => \count($choices[Role::Guesser->value] ?? []), Role::Drawer->value => \count($choices[Role::Drawer->value] ?? [])],
            'capabilities_known' => $list->hasCapabilities(),
            'long_list' => self::LONG_LIST,
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Step 3: tries the model of each role, on the setting that is not saved yet. A check changes nothing.
     */
    #[Route('/check', name: 'models_check', methods: ['GET'])]
    public function check(): Response
    {
        $draft = $this->drafts->get();
        if (null === $draft || !$draft->hasModels()) {
            return $this->redirectToRoute(null === $draft ? 'models_provider' : 'models_choose');
        }
        try {
            $key = $this->drafts->open($draft);
        } catch (EncryptionException) {
            $this->drafts->clear();

            return $this->redirectToRoute('models_provider');
        }

        // A model that loads into memory takes tens of seconds on its first call.
        set_time_limit(300);
        [$drawer, $guesser] = $draft->setups($key);
        $models = $this->models->with($drawer, $guesser);
        $check = $this->modelCheck->with($models);
        $results = [];
        foreach ([Role::Guesser, Role::Drawer] as $role) {
            $setup = $models->setup($role);
            $started = microtime(true);
            try {
                $problem = $check->check($role, null);
            } catch (\Throwable $exception) {
                $problem = ModelHealth::explain($exception, $setup->provider, $setup->reasoningOff);
            }
            $seconds = (int) round(microtime(true) - $started);
            $results[] = [
                'role' => $role->value,
                'model' => $setup->model,
                'ok' => null === $problem,
                'problem' => $problem?->trans($this->translator),
                'seconds' => $seconds,
                'slow' => null === $problem && $seconds >= self::SLOW_S ? $this->slowNote($seconds, $setup->provider->hasReasoningSwitch(), $setup->reasoningOff) : null,
            ];
        }
        $passed = [] === array_filter($results, static fn (array $result): bool => !$result['ok']);
        if ($passed) {
            $this->drafts->put($draft->passedTheCheck());
        }

        return $this->render('models/check.html.twig', [
            'draft' => $draft,
            'results' => $results,
            'passed' => $passed,
            'save' => $passed ? $this->createFormBuilder(null, ['action' => $this->generateUrl('models_save')])->add('save', SubmitType::class, ['label' => 'action.save_and_play'])->getForm() : null,
        ]);
    }

    /**
     * Saves the setting of a draft that passed the check, forgets the draft and goes home. On a failure
     * nothing is saved and the choices stay filled.
     */
    #[Route('/save', name: 'models_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $draft = $this->drafts->get();
        if (null === $draft || !$draft->hasModels()) {
            return $this->redirectToRoute('models_provider');
        }
        $form = $this->createFormBuilder()->add('save', SubmitType::class)->getForm();
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid() || !$draft->isChecked()) {
            return $this->redirectToRoute('models_check');
        }

        try {
            $this->settings->save($draft->provider, $draft->address, $draft->drawerModel, $draft->guesserModel, $draft->reasoningOff, $this->drafts->open($draft) ?? '');
        } catch (EncryptionException $exception) {
            $this->addFlash('error', $this->translator->trans('error.encryption', ['%detail%' => $exception->getMessage()]));

            return $this->redirectToRoute('models_check');
        } catch (\InvalidArgumentException) {
            return $this->redirectToRoute('models_provider', ['back' => 1]);
        }
        $this->drafts->clear();
        // Step 3 has just checked these models: the home page does not check them again.
        $this->health->rememberHealthy();

        return $this->redirectToRoute('home');
    }

    private function nextDraft(Provider $provider, ?string $address, #[\SensitiveParameter] ?string $sealedKey, ?ModelDraft $previous): ModelDraft
    {
        // The models of the same provider at the same place, from the draft or the saved setting, stay filled in.
        $view = $this->settings->view();
        $same = static fn (?string $otherAddress, ?Provider $otherProvider): bool => $otherProvider === $provider && $provider->addressOr($otherAddress) === $provider->addressOr($address);
        if (null !== $previous && $same($previous->address, $previous->provider)) {
            return new ModelDraft($provider, $address, $sealedKey, $previous->guesserModel, $previous->drawerModel, $previous->reasoningOff);
        }
        if (null !== $view && $same($view->address, $view->provider)) {
            return new ModelDraft($provider, $address, $sealedKey, $view->guesserModel, $view->drawerModel, $view->reasoningOff);
        }

        return new ModelDraft($provider, $address, $sealedKey, reasoningOff: $provider->reasoningOffByDefault($address));
    }

    private function label(ModelChoice $choice): string
    {
        $says = array_filter([
            true === $choice->vision ? $this->translator->trans('models.reads_images') : null,
            true === $choice->tools ? $this->translator->trans('models.calls_tools') : null,
        ]);

        return [] === $says ? $choice->name : $choice->name.' — '.implode(', ', $says);
    }

    private function slowNote(int $seconds, bool $hasSwitch, bool $switchOff): string
    {
        return (new Problem('check.slow', ['%seconds%' => (string) $seconds], $hasSwitch && !$switchOff ? 'advice.reasoning_tick' : 'advice.reasoning_choose'))->trans($this->translator);
    }
}
