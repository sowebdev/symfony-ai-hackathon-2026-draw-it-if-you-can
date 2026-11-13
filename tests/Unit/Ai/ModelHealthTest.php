<?php

namespace App\Tests\Unit\Ai;

use App\Ai\ModelHealth;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\BadRequestException;

final class ModelHealthTest extends TestCase
{
    public function testNoServerIsExplained(): void
    {
        $failure = new \RuntimeException('Failed to connect to host.docker.internal port 11434 after 0 ms: Could not connect to server');

        self::assertStringStartsWith('Nothing answers at this address: start the model server', ModelHealth::explain($failure));
    }

    public function testARefusedKeyIsExplained(): void
    {
        self::assertStringStartsWith('The server refused the API key', ModelHealth::explain(new \RuntimeException('HTTP 401 returned for "https://api.example.com"')));
    }

    public function testAnotherFailureIsGivenAsIs(): void
    {
        self::assertSame('The call failed: the server is overloaded', ModelHealth::explain(new \RuntimeException('the server is overloaded')));
    }

    public function testAMissingModelSaysHowToDownloadIt(): void
    {
        self::assertStringEndsWith('(with Ollama: ollama pull lfm3-vl:2b), or set another one.', ModelHealth::explain(new \RuntimeException('model "lfm3-vl:2b" not found')));
        self::assertStringContainsString('ollama pull lfm3-vl:2b)', ModelHealth::explain(new \RuntimeException("model 'lfm3-vl:2b' not found")));
    }

    public function testAReasoningModelThatTheBridgeCannotReadIsExplained(): void
    {
        $failure = new \TypeError('Symfony\AI\Platform\Result\TextResult::__construct(): Argument #1 ($content) must be of type string, array given');

        self::assertStringStartsWith('This model answers with reasoning blocks', ModelHealth::explain($failure));
    }

    public function testARefusedRequestGivesTheReasonOfTheServer(): void
    {
        self::assertSame('The server refused the request: model not found', ModelHealth::explain(new BadRequestException('model not found')));
        self::assertStringContainsString('without a reason the game can read', ModelHealth::explain(new BadRequestException('Bad Request')));
    }
}
