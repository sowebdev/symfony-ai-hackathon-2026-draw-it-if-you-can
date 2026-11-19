<?php

namespace App\Ai;

/**
 * The models a provider offers that can play: for a provider with a catalog, those the catalog (or
 * its own answer) says can; for a server without capabilities, all it lists, sorted by name. Embedding,
 * audio and image models are not here, nor are models a catalog does not know: the player types
 * their name.
 */
final readonly class ModelList
{
    /**
     * @param list<ModelChoice> $choices
     * @param Problem|null      $problem Why the list could not be read; the caller falls back to a model typed by hand
     */
    public function __construct(
        public array $choices = [],
        public ?Problem $problem = null,
    ) {
    }

    public static function failed(Problem $problem): self
    {
        return new self(problem: $problem);
    }

    public function isRead(): bool
    {
        return null === $this->problem;
    }

    /**
     * @return list<ModelChoice> The models that can play the role, as far as is known, sorted by name
     */
    public function for(Role $role): array
    {
        return array_values(array_filter($this->choices, static fn (ModelChoice $choice): bool => $choice->canPlay($role)));
    }

    /**
     * Whether any model of the list says what it can do: if none does, the labels say so once.
     */
    public function hasCapabilities(): bool
    {
        foreach ($this->choices as $choice) {
            if ($choice->isKnown()) {
                return true;
            }
        }

        return false;
    }
}
