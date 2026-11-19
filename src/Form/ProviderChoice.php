<?php

namespace App\Form;

use App\Ai\Provider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The first question of a role on the models screen: which provider.
 */
final class ProviderChoice
{
    public function __construct(
        #[Assert\NotNull(message: 'error.choose_provider')]
        public ?Provider $provider = null,
    ) {
    }
}
