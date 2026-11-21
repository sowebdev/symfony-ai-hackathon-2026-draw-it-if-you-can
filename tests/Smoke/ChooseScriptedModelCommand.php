<?php

namespace App\Tests\Smoke;

use App\Ai\ModelSettings;
use App\Ai\Provider;
use App\Ai\Role;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * Stores a model for both roles, as the models screen would: the smoke test starts where a player
 * who chose one starts. The platform behind it is the script (ScriptedPlatforms).
 */
#[AsCommand('app:smoke:choose-model', 'Choose the scripted model for both roles (smoke environment only)')]
final readonly class ChooseScriptedModelCommand
{
    public function __construct(private ModelSettings $settings)
    {
    }

    public function __invoke(): int
    {
        foreach ([Role::Drawer, Role::Guesser] as $role) {
            $this->settings->choose($role, Provider::Compatible, 'http://scripted:8000', 'scripted', true);
        }

        return Command::SUCCESS;
    }
}
