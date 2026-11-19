<?php

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Step 2 of the model screen: a model for each role, taken from the list or typed by name (the typed
 * name wins), and the reasoning switch where the game cannot decide.
 */
final class ModelsStep
{
    public function __construct(
        public ?string $guesserChoice = null,
        #[Assert\Length(max: 200, maxMessage: 'error.too_long')]
        public ?string $guesserName = null,
        public ?string $drawerChoice = null,
        #[Assert\Length(max: 200, maxMessage: 'error.too_long')]
        public ?string $drawerName = null,
        public bool $reasoningOff = false,
    ) {
    }

    public function guesser(): string
    {
        return self::pick($this->guesserName, $this->guesserChoice);
    }

    public function drawer(): string
    {
        return self::pick($this->drawerName, $this->drawerChoice);
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ('' === $this->guesser()) {
            $context->buildViolation('error.model_needed')->atPath('guesserName')->addViolation();
        }
        if ('' === $this->drawer()) {
            $context->buildViolation('error.model_needed')->atPath('drawerName')->addViolation();
        }
    }

    private static function pick(?string $typed, ?string $chosen): string
    {
        $typed = trim((string) $typed);

        return '' !== $typed ? $typed : trim((string) $chosen);
    }
}
