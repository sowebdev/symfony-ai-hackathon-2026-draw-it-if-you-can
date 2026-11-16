<?php

namespace App\Tests\Unit\Setup;

use App\Setup\EnvLocalFile;
use App\Setup\ModelPreset;
use PHPUnit\Framework\TestCase;

final class EnvLocalFileTest extends TestCase
{
    public function testAnEmptyFileGetsTheSettings(): void
    {
        self::assertSame("AI_DRAWER_MODEL=claude-sonnet-5-5\n", EnvLocalFile::merge('', ['AI_DRAWER_MODEL' => 'claude-sonnet-5-5']));
    }

    public function testOtherLinesAreKeptAndOldSettingsReplaced(): void
    {
        $before = "APP_PORT=8050\nAI_DRAWER_MODEL=gemma3\n# a comment\n";

        self::assertSame(
            "APP_PORT=8050\n# a comment\nAI_DRAWER_MODEL=claude-sonnet-5-5\n",
            EnvLocalFile::merge($before, ['AI_DRAWER_MODEL' => 'claude-sonnet-5-5']),
        );
    }

    public function testAValueWithSpecialCharactersIsQuoted(): void
    {
        self::assertSame("AI_DRAWER_API_KEY=\"a b\\\$c\"\n", EnvLocalFile::merge('', ['AI_DRAWER_API_KEY' => 'a b$c']));
    }

    public function testAnUrlAndAModelNameStayUnquoted(): void
    {
        $content = EnvLocalFile::merge('', ModelPreset::Server->values('http://host.docker.internal:11434', 'hf.co/unsloth/Qwen3.8-27B-GGUF:UD-Q3_K_XL', 'qwen', ''));

        self::assertStringContainsString("AI_DRAWER_BASE_URL=http://host.docker.internal:11434\n", $content);
        self::assertStringContainsString("AI_DRAWER_MODEL=hf.co/unsloth/Qwen3.8-27B-GGUF:UD-Q3_K_XL\n", $content);
        self::assertStringContainsString("AI_GUESSER_API_KEY=\n", $content);
        self::assertStringContainsString("AI_GUESSER_DISABLE_THINKING=true\n", $content);
    }

    public function testAnApiPresetSetsBothRolesAndKeepsReasoningOptionsOff(): void
    {
        $values = ModelPreset::Anthropic->values('https://api.anthropic.com', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'sk-test');

        self::assertSame('claude-sonnet-5-5', $values['AI_DRAWER_MODEL']);
        self::assertSame('claude-haiku-5-5', $values['AI_GUESSER_MODEL']);
        self::assertSame('sk-test', $values['AI_GUESSER_API_KEY']);
        self::assertSame('false', $values['AI_DRAWER_DISABLE_THINKING']);
    }
}
