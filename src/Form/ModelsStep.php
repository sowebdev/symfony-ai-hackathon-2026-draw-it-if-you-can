<?php

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Step 2 of the model screen: the model, taken from the list or typed by name (the typed name wins),
 * and optionally another one to guess, and the reasoning switch where the game cannot decide.
 */
final class ModelsStep
{
    public function __construct(
        /** The model: the drawer's, which can guess as well */
        public ?string $drawerChoice = null,
        #[Assert\Length(max: 200, maxMessage: 'error.too_long')]
        public ?string $drawerName = null,
        /** Whether the guesser is another model than the one that draws */
        public bool $otherGuesser = false,
        public ?string $guesserChoice = null,
        #[Assert\Length(max: 200, maxMessage: 'error.too_long')]
        public ?string $guesserName = null,
        public bool $reasoningOff = false,
    ) {
    }

    public function guesser(): string
    {
        // The model that draws sees images: it can always guess too.
        return $this->otherGuesser ? self::pick($this->guesserName, $this->guesserChoice) : $this->drawer();
    }

    public function drawer(): string
    {
        return self::pick($this->drawerName, $this->drawerChoice);
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ('' === $this->drawer()) {
            $context->buildViolation('error.model_needed')->atPath('drawerName')->addViolation();
        }
        if ($this->otherGuesser && '' === $this->guesser()) {
            $context->buildViolation('error.model_needed')->atPath('guesserName')->addViolation();
        }
    }

    private static function pick(?string $typed, ?string $chosen): string
    {
        $typed = trim((string) $typed);

        return '' !== $typed ? $typed : trim((string) $chosen);
    }
}
