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

    public function testTheOptionsWriteTheFileWithoutQuestions(): void
    {
        file_put_contents($this->path, "APP_PORT=8050\n");

        $status = $this->tester()->execute(['--provider' => 'anthropic', '--api-key' => 'sk-test'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        $content = (string) file_get_contents($this->path);
        self::assertStringStartsWith("APP_PORT=8050\n", $content);
        self::assertStringContainsString("AI_DRAWER_BASE_URL=https://api.anthropic.com\n", $content);
        self::assertStringContainsString("AI_GUESSER_API_KEY=sk-test\n", $content);
    }

    public function testAnApiWithoutKeyIsRefused(): void
    {
        self::assertSame(Command::INVALID, $this->tester()->execute(['--provider' => 'mistral'], ['interactive' => false]));
        self::assertFileDoesNotExist($this->path);
    }

    public function testWithoutOptionsAScriptLeavesTheFileAlone(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester()->execute([], ['interactive' => false]));
        self::assertFileDoesNotExist($this->path);
    }

    public function testInATerminalTheServerQuestionsAreAsked(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['2', 'http://192.168.1.10:1234', 'gemma3', '', '']);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $content = (string) file_get_contents($this->path);
        self::assertStringContainsString("AI_DRAWER_BASE_URL=http://192.168.1.10:1234\n", $content);
        self::assertStringContainsString("AI_DRAWER_MODEL=gemma3\n", $content);
        self::assertStringContainsString("AI_DRAWER_DISABLE_THINKING=true\n", $content);
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new SetupCommand($this->path));

        return new CommandTester($application->find('app:setup'));
    }
}
