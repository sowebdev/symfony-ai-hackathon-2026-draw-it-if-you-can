<?php

namespace App\Form;

use App\Ai\Provider;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The second question of a role: what the place is still missing, its key and, for a server of one's
 * own, its address.
 */
final class AccessStep
{
    public function __construct(
        public Provider $provider,
        public ?string $apiKey = null,
        #[Assert\Length(max: 255, maxMessage: 'error.too_long')]
        public ?string $address = null,
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (!$this->provider->hasAddress()) {
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
