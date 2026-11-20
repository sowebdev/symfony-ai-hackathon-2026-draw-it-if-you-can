<?php

namespace App\Controller;

use App\Ai\DraftStore;
use App\Ai\EncryptionException;
use App\Ai\ModelCheck;
use App\Ai\ModelCheckResult;
use App\Ai\ModelChoice;
use App\Ai\ModelDraft;
use App\Ai\ModelHealth;
use App\Ai\ModelList;
use App\Ai\ModelLister;
use App\Ai\ModelPlan;
use App\Ai\Models;
use App\Ai\ModelsDev;
use App\Ai\ModelSettings;
use App\Ai\NoModelException;
use App\Ai\Problem;
use App\Ai\Provider;
use App\Ai\Role;
use App\Ai\RoleDraft;
use App\Form\AccessStep;
use App\Form\AccessStepType;
use App\Form\ModelStep;
use App\Form\ModelStepType;
use App\Form\ProviderChoice;
use App\Form\ProviderChoiceType;
use App\Game\Language;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The screen where the player chooses the AI of each role, one question at a time: which provider, what
 * the place is still missing (its key, its address), which model, then the check. Server-rendered, no
 * JavaScript: each "Continue" is a round trip, and what is answered so far lives in the session
 * (ModelDraft), the keys sealed and never sent back to the browser.
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
        private readonly ModelPlan $plan,
        private readonly DraftStore $drafts,
        private readonly ModelLister $lister,
        private readonly ModelCheck $modelCheck,
        private readonly Models $models,
        private readonly ModelHealth $health,
        private readonly TranslatorInterface $translator,
        private readonly FormFactoryInterface $forms,
        private readonly ModelsDev $modelsDev,
    ) {
    }

    /**
     * The summary: the AI of each role, and the keys kept. With no drawer yet, the first question.
     */
    #[Route('', name: 'models', methods: ['GET'])]
    public function summary(): Response
    {
        $draft = $this->drafts->get();
        if (!isset($this->plan->choices($draft)[Role::Drawer->value])) {
            return $this->redirectToRoute('models_provider', ['role' => Role::Drawer->value]);
        }

        return $this->renderSummary($draft);
    }

    /**
     * Question 1 of a role: which provider. A provider with a key stored is tagged.
     */
    #[Route('/{role}', name: 'models_provider', requirements: ['role' => new EnumRequirement(Role::class)], methods: ['GET', 'POST'])]
    public function provider(Request $request, Role $role): Response
    {
        $draft = $this->drafts->get();
        $current = $this->plan->current($role, $draft);
        $before = $draft?->editing === $role ? $draft->edit : null;
        $choice = new ProviderChoice($before->provider ?? $current->provider ?? Provider::Anthropic);
        $form = $this->createForm(ProviderChoiceType::class, $choice);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $choice->provider) {
            $provider = $choice->provider;
            $address = $provider->hasAddress() ? $this->suggestedAddress($provider, $before, $current?->provider === $provider ? $current->address : null) : null;
            // The key typed a moment ago for the same place is kept.
            $sealed = null !== $before && $before->isAt($provider, $address) ? $before->sealedKey : null;
            $draft = ($draft ?? new ModelDraft())->withEdit($role, new RoleDraft($provider, $address, $sealed));
            $this->drafts->put($draft);

            // A place already known needs no question: only a server of one's own has an address to give.
            return $this->redirectToRoute($provider->hasAddress() || !$this->plan->knowsPlace($provider, null, $draft) ? 'models_access' : 'models_model', ['role' => $role->value]);
        }

        return $this->render('models/provider.html.twig', [
            'form' => $form,
            'role' => $role,
            'others' => $this->others($role, $draft),
            'saved' => array_values(array_map(static fn (Provider $provider): string => $provider->value, array_filter(Provider::cases(), fn (Provider $provider): bool => $this->plan->hasKeyFor($provider, $draft)))),
            'same' => null !== $role->fallback() ? $this->sameForm()->createView() : null,
            'back' => isset($this->plan->choices($draft)[Role::Drawer->value]) ? $this->generateUrl('models') : $this->generateUrl('home'),
        ], $this->statusOf($form));
    }

    /**
     * Question 2 of a role: what the place is missing. Only a provider's key, or a server's address and, folded away, its key.
     */
    #[Route('/{role}/access', name: 'models_access', requirements: ['role' => new EnumRequirement(Role::class)], methods: ['GET', 'POST'])]
    public function access(Request $request, Role $role): Response
    {
        $draft = $this->drafts->get();
        if (null === $draft?->edit || $draft->editing !== $role) {
            return $this->redirectToRoute('models_provider', ['role' => $role->value]);
        }
        $edit = $draft->edit;
        $provider = $edit->provider;
        $step = new AccessStep($provider, null, $edit->address);
        $stored = $this->settings->account($provider, $edit->address);
        $form = $this->createForm(AccessStepType::class, $step, [
            'with_address' => $provider->hasAddress(),
            'key_label' => null !== $stored && $stored->hasApiKey ? 'models.change_key' : ($provider->needsKey() ? 'models.your_key' : 'models.api_key'),
            'key_label_parameters' => ['%provider%' => $this->translator->trans('provider.'.$provider->value)],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $address = $provider->hasAddress() ? rtrim(trim((string) $step->address), '/') : null;
            $typed = trim((string) $step->apiKey);
            if ('' === $typed && $provider->needsKey() && !$this->plan->knowsPlace($provider, $address, $draft)) {
                $form->get('apiKey')->addError(new FormError($this->translator->trans('error.key_needed', ['%provider%' => $this->translator->trans('provider.'.$provider->value)])));
            } else {
                try {
                    $sealed = '' !== $typed ? $this->drafts->seal($typed) : ($edit->isAt($provider, $address) ? $edit->sealedKey : null);
                    $this->drafts->put($draft->withEdit($role, new RoleDraft($provider, $address, $sealed, $edit->isAt($provider, $address) ? $edit->model : '', $edit->reasoningOff)));

                    return $this->redirectToRoute('models_model', ['role' => $role->value]);
                } catch (EncryptionException $exception) {
                    $form->get('apiKey')->addError(new FormError($this->translator->trans('error.encryption', ['%detail%' => $exception->getMessage()])));
                }
            }
        }

        return $this->render('models/access.html.twig', [
            'form' => $form,
            'role' => $role,
            'provider' => $provider,
            'stored' => $stored,
            'others' => $this->others($role, $draft),
        ], $this->statusOf($form));
    }

    /**
     * Question 3 of a role: its model, from the list of the provider or typed. "Check and play" goes on to the check.
     */
    #[Route('/{role}/model', name: 'models_model', requirements: ['role' => new EnumRequirement(Role::class)], methods: ['GET', 'POST'])]
    public function model(Request $request, Role $role): Response
    {
        $draft = $this->drafts->get();
        if (null === $draft?->edit || $draft->editing !== $role) {
            return $this->redirectToRoute('models_provider', ['role' => $role->value]);
        }
        $edit = $draft->edit;
        $provider = $edit->provider;
        try {
            $key = $this->plan->key($provider, $edit->address, $draft);
        } catch (EncryptionException) {
            $this->drafts->clear();

            return $this->redirectToRoute('models');
        }

        $this->modelsDev->ensure();
        $list = $this->lister->list($provider, $edit->address, $key, fresh: $request->query->getBoolean('fresh'));
        [$choices, $layout] = $this->layout($provider, $list, $role);

        // What the role has at this place stays; else the recommended one, and the switch the address suggests.
        $current = $this->plan->current($role, $draft);
        $kept = null !== $current && $current->isAt($provider, $edit->address) ? $current : null;
        $model = '' !== $edit->model ? $edit->model : ($kept->model ?? $layout['preselected'] ?? '');
        $reasoningOff = '' !== $edit->model ? $edit->reasoningOff : ($kept->reasoningOff ?? $provider->reasoningOffByDefault($edit->address));
        $step = new ModelStep(reasoningOff: $reasoningOff);
        [$step->choice, $step->name] = isset($choices[$model]) ? [$model, null] : [null, '' === $model ? null : $model];

        $another = Role::Drawer === $role && !isset($this->plan->choices($draft)[Role::Guesser->value]) ? 'models.other_guesser_q' : null;
        $form = $this->createForm(ModelStepType::class, $step, ['choices' => $choices, 'reasoning_switch' => $provider->hasReasoningSwitch(), 'another' => $another]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->drafts->put($draft->withRole($role, $edit->withModel($step->model(), $step->reasoningOff)));
            $next = $form->has('another') && $form->get('another') instanceof SubmitButton && $form->get('another')->isClicked();

            return $next ? $this->redirectToRoute('models_provider', ['role' => Role::Guesser->value]) : $this->redirectToRoute('models_check');
        }

        return $this->render('models/model.html.twig', [
            'form' => $form,
            'role' => $role,
            'provider' => $provider,
            'address' => $edit->address,
            'problem' => $list->problem?->trans($this->translator),
            'layout' => $layout,
            'capabilities_known' => $list->hasCapabilities(),
            'long_list' => self::LONG_LIST,
            'others' => $this->others($role, $draft),
            'follows' => $another ? Role::Drawer : null,
        ], $this->statusOf($form));
    }

    /**
     * "The same AI as the one that draws": the guesser has no model of its own again.
     */
    #[Route('/guesser/same', name: 'models_same', methods: ['POST'])]
    public function same(Request $request): Response
    {
        $form = $this->sameForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->drafts->put(($this->drafts->get() ?? new ModelDraft())->withRole(Role::Guesser, null));
        }

        return $this->redirectToRoute('models');
    }

    /**
     * The check, on the spot: tries each role on the setting that is not saved yet (a check changes nothing).
     * When all pass, the setting is saved and the player goes home; else the summary says which role failed and why.
     */
    #[Route('/check', name: 'models_check', methods: ['GET'])]
    public function check(): Response
    {
        $draft = $this->drafts->get() ?? new ModelDraft();
        try {
            $drawer = $this->plan->setup(Role::Drawer, $draft);
            $guesser = $this->plan->setup(Role::Guesser, $draft);
        } catch (NoModelException) {
            return $this->redirectToRoute('models_provider', ['role' => Role::Drawer->value]);
        } catch (EncryptionException $exception) {
            $this->drafts->clear();
            $this->addFlash('error', $this->translator->trans('error.encryption', ['%detail%' => $exception->getMessage()]));

            return $this->redirectToRoute('models');
        }

        // A model that loads into memory takes tens of seconds on its first call.
        set_time_limit(300);
        $models = $this->models->with($drawer, $guesser);
        $check = $this->modelCheck->with($models);
        $own = $this->plan->choices($draft);
        $results = [];
        $checked = $draft->checked;
        foreach (Role::cases() as $role) {
            // A role that follows another calls the same model: the check of the drawer reads images too.
            if (!isset($own[$role->value])) {
                continue;
            }
            $setup = $models->setup($role);
            // What passed a moment ago, and has not changed since, is not tried again.
            if (($checked[$role->value] ?? null) === $setup->fingerprint()) {
                $results[$role->value] = ['ok' => true, 'problem' => null, 'slow' => null];
                continue;
            }
            $started = microtime(true);
            try {
                $outcome = $check->run($role, null);
            } catch (\Throwable $exception) {
                $outcome = new ModelCheckResult(ModelHealth::explain($exception, $setup->provider, $setup->reasoningOff));
            }
            $problem = $outcome->problem;
            $seconds = (int) round(microtime(true) - $started);
            $results[$role->value] = [
                'ok' => null === $problem,
                'problem' => $problem?->trans($this->translator),
                'slow' => null === $problem && $seconds >= self::SLOW_S ? $this->slowNote($seconds, $setup->provider->hasReasoningSwitch(), $setup->reasoningOff) : null,
            ];
            if (null === $problem) {
                $checked[$role->value] = $setup->fingerprint();
            } else {
                unset($checked[$role->value]);
            }
        }

        if ([] === array_filter($results, static fn (array $result): bool => !$result['ok'])) {
            try {
                $this->settings->save($this->plan->changes($draft));
            } catch (EncryptionException $exception) {
                $this->addFlash('error', $this->translator->trans('error.encryption', ['%detail%' => $exception->getMessage()]));

                return $this->redirectToRoute('models');
            } catch (\InvalidArgumentException) {
                return $this->redirectToRoute('models');
            }
            $this->drafts->clear();
            // The models have just been checked: the home page does not check them again.
            $this->health->rememberHealthy();

            return $this->redirectToRoute('home');
        }
        $draft = $draft->withChecked($checked);
        $this->drafts->put($draft);

        return $this->renderSummary($draft, $results);
    }

    /**
     * Forgets a stored key, with its account. A key a role uses is refused.
     */
    #[Route('/keys/{id}/forget', name: 'models_forget', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function forget(Request $request, int $id): Response
    {
        $form = $this->forgetForm($id);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $forgotten = $this->settings->forget($id);
            $this->addFlash($forgotten ? 'notice' : 'error', $this->translator->trans($forgotten ? 'models.forgotten' : 'models.forget_refused'));
        }

        return $this->redirectToRoute('models');
    }

    /**
     * @param array<string, array{ok: bool, problem: ?string, slow: ?string}> $results By role value, when the check has run
     */
    private function renderSummary(?ModelDraft $draft, array $results = []): Response
    {
        $choices = $this->plan->choices($draft);
        $lines = [];
        foreach (Role::cases() as $role) {
            $lines[] = ['role' => $role, 'view' => $choices[$role->value] ?? null, 'follows' => $role->fallback(), 'result' => $results[$role->value] ?? null];
        }
        $accounts = $this->settings->accounts();

        return $this->render('models/summary.html.twig', [
            'lines' => $lines,
            'checked' => [] !== $results,
            'accounts' => $accounts,
            'forget' => array_combine(array_map(static fn ($account): int => $account->id, $accounts), array_map(fn ($account) => $this->forgetForm($account->id)->createView(), $accounts)),
        ]);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function sameForm(): FormInterface
    {
        return $this->createFormBuilder(null, ['action' => $this->generateUrl('models_same')])->add('same', SubmitType::class, ['label' => 'models.same_as_drawer'])->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function forgetForm(int $id): FormInterface
    {
        return $this->forms->createNamedBuilder('forget_'.$id, FormType::class, null, ['action' => $this->generateUrl('models_forget', ['id' => $id]), 'csrf_token_id' => 'submit'])
            ->add('forget', SubmitType::class, ['label' => 'action.forget'])
            ->getForm();
    }

    /**
     * The other roles that have a model of their own, to show on top as one line each: "change" leads to their questions.
     *
     * @return list<array{role: Role, view: \App\Ai\RoleView}>
     */
    private function others(Role $role, ?ModelDraft $draft): array
    {
        $others = [];
        foreach ($this->plan->choices($draft) as $value => $view) {
            if ($value !== $role->value) {
                $others[] = ['role' => $view->role, 'view' => $view];
            }
        }

        return $others;
    }

    /**
     * Where the address of a server of one's own starts: the one just typed, the one the role has, else the one stored.
     */
    private function suggestedAddress(Provider $provider, ?RoleDraft $before, ?string $currentAddress): ?string
    {
        if (null !== $before && $before->provider === $provider) {
            return $before->address;
        }
        if (null !== $currentAddress) {
            return $currentAddress;
        }
        foreach ($this->settings->accounts() as $account) {
            if ($account->provider === $provider) {
                return $account->address;
            }
        }

        return null;
    }

    /**
     * The models offered for a role, as the names the form accepts, and how the page arranges them.
     *
     * @return array{array<string, string>, array{count: int, cards: list<string>, others: list<string>, preselected: ?string, unknown: list<string>, long: bool}}
     */
    private function layout(Provider $provider, ModelList $list, Role $role): array
    {
        $names = static fn (array $models): array => array_values(array_map(static fn (ModelChoice $choice): string => $choice->name, $models));
        $listed = $list->for($role);
        $arranged = ModelsDev::arrange($provider, $listed);
        $long = \count($listed) > self::LONG_LIST;
        // The middle of the provider's range, from models.dev; without the catalog, nothing is preselected.
        $recommended = ModelsDev::recommended($provider, $names($listed));
        $cards = $names($arranged['cards']);
        $others = $names($arranged['others']);
        if (null !== $recommended && !\in_array($recommended, $cards, true)) {
            $cards[] = $recommended;
            $others = array_values(array_diff($others, [$recommended]));
        }

        return [
            array_combine($names($listed), $names($listed)),
            [
                'count' => \count($listed),
                'cards' => $long ? [] : $cards,
                'others' => $long ? $names($listed) : $others,
                'preselected' => $recommended,
                'unknown' => $list->hasCapabilities() ? $names(array_filter($listed, static fn (ModelChoice $choice): bool => !$choice->isKnown())) : [],
                'long' => $long,
            ],
        ];
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function statusOf(FormInterface $form): Response
    {
        return new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    private function slowNote(int $seconds, bool $hasSwitch, bool $switchOff): string
    {
        return (new Problem('check.slow', ['%seconds%' => (string) $seconds], $hasSwitch && !$switchOff ? 'advice.reasoning_tick' : 'advice.reasoning_choose'))->trans($this->translator);
    }
}
