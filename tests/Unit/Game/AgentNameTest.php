<?php

namespace App\Tests\Unit\Game;

use App\Game\AgentName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentNameTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function names(): iterable
    {
        yield 'Claude Code' => ['claude-code', 'Claude'];
        yield 'Vibe' => ['mistral-vibe', 'Vibe'];
        yield 'whatever the case' => ['Claude-Code', 'Claude'];
        yield 'an unknown client, made readable' => ['my_little-agent', 'My little agent'];
        yield 'a long name, cut' => ['a-very-long-name-for-an-agent', 'A very long name fo…'];
        yield 'no name' => ['', null];
        yield 'the old placeholder' => ['MCP client', null];
        yield 'the default of an MCP library, as Vibe sends it' => ['mcp', null];
        yield 'nothing' => [null, null];
    }

    #[DataProvider('names')]
    public function testThePlayerReadsASimpleName(?string $clientName, ?string $shown): void
    {
        self::assertSame($shown, AgentName::forDisplay($clientName));
    }

    public function testANicknameIsOneLineOfAtMost24Characters(): void
    {
        self::assertSame('Picasso du dimanche', AgentName::nickname("  Picasso\n\tdu dimanche "));
        self::assertSame(str_repeat('é', 24), AgentName::nickname(str_repeat('é', 24)));

        $this->expectException(\InvalidArgumentException::class);
        AgentName::nickname(str_repeat('é', 25));
    }
}
