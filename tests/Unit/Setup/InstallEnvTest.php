<?php

namespace App\Tests\Unit\Setup;

use PHPUnit\Framework\TestCase;

/**
 * bin/install-env, run by make install before the dependencies: a player gets the prod environment and a
 * secret of their own, a developer keeps APP_ENV=dev, nothing is ever replaced, and the database of a game
 * installed before 0.4 keeps its contents under its new name.
 */
final class InstallEnvTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/install-env-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/var', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (['.env.local', 'var/data.db', 'var/data_dev.db'] as $file) {
            @unlink($this->dir.'/'.$file);
        }
        @rmdir($this->dir.'/var');
        @rmdir($this->dir);
    }

    public function testAPlayerGetsTheProdEnvironmentAndASecret(): void
    {
        self::assertStringContainsString('prod environment', $this->install());

        $env = (string) file_get_contents($this->dir.'/.env.local');
        self::assertMatchesRegularExpression('/^APP_ENV=prod$/m', $env);
        self::assertMatchesRegularExpression('/^APP_SECRET=[0-9a-f]{32}$/m', $env);
    }

    public function testADeveloperKeepsTheDevEnvironmentAndNothingIsReplaced(): void
    {
        file_put_contents($this->dir.'/.env.local', "APP_ENCRYPTION_KEY=kept\nAPP_ENV=dev\nAPP_SECRET=mine");

        self::assertStringContainsString('dev environment', $this->install());
        self::assertSame("APP_ENCRYPTION_KEY=kept\nAPP_ENV=dev\nAPP_SECRET=mine", file_get_contents($this->dir.'/.env.local'));

        $this->install();
        self::assertSame("APP_ENCRYPTION_KEY=kept\nAPP_ENV=dev\nAPP_SECRET=mine", file_get_contents($this->dir.'/.env.local'), 'Run again, it changes nothing');
    }

    public function testTheDatabaseOfAnOlderInstallKeepsItsContents(): void
    {
        file_put_contents($this->dir.'/var/data_dev.db', 'the games so far');

        $this->install();

        self::assertFileDoesNotExist($this->dir.'/var/data_dev.db');
        self::assertSame('the games so far', file_get_contents($this->dir.'/var/data.db'));
    }

    public function testADatabaseAlreadyUnderItsNewNameIsNeverOverwritten(): void
    {
        file_put_contents($this->dir.'/var/data_dev.db', 'old');
        file_put_contents($this->dir.'/var/data.db', 'current');

        $this->install();

        self::assertSame('current', file_get_contents($this->dir.'/var/data.db'));
    }

    private function install(): string
    {
        exec(\sprintf('php %s %s 2>&1', escapeshellarg(\dirname(__DIR__, 3).'/bin/install-env'), escapeshellarg($this->dir)), $output, $status);
        self::assertSame(0, $status, implode("\n", $output));

        return implode("\n", $output);
    }
}
