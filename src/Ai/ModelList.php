<?php

namespace App\Ai;

/**
 * The models a provider offers, sorted by what the catalog of its bridge says each can do. A model
 * the catalog does not know, and every model of a Generic provider, is "others, untested": it may
 * well play, ModelCheck finds out.
 */
final readonly class ModelList
{
    /**
     * @param list<string> $guesser Known to read images
     * @param list<string> $drawer  Known to read images and to call tools
     * @param list<string> $others  Not in the catalog: untested
     * @param string|null  $problem Why the list could not be read; the caller falls back to a model typed by hand
     */
    public function __construct(
        public array $guesser = [],
        public array $drawer = [],
        public array $others = [],
        public ?string $problem = null,
    ) {
    }

    public static function failed(string $problem): self
    {
        return new self(problem: $problem);
    }

    public function isRead(): bool
    {
        return null === $this->problem;
    }

    /**
     * @return list<string> The models known to play the role
     */
    public function knownFor(Role $role): array
    {
        return Role::Drawer === $role ? $this->drawer : $this->guesser;
    }
}
