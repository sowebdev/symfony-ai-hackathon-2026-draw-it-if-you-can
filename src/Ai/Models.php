<?php

namespace App\Ai;

use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The model of each role: its platform, its name and the options it needs.
 */
final readonly class Models
{
    public function __construct(
        #[Autowire(service: 'ai.platform.generic.drawer')]
        private PlatformInterface $drawerPlatform,
        #[Autowire('%env(AI_DRAWER_MODEL)%')]
        private string $drawerModel,
        #[Autowire('%env(AI_DRAWER_BASE_URL)%')]
        private string $drawerBaseUrl,
        #[Autowire('%env(bool:AI_DRAWER_DISABLE_THINKING)%')]
        private bool $drawerDisableThinking,
        #[Autowire(service: 'ai.platform.generic.guesser')]
        private PlatformInterface $guesserPlatform,
        #[Autowire('%env(AI_GUESSER_MODEL)%')]
        private string $guesserModel,
        #[Autowire('%env(AI_GUESSER_BASE_URL)%')]
        private string $guesserBaseUrl,
        #[Autowire('%env(bool:AI_GUESSER_DISABLE_THINKING)%')]
        private bool $guesserDisableThinking,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function invoke(Role $role, MessageBag $messages, array $options = []): DeferredResult
    {
        [$platform, $model, , $disableThinking] = $this->of($role);
        if ($disableThinking) {
            // No reasoning: the game needs an answer every few seconds (tests of 10/11/2026).
            // A llama.cpp option: a provider's API may refuse it, hence the switch.
            $options['chat_template_kwargs'] = ['enable_thinking' => false];
        }

        return $platform->invoke($model, $messages, $options);
    }

    /**
     * "qwen3.8-27b-vision at http://gate:8001", for messages that tell what to fix.
     */
    public function describe(Role $role): string
    {
        [, $model, $baseUrl] = $this->of($role);

        return \sprintf('%s at %s', $model, $baseUrl);
    }

    /**
     * @return array{PlatformInterface, string, string, bool}
     */
    private function of(Role $role): array
    {
        return match ($role) {
            Role::Drawer => [$this->drawerPlatform, $this->drawerModel, $this->drawerBaseUrl, $this->drawerDisableThinking],
            Role::Guesser => [$this->guesserPlatform, $this->guesserModel, $this->guesserBaseUrl, $this->guesserDisableThinking],
        };
    }
}
