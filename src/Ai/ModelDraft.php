<?php

namespace App\Ai;

/**
 * What the player has chosen so far on the models screen, kept in the session between the questions:
 * the roles changed and not saved yet, the role whose questions are being answered, and which roles
 * passed the check. API keys are in it only sealed (Encryption), and the draft is dropped once saved
 * or abandoned.
 */
final readonly class ModelDraft
{
    public function __construct(
        /** @var array<string, ?RoleDraft> The roles changed, by role value; null: the role follows its fallback again */
        public array $roles = [],
        /** The role whose questions are being answered... */
        public ?Role $editing = null,
        /** ...and its answers so far. */
        public ?RoleDraft $edit = null,
        /** @var array<string, string> The fingerprint of what passed the check, by role value */
        public array $checked = [],
    ) {
    }

    /**
     * The role is changed: its questions are over.
     */
    public function withRole(Role $role, ?RoleDraft $choice): self
    {
        return new self([...$this->roles, $role->value => $choice], null, null, $this->checked);
    }

    /**
     * The questions of a role begin, or go on with other answers.
     */
    public function withEdit(Role $role, RoleDraft $edit): self
    {
        return new self($this->roles, $role, $edit, $this->checked);
    }

    /**
     * @param array<string, string> $checked
     */
    public function withChecked(array $checked): self
    {
        return new self($this->roles, $this->editing, $this->edit, $checked);
    }

    /**
     * The key typed for a place, still sealed: in the answers so far, or in a role changed.
     */
    public function sealedKeyAt(Provider $provider, ?string $address): ?string
    {
        foreach ([$this->edit, ...array_values($this->roles)] as $choice) {
            if (null !== $choice?->sealedKey && $choice->isAt($provider, $address)) {
                return $choice->sealedKey;
            }
        }

        return null;
    }

    /**
     * Whether a role changed here is at the place: its questions about the place are answered.
     */
    public function knowsPlace(Provider $provider, ?string $address): bool
    {
        foreach ($this->roles as $choice) {
            if (null !== $choice && $choice->isAt($provider, $address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'roles' => array_map(static fn (?RoleDraft $choice): ?array => $choice?->toArray(), $this->roles),
            'editing' => $this->editing?->value,
            'edit' => $this->edit?->toArray(),
            'checked' => $this->checked,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $roles = [];
        foreach (\is_array($data['roles'] ?? null) ? $data['roles'] : [] as $value => $choice) {
            $role = \is_string($value) ? Role::tryFrom($value) : null;
            if (null !== $role && (null === $choice || \is_array($choice))) {
                $roles[$role->value] = null === $choice ? null : RoleDraft::fromArray($choice);
            }
        }
        $editing = \is_string($data['editing'] ?? null) ? Role::tryFrom($data['editing']) : null;
        $edit = \is_array($data['edit'] ?? null) ? RoleDraft::fromArray($data['edit']) : null;
        $checked = array_filter(\is_array($data['checked'] ?? null) ? $data['checked'] : [], static fn (mixed $fingerprint, mixed $role): bool => \is_string($fingerprint) && \is_string($role), \ARRAY_FILTER_USE_BOTH);

        return new self($roles, null === $edit ? null : $editing, null === $editing ? null : $edit, $checked);
    }
}
