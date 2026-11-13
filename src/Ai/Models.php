<?php

namespace App\Ai;

use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Toolbox;
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
        [$platform, $model] = $this->of($role);

        return $platform->invoke($model, $messages, $this->options($role, $options));
    }

    /**
     * An agent on the model of the role, with the given tools. Call it with options().
     */
    public function agent(Role $role, object $tool, int $maxToolCalls): Agent
    {
        [$platform, $model] = $this->of($role);

        return new Agent($platform, $model, toolbox: new Toolbox([$tool]), maxToolCalls: $maxToolCalls);
    }

    /**
     * The call options, with those the role needs.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function options(Role $role, array $options = []): array
    {
        if ($this->of($role)[3]) {
            // No reasoning: a local model may reason for tens of seconds (tests of 10/11/2026).
            // llama.cpp reads chat_template_kwargs, Ollama reads reasoning_effort and ignores the
            // other (13/11/2026); a cloud API may refuse either, hence the switch.
            $options['chat_template_kwargs'] = ['enable_thinking' => false];
            $options['reasoning_effort'] = 'none';
        }

        return $options;
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
