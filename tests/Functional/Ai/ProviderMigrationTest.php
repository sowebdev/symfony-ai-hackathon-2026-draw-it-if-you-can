<?php

namespace App\Tests\Functional\Ai;

use App\Ai\ModelSettings;
use App\Ai\Provider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261119120000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A setting stored as "ollama" (or "own server") is the same case after the migration: another OpenAI-compatible service.
 */
final class ProviderMigrationTest extends KernelTestCase
{
    public static function setUpBeforeClass(): void
    {
        // Migrations are not in the autoload: Doctrine Migrations loads them by their path.
        require_once \dirname(__DIR__, 3).'/migrations/Version20261119120000.php';
    }

    private function insert(Connection $connection, string $provider, ?string $address): void
    {
        $connection->executeStatement('DELETE FROM ai_setting');
        $connection->insert('ai_setting', ['provider' => $provider, 'address' => $address, 'drawer_model' => 'qwen', 'guesser_model' => 'qwen', 'reasoning_off' => 1, 'updated_at' => '2026-11-19 10:00:00']);
    }

    private function migrate(Connection $connection, bool $up = true): void
    {
        $migration = new Version20261119120000($connection, new NullLogger());
        $up ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }

    public function testOllamaBecomesTheOtherServerAtItsAddress(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $this->insert($connection, 'ollama', null);

        $this->migrate($connection);

        $view = self::getContainer()->get(ModelSettings::class)->view();
        self::assertSame(Provider::Compatible, $view?->provider);
        self::assertSame('http://host.docker.internal:11434', $view->address, 'The address Ollama had by default is written down');
        self::assertTrue($view->reasoningOff);
    }

    public function testAnOwnServerKeepsItsAddress(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $this->insert($connection, 'own_server', 'http://gate:8001');

        $this->migrate($connection);

        $view = self::getContainer()->get(ModelSettings::class)->view();
        self::assertSame(Provider::Compatible, $view?->provider);
        self::assertSame('http://gate:8001', $view->address);
    }

    public function testOtherProvidersAreLeftAlone(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $this->insert($connection, 'mistral', null);

        $this->migrate($connection);

        self::assertSame(Provider::Mistral, self::getContainer()->get(ModelSettings::class)->view()?->provider);
    }

    public function testGoingDownGivesTheOldNameBack(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $this->insert($connection, 'openai_compatible', 'http://gate:8001');

        $this->migrate($connection, up: false);

        self::assertSame('own_server', $connection->fetchOne('SELECT provider FROM ai_setting'));
    }
}
