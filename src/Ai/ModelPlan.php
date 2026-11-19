<?php

namespace App\Ai;

/**
 * What the game would call if the draft of the models screen were saved: the stored roles, with the
 * roles of the draft over them, and the keys: the one typed for a place, else the one stored for it.
 * Keys stay in memory here: the pages get views without them.
 */
final readonly class ModelPlan
{
    public function __construct(
        private ModelSettings $settings,
        private DraftStore $drafts,
    ) {
    }

    /**
     * The roles that have a model of their own, stored or chosen in the draft.
     *
     * @return array<string, RoleView> By role value
     */
    public function choices(?ModelDraft $draft): array
    {
        $choices = $this->settings->views();
        foreach ($draft->roles ?? [] as $value => $choice) {
            $role = Role::from($value);
            if (null === $choice) {
                unset($choices[$value]);
            } else {
                $choices[$value] = $choice->view($role);
            }
        }

        return $choices;
    }

    /**
     * The choice a role starts from: its own, else the one of its fallback.
     */
    public function current(Role $role, ?ModelDraft $draft): ?RoleView
    {
        $choices = $this->choices($draft);
        for ($current = $role; null !== $current; $current = $current->fallback()) {
            if (isset($choices[$current->value])) {
                return $choices[$current->value];
            }
        }

        return null;
    }

    /**
     * Whether the place needs no more questions: an account is stored for it, or a role of the draft is there.
     */
    public function knowsPlace(Provider $provider, ?string $address, ?ModelDraft $draft): bool
    {
        return null !== $this->settings->account($provider, $address) || ($draft?->knowsPlace($provider, $address) ?? false);
    }

    /**
     * Whether the provider has a key, stored or typed.
     */
    public function hasKeyFor(Provider $provider, ?ModelDraft $draft): bool
    {
        if ($this->settings->hasKeyFor($provider)) {
            return true;
        }
        foreach ([$draft?->edit, ...array_values($draft->roles ?? [])] as $choice) {
            if (null !== $choice?->sealedKey && $choice->provider === $provider) {
                return true;
            }
        }

        return false;
    }

    /**
     * The key for a place: the one typed in the draft, else the one stored.
     *
     * @throws EncryptionException when a sealed key cannot be opened
     */
    public function key(Provider $provider, ?string $address, ?ModelDraft $draft): ?string
    {
        $typed = $this->drafts->open($draft?->sealedKeyAt($provider, $address));

        return $typed ?? $this->settings->savedKeyFor($provider, $address);
    }

    /**
     * What a role calls, with its key.
     *
     * @throws NoModelException    when the role has no model, nor does its fallback
     * @throws EncryptionException when a sealed key cannot be opened
     */
    public function setup(Role $role, ?ModelDraft $draft): RoleSetup
    {
        $view = $this->current($role, $draft) ?? throw new NoModelException();

        return new RoleSetup($view->provider, $view->address, $this->key($view->provider, $view->address, $draft), $view->model, $view->reasoningOff);
    }

    /**
     * What the draft changes, to save: the key is the one typed, where there is one.
     *
     * @return array<string, ?RoleSetup> By role value; null: the role follows its fallback
     *
     * @throws EncryptionException when a sealed key cannot be opened
     */
    public function changes(ModelDraft $draft): array
    {
        $changes = [];
        foreach ($draft->roles as $value => $choice) {
            $changes[$value] = null === $choice ? null : new RoleSetup($choice->provider, $choice->address, $this->drafts->open($draft->sealedKeyAt($choice->provider, $choice->address)), $choice->model, $choice->reasoningOff);
        }

        return $changes;
    }
}
