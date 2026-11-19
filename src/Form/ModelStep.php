<?php

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The last question of a role: its model, taken from the list or typed by name (the typed name wins),
 * and the reasoning switch where the game cannot decide.
 */
final class ModelStep
{
    public function __construct(
        public ?string $choice = null,
        #[Assert\Length(max: 200, maxMessage: 'error.too_long')]
        public ?string $name = null,
        public bool $reasoningOff = false,
    ) {
    }

    public function model(): string
    {
        $typed = trim((string) $this->name);

        return '' !== $typed ? $typed : trim((string) $this->choice);
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ('' === $this->model()) {
            $context->buildViolation('error.model_needed')->atPath('name')->addViolation();
        }
    }
}
