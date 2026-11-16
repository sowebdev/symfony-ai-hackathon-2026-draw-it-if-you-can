<?php

namespace App\Tests\Unit\Setup;

use App\Command\SetupCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SetupCommandTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/env-local-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testACloudApiKeepsReasoningAndOtherLines(): void
    {
        file_put_contents($this->path, "APP_PORT=8050\n");

        $status = $this->tester()->execute(['--source' => 'cloud', '--url' => 'https://api.anthropic.com', '--model' => 'claude-haiku-5-5', '--api-key' => 'sk-test'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        $content = (string) file_get_contents($this->path);
        self::assertStringStartsWith("APP_PORT=8050\n", $content);
        self::assertStringContainsString("AI_DRAWER_MODEL=claude-haiku-5-5\n", $content);
        self::assertStringContainsString("AI_GUESSER_MODEL=claude-haiku-5-5\n", $content);
        self::assertStringContainsString("AI_GUESSER_API_KEY=sk-test\n", $content);
        self::assertStringContainsString("AI_DRAWER_DISABLE_THINKING=false\n", $content);
    }

    public function testALocalServerHasDefaultsAndReasoningOff(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--source' => 'local'], ['interactive' => false]));

        $content = (string) file_get_contents($this->path);
        self::assertStringContainsString("AI_DRAWER_BASE_URL=http://host.docker.internal:11434\n", $content);
        self::assertStringContainsString("AI_DRAWER_DISABLE_THINKING=true\n", $content);
    }

    public function testReasoningCanBeForced(): void
    {
        $this->tester()->execute(['--source' => 'local', '--reasoning' => 'on', '--api-key' => 'local-key'], ['interactive' => false]);

        $content = (string) file_get_contents($this->path);
        self::assertStringContainsString("AI_DRAWER_DISABLE_THINKING=false\n", $content);
        self::assertStringContainsString("AI_DRAWER_API_KEY=local-key\n", $content);
    }

    public function testACloudApiWithoutKeyOrModelIsRefused(): void
    {
        self::assertSame(Command::INVALID, $this->tester()->execute(['--source' => 'cloud', '--url' => 'https://api.mistral.ai', '--model' => 'x'], ['interactive' => false]));
        self::assertSame(Command::INVALID, $this->tester()->execute(['--source' => 'cloud', '--url' => 'https://api.mistral.ai', '--api-key' => 'k'], ['interactive' => false]));
        self::assertFileDoesNotExist($this->path);
    }

    public function testWithoutOptionsAScriptLeavesTheFileAlone(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester()->execute([], ['interactive' => false]));
        self::assertFileDoesNotExist($this->path);
    }

    public function testInATerminalTheQuestionsAreAsked(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['1', 'http://192.168.1.10:1234', 'gemma3', '', '']);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $content = (string) file_get_contents($this->path);
        self::assertStringContainsString("AI_DRAWER_BASE_URL=http://192.168.1.10:1234\n", $content);
        self::assertStringContainsString("AI_GUESSER_MODEL=gemma3\n", $content);
        self::assertStringContainsString("AI_DRAWER_DISABLE_THINKING=true\n", $content);
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new SetupCommand($this->path));

        return new CommandTester($application->find('app:setup'));
    }
}
