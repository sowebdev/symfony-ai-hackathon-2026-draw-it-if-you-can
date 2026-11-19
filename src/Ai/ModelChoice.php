<?php

namespace App\Ai;

/**
 * A model of a list, with what is known of it now: the capabilities a catalog or the provider states,
 * `null` when nothing says (a server without capabilities in its answer): the check will tell.
 */
final readonly class ModelChoice
{
    public function __construct(
        public string $name,
        /** Reads images */
        public ?bool $vision = null,
        /** Calls tools */
        public ?bool $tools = null,
        /** When the model was created (Unix time), if the list says */
        public ?int $created = null,
    ) {
    }

    public function created(?int $created): self
    {
        return new self($this->name, $this->vision, $this->tools, $created);
    }

    /**
     * Whether it can play the role as far as is known: only a capability known to be missing rules it out.
     */
    public function canPlay(Role $role): bool
    {
        return false !== $this->vision && (Role::Drawer !== $role || false !== $this->tools);
    }

    /**
     * Whether something is known of what it can do.
     */
    public function isKnown(): bool
    {
        return null !== $this->vision || null !== $this->tools;
    }

    /**
     * @return array{name: string, vision: ?bool, tools: ?bool, created: ?int}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'vision' => $this->vision, 'tools' => $this->tools, 'created' => $this->created];
    }

    /**
     * @param array{name: string, vision: ?bool, tools: ?bool, created: ?int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['vision'], $data['tools'], $data['created']);
    }
}
