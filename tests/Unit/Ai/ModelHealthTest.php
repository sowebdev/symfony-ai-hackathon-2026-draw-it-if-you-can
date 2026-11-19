<?php

namespace App\Tests\Unit\Ai;

use App\Ai\ModelHealth;
use App\Ai\Problem;
use App\Ai\Provider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\Component\HttpClient\Exception\TimeoutException;

final class ModelHealthTest extends TestCase
{
    public function testNoServerIsExplained(): void
    {
        $failure = new \RuntimeException('Failed to connect to host.docker.internal port 11434 after 0 ms: Could not connect to server');

        self::assertSame('problem.no_server', ModelHealth::explain($failure)->id);
    }

    public function testARefusedKeyIsExplained(): void
    {
        $problem = ModelHealth::explain(new \RuntimeException('HTTP 401 returned for "https://api.example.com"'));

        self::assertSame('problem.refused_key', $problem->id);
        self::assertStringContainsString('HTTP 401', $problem->parameters['%detail%']);
    }

    public function testAnotherFailureIsGivenAsIs(): void
    {
        $problem = ModelHealth::explain(new \RuntimeException('the server is overloaded'));

        self::assertSame('problem.call_failed', $problem->id);
        self::assertSame(['%detail%' => 'the server is overloaded'], $problem->parameters);
    }

    public function testAMissingModelSaysWhichOneAndHowToDownloadIt(): void
    {
        self::assertSame(['%model%' => 'lfm3-vl:2b'], ModelHealth::explain(new \RuntimeException('model "lfm3-vl:2b" not found'))->parameters);
        self::assertSame('problem.model_missing', ModelHealth::explain(new \RuntimeException("model 'lfm3-vl:2b' not found"))->id);
    }

    public function testAReasoningModelThatTheBridgeCannotReadIsExplained(): void
    {
        $failure = new \TypeError('Symfony\AI\Platform\Result\TextResult::__construct(): Argument #1 ($content) must be of type string, array given');

        self::assertSame('problem.reasoning_blocks', ModelHealth::explain($failure)->id);
    }

    public function testARefusedRequestGivesTheReasonOfTheServer(): void
    {
        $problem = ModelHealth::explain(new BadRequestException('model not found'));
        self::assertSame('problem.refused', $problem->id);
        self::assertSame(['%detail%' => 'model not found'], $problem->parameters);
        self::assertNull($problem->advice, 'Not the option of the game: no advice on the switch');

        self::assertSame('problem.refused_without_reason', ModelHealth::explain(new BadRequestException('Bad Request'))->id);
    }

    /**
     * Which way to flip the reasoning switch, said by the problem: a refused option with the switch on says
     * to turn it off; a very slow answer or a block answer with it off says to turn it on; the providers
     * without a switch say to choose another model.
     *
     * @return iterable<string, array{\Throwable, ?Provider, bool, string, ?string}>
     */
    public static function advice(): iterable
    {
        $blocks = new \TypeError('Argument #1 ($content) must be of type string, array given');
        yield 'a refused option, the switch on: untick it' => [new BadRequestException('Bad Request'), Provider::Compatible, true, 'problem.refused_without_reason', 'advice.reasoning_untick'];
        yield 'a refused option named, the switch on: untick it' => [new BadRequestException('Unknown parameter: chat_template_kwargs'), Provider::Compatible, true, 'problem.refused', 'advice.reasoning_untick'];
        yield 'a refused request about something else, the switch on' => [new BadRequestException('model not found'), Provider::Compatible, true, 'problem.refused', null];
        yield 'a refused request, the switch off: not our option' => [new BadRequestException('Bad Request'), Provider::Compatible, false, 'problem.refused_without_reason', 'advice.reasoning_choose'];
        yield 'a very slow answer, the switch off: tick it' => [new TimeoutException('Idle timeout reached'), Provider::Compatible, false, 'problem.slow', 'advice.reasoning_tick'];
        yield 'a very slow answer, the switch on: another model' => [new TimeoutException('Idle timeout reached'), Provider::Compatible, true, 'problem.slow', 'advice.reasoning_choose'];
        yield 'a very slow answer wrapped in another' => [new \RuntimeException('call failed', 0, new TimeoutException('Idle timeout reached')), Provider::Compatible, false, 'problem.slow', 'advice.reasoning_tick'];
        yield 'a very slow answer, no switch' => [new TimeoutException('Idle timeout reached'), Provider::Anthropic, false, 'problem.slow', 'advice.reasoning_choose'];
        yield 'blocks, the switch off: tick it' => [$blocks, Provider::Compatible, false, 'problem.reasoning_blocks', 'advice.reasoning_tick'];
        yield 'blocks, the switch on: another model' => [$blocks, Provider::Compatible, true, 'problem.reasoning_blocks', 'advice.reasoning_choose'];
        yield 'blocks, no switch' => [$blocks, Provider::Mistral, false, 'problem.reasoning_blocks', 'advice.reasoning_choose'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('advice')]
    public function testTheMessageSaysWhichWayToFlipTheSwitch(\Throwable $failure, ?Provider $provider, bool $switchOff, string $id, ?string $advice): void
    {
        $problem = ModelHealth::explain($failure, $provider, $switchOff);

        self::assertSame($id, $problem->id);
        self::assertSame($advice, $problem->advice);
    }

    public function testAProblemIsKeptAsIdsAndTranslatedByWhoLooks(): void
    {
        $problem = new Problem('problem.not_a_circle', ['%answer%' => 'cat'], 'advice.reasoning_tick');

        self::assertEquals($problem, Problem::fromArray($problem->toArray()));
        $translator = new \Symfony\Component\Translation\IdentityTranslator();
        self::assertSame('problem.not_a_circle advice.reasoning_tick', $problem->trans($translator));
    }
}
