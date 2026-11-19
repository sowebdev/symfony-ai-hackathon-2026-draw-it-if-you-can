<?php

namespace App\Form;

use App\Ai\Provider;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Step 1 of the model screen: who answers, with which key, at which address.
 */
final class ProviderStep
{
    public function __construct(
        #[Assert\NotNull(message: 'error.choose_provider')]
        public ?Provider $provider = null,
        public ?string $apiKey = null,
        #[Assert\Length(max: 255, maxMessage: 'error.too_long')]
        public ?string $address = null,
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (null === $this->provider || !$this->provider->hasAddress()) {
            return;
        }
        $address = trim((string) $this->address);
        if ('' === $address) {
            $context->buildViolation('error.address_needed')->atPath('address')->addViolation();

            return;
        }
        $scheme = parse_url($address, \PHP_URL_SCHEME);
        if (!\in_array($scheme, ['http', 'https'], true) || !\is_string(parse_url($address, \PHP_URL_HOST))) {
            $context->buildViolation('error.address_invalid')->atPath('address')->addViolation();
        }
    }
}
