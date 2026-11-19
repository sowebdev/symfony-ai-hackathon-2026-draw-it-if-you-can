<?php

namespace App\Tests\Functional\Ai;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261119120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A setting stored as "ollama" (or "own server") is the same case after the migration: another OpenAI-compatible service.
 * It runs on a table of its own, as the database was at that time (ai_setting is gone since).
 */
final class ProviderMigrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Migrations are not in the autoload: Doctrine Migrations loads them by their path.
        require_once \dirname(__DIR__, 3).'/migrations/Version20261119120000.php';
    }

    private function connection(string $provider, ?string $address): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE ai_setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, provider VARCHAR(20) NOT NULL, address VARCHAR(255) DEFAULT NULL, drawer_model VARCHAR(200) NOT NULL, guesser_model VARCHAR(200) NOT NULL, reasoning_off BOOLEAN DEFAULT 0 NOT NULL, updated_at DATETIME NOT NULL)');
        $connection->insert('ai_setting', ['provider' => $provider, 'address' => $address, 'drawer_model' => 'qwen', 'guesser_model' => 'qwen', 'reasoning_off' => 1, 'updated_at' => '2026-11-19 10:00:00']);

        return $connection;
    }

    private function migrate(Connection $connection, bool $up = true): void
    {
        $migration = new Version20261119120000($connection, new NullLogger());
        $up ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Connection $connection): array
    {
        return $connection->fetchAssociative('SELECT * FROM ai_setting') ?: [];
    }

    public function testOllamaBecomesTheOtherServerAtItsAddress(): void
    {
        $connection = $this->connection('ollama', null);

        $this->migrate($connection);

        $row = $this->row($connection);
        self::assertSame('openai_compatible', $row['provider']);
        self::assertSame('http://host.docker.internal:11434', $row['address'], 'The address Ollama had by default is written down');
        self::assertSame(1, (int) $row['reasoning_off']);
    }

    public function testAnOwnServerKeepsItsAddress(): void
    {
        $connection = $this->connection('own_server', 'http://gate:8001');

        $this->migrate($connection);

        $row = $this->row($connection);
        self::assertSame('openai_compatible', $row['provider']);
        self::assertSame('http://gate:8001', $row['address']);
    }

    public function testOtherProvidersAreLeftAlone(): void
    {
        $connection = $this->connection('mistral', null);

        $this->migrate($connection);

        self::assertSame('mistral', $this->row($connection)['provider']);
    }

    public function testGoingDownGivesTheOldNameBack(): void
    {
        $connection = $this->connection('openai_compatible', 'http://gate:8001');

        $this->migrate($connection, up: false);

        self::assertSame('own_server', $this->row($connection)['provider']);
    }
}
