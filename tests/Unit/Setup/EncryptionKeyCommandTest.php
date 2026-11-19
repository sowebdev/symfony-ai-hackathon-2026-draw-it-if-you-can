<?php

namespace App\Tests\Unit\Setup;

use App\Ai\Encryption;
use App\Command\EncryptionKeyCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class EncryptionKeyCommandTest extends TestCase
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

    public function testWritesAKeyWhenThereIsNoFile(): void
    {
        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/^APP_ENCRYPTION_KEY=[A-Za-z0-9+\/]{43}=\n$/', (string) file_get_contents($this->path));
        self::assertStringContainsString('APP_ENCRYPTION_KEY written to .env.local', $tester->getDisplay());
        self::assertStringNotContainsString($this->key(), $tester->getDisplay(), 'The key is not printed');
    }

    public function testTheKeyItWritesIsUsableByEncryption(): void
    {
        $this->runCommand();

        $encryption = new Encryption($this->key());

        self::assertTrue($encryption->isConfigured());
        self::assertSame('sk-secret', $encryption->decrypt($encryption->encrypt('sk-secret')));
    }

    public function testKeepsTheOtherLinesAndStartsALineOfItsOwn(): void
    {
        file_put_contents($this->path, "APP_PORT=8050\nAI_DRAWER_MODEL=qwen");

        $this->runCommand();

        $lines = explode("\n", (string) file_get_contents($this->path));
        self::assertSame(['APP_PORT=8050', 'AI_DRAWER_MODEL=qwen'], \array_slice($lines, 0, 2));
        self::assertStringStartsWith('APP_ENCRYPTION_KEY=', $lines[2]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function existingKeys(): iterable
    {
        yield 'plain' => ["APP_PORT=8050\nAPP_ENCRYPTION_KEY=abc123\n"];
        yield 'double quotes' => ['APP_ENCRYPTION_KEY="abc123"'."\n"];
        yield 'single quotes' => ["APP_ENCRYPTION_KEY='abc123'\n"];
    }

    #[DataProvider('existingKeys')]
    public function testNeverReplacesAKey(string $content): void
    {
        file_put_contents($this->path, $content);

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame($content, file_get_contents($this->path));
        self::assertSame('', $tester->getDisplay(), 'Nothing is said when there is nothing to do');
    }

    public function testAnEmptyKeyIsNotAKey(): void
    {
        file_put_contents($this->path, "APP_ENCRYPTION_KEY=\n");

        $this->runCommand();

        self::assertMatchesRegularExpression('/APP_ENCRYPTION_KEY=[A-Za-z0-9+\/]{43}=/', (string) file_get_contents($this->path));
    }

    public function testTwoRunsMakeOneKey(): void
    {
        $this->runCommand();
        $first = (string) file_get_contents($this->path);
        $this->runCommand();

        self::assertSame($first, file_get_contents($this->path));
    }

    private function runCommand(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new EncryptionKeyCommand($this->path));
        $tester = new CommandTester($application->find('app:encryption-key'));
        $tester->execute([]);

        return $tester;
    }

    private function key(): string
    {
        preg_match('/^APP_ENCRYPTION_KEY=(\S+)$/m', (string) file_get_contents($this->path), $match);

        return $match[1] ?? '';
    }
}
