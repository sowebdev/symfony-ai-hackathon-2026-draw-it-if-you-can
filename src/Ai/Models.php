<?php

namespace App\Ai;

use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * The model of each role: its platform, its name and the options it needs, from the current
 * setting (ModelSettings: the one stored from the models screen). The platform is built when it is
 * needed, so that a new setting takes effect at once.
 */
final readonly class Models
{
    /**
     * The longest a call to a model may last inside an ordinary request, whose PHP limit is 30 s:
     * a slower model is a message for the player, not an error page. The streamed loops, which
     * lift the PHP limit, and the check of the models screen, which raises it, say otherwise.
     */
    public const int REQUEST_LIMIT_S = 25;

    /**
     * @param array<string, RoleSetup> $setups A setting that is not saved yet, by role: what the model screen checks
     */
    public function __construct(
        private ModelSettings $settings,
        private PlatformFactory $platforms,
        private array $setups = [],
    ) {
    }

    /**
     * The same models, on a setting that is not saved: to check it before saving it.
     *
     * @param array<string, RoleSetup> $setups What each role calls, by role value
     */
    public function with(array $setups): self
    {
        return new self($this->settings, $this->platforms, $setups);
    }

    /**
     * Who answers for the role, and with which model and switch.
     */
    public function setup(Role $role): RoleSetup
    {
        return $this->setups[$role->value] ?? $this->settings->forRole($role);
    }

    /**
     * @param array<string, mixed> $options
     * @param int|null             $maxTokens    The longest answer, said the way the provider's API takes it
     * @param int|null             $maxDurationS The longest the call may last, in seconds; null: no limit of its own
     */
    public function invoke(Role $role, MessageBag $messages, array $options = [], ?int $maxTokens = null, ?int $maxDurationS = self::REQUEST_LIMIT_S): DeferredResult
    {
        $setup = $this->setup($role);

        return $this->platform($setup, $maxDurationS)->invoke($this->modelName($role, $setup), $messages, $this->optionsOf($setup, $options, $maxTokens));
    }

    /**
     * An agent on the model of the role, with the given tools. Call it with options().
     *
     * @param int|null $maxDurationS The longest each call of the agent may last, in seconds; null: no limit of its own
     */
    public function agent(Role $role, object $tool, int $maxToolCalls, ?int $maxDurationS = self::REQUEST_LIMIT_S): Agent
    {
        $setup = $this->setup($role);

        return new Agent($this->platform($setup, $maxDurationS), $this->modelName($role, $setup), toolbox: new Toolbox([$tool]), maxToolCalls: $maxToolCalls);
    }

    /**
     * The call options, with those the provider takes: the limit of the answer, and what keeps the
     * model from reasoning for long (Provider::reasoningOptions()).
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function options(Role $role, array $options = [], ?int $maxTokens = null): array
    {
        return $this->optionsOf($this->setup($role), $options, $maxTokens);
    }

    /**
     * Whether the call failed for being too slow: the limit of the call, or the client's own.
     */
    public static function isTooSlow(\Throwable $exception): bool
    {
        for ($previous = $exception; null !== $previous; $previous = $previous->getPrevious()) {
            if ($previous instanceof TimeoutExceptionInterface || ($previous instanceof TransportExceptionInterface && str_contains($previous->getMessage(), 'Max duration was reached'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * "qwen3.8-27b-vision at http://gate:8001", for messages that tell what to fix.
     */
    public function describe(Role $role): string
    {
        return $this->setup($role)->describe();
    }

    /**
     * Changes when the provider, the address, the model or the key does.
     */
    public function fingerprint(Role $role): string
    {
        return $this->setup($role)->fingerprint();
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
        $options += $setup->provider->reasoningOptions($setup->model, $setup->reasoningOff);

        return $options;
    }

    private function platform(RoleSetup $setup, ?int $maxDurationS): PlatformInterface
    {
        return $this->platforms->create($setup->provider, $setup->address, $setup->apiKey(), $maxDurationS);
    }

    /**
     * @return non-empty-string
     */
    private function modelName(Role $role, RoleSetup $setup): string
    {
        if ('' === $setup->model) {
            throw new \LogicException(\sprintf('No model is set for the role "%s": choose one on the models screen of the game.', $role->name));
        }

        return $setup->model;
    }
}
