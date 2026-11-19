<?php

namespace App\Ai;

/**
 * The setting as the screen may show it: everything but the API key, of which it has the end only.
 */
final readonly class SettingView
{
    public function __construct(
        public Provider $provider,
        public ?string $address,
        public string $drawerModel,
        public string $guesserModel,
        public bool $reasoningOff,
        public bool $hasApiKey,
        /** "…3f9a" */
        public ?string $maskedApiKey,
    ) {
    }
}
