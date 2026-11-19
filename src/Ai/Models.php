<?php

namespace App\Ai;

use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;

/**
 * The model of each role: its platform, its name and the options it needs, from the current
 * setting (ModelSettings: the game's, else the AI_* variables). The platform is built when it is
 * needed, so that a new setting takes effect at once.
 */
final readonly class Models
{
    public function __construct(
        private ModelSettings $settings,
        private PlatformFactory $platforms,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @param int|null             $maxTokens The longest answer, said the way the provider's API takes it
     */
    public function invoke(Role $role, MessageBag $messages, array $options = [], ?int $maxTokens = null): DeferredResult
    {
        $setup = $this->settings->forRole($role);

        return $this->platform($setup)->invoke($this->modelName($role, $setup), $messages, $this->optionsOf($setup, $options, $maxTokens));
    }

    /**
     * An agent on the model of the role, with the given tools. Call it with options().
     */
    public function agent(Role $role, object $tool, int $maxToolCalls): Agent
    {
        $setup = $this->settings->forRole($role);

        return new Agent($this->platform($setup), $this->modelName($role, $setup), toolbox: new Toolbox([$tool]), maxToolCalls: $maxToolCalls);
    }

    /**
     * The call options, with those the provider takes: the limit of the answer, and the switch of
     * reasoning where it is understood.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function options(Role $role, array $options = [], ?int $maxTokens = null): array
    {
        return $this->optionsOf($this->settings->forRole($role), $options, $maxTokens);
    }

    /**
     * "qwen3.8-27b-vision at http://gate:8001", for messages that tell what to fix.
     */
    public function describe(Role $role): string
    {
        return $this->settings->forRole($role)->describe();
    }

    /**
     * Changes when the provider, the address, the model or the key does.
     */
    public function fingerprint(Role $role): string
    {
        return $this->settings->forRole($role)->fingerprint();
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function optionsOf(RoleSetup $setup, array $options, ?int $maxTokens): array
    {
        if (null !== $maxTokens) {
            $options += $setup->provider->limit($maxTokens);
        }
        if ($setup->reasoningOff) {
            $thinks = $setup->provider->knows($setup->model)?->supports(Capability::THINKING) ?? false;
            $options += $setup->provider->reasoningOff($thinks);
        }

        return $options;
    }

    private function platform(RoleSetup $setup): PlatformInterface
    {
        return $this->platforms->create($setup->provider, $setup->address, $setup->apiKey());
    }

    /**
     * @return non-empty-string
     */
    private function modelName(Role $role, RoleSetup $setup): string
    {
        if ('' === $setup->model) {
            throw new \LogicException(\sprintf('No model is set for the role "%s": choose one in the settings of the game, or fill in its AI_*_MODEL variable.', $role->name));
        }

        return $setup->model;
    }
}
