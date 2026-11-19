<?php

namespace App\Tests\Functional\Ai;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use DoctrineMigrations\Version20261119140000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The one setting becomes accounts and a model per role, and back when it can: on a database that holds
 * the row of ai_setting as the game stored it, the key sealed.
 */
final class AccountsMigrationTest extends TestCase
{
    private const string SEALED = 'c2VhbGVkLWJ5LXRoZS1nYW1lLW5vdC1hLXJlYWwta2V5';

    public static function setUpBeforeClass(): void
    {
        // Migrations are not in the autoload: Doctrine Migrations loads them by their path.
        require_once \dirname(__DIR__, 3).'/migrations/Version20261119140000.php';
    }

    private function connection(string $drawer, string $guesser, string $provider = 'anthropic', ?string $address = null): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE ai_setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, sealed_api_key CLOB DEFAULT NULL, masked_api_key VARCHAR(10) DEFAULT NULL, updated_at DATETIME NOT NULL, provider VARCHAR(20) NOT NULL, address VARCHAR(255) DEFAULT NULL, drawer_model VARCHAR(200) NOT NULL, guesser_model VARCHAR(200) NOT NULL, reasoning_off BOOLEAN DEFAULT 0 NOT NULL)');
        $connection->insert('ai_setting', ['sealed_api_key' => self::SEALED, 'masked_api_key' => '…3f9a', 'provider' => $provider, 'address' => $address, 'drawer_model' => $drawer, 'guesser_model' => $guesser, 'reasoning_off' => 1, 'updated_at' => '2026-11-19 10:00:00']);

        return $connection;
    }

    private function migrate(Connection $connection, bool $up = true): void
    {
        $migration = new Version20261119140000($connection, new NullLogger());
        $up ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }

    public function testTheSameModelForBothBecomesOneAccountAndOnlyTheDrawer(): void
    {
        $connection = $this->connection('claude-haiku-5-5', 'claude-haiku-5-5');

        $this->migrate($connection);

        $accounts = $connection->fetchAllAssociative('SELECT * FROM provider_account');
        self::assertCount(1, $accounts);
        self::assertSame('anthropic', $accounts[0]['provider']);
        self::assertSame(self::SEALED, $accounts[0]['sealed_api_key'], 'The key stays sealed as it was: nothing is decrypted');
        self::assertSame('…3f9a', $accounts[0]['masked_api_key']);
        $roles = $connection->fetchAllAssociative('SELECT role, model, account_id, reasoning_off FROM role_model');
        self::assertCount(1, $roles, 'The guesser follows the drawer: no row for it');
        self::assertSame('drawer', $roles[0]['role']);
        self::assertSame('claude-haiku-5-5', $roles[0]['model']);
        self::assertSame($accounts[0]['id'], $roles[0]['account_id']);
        self::assertSame(1, (int) $roles[0]['reasoning_off']);
        self::assertNotContains('ai_setting', $connection->createSchemaManager()->listTableNames(), 'ai_setting is gone');
    }

    public function testTwoModelsBecomeTwoRolesOnTheSameAccount(): void
    {
        $connection = $this->connection('claude-sonnet-5-5', 'claude-haiku-5-5');

        $this->migrate($connection);

        $roles = $connection->fetchAllKeyValue('SELECT role, model FROM role_model');
        self::assertSame(['drawer' => 'claude-sonnet-5-5', 'guesser' => 'claude-haiku-5-5'], $roles);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(DISTINCT account_id) FROM role_model'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM provider_account'));
    }

    public function testAServerKeepsItsAddress(): void
    {
        $connection = $this->connection('qwen', 'qwen', 'openai_compatible', 'http://gate:8001');

        $this->migrate($connection);

        self::assertSame('http://gate:8001', $connection->fetchOne('SELECT address FROM provider_account'));
    }

    public function testWithoutASettingThereIsNothingToMove(): void
    {
        $connection = $this->connection('a', 'b');
        $connection->executeStatement('DELETE FROM ai_setting');

        $this->migrate($connection);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM provider_account'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM role_model'));
    }

    public function testGoingDownGivesTheOneSettingBack(): void
    {
        foreach ([['claude-haiku-5-5', 'claude-haiku-5-5'], ['claude-sonnet-5-5', 'claude-haiku-5-5']] as [$drawer, $guesser]) {
            $connection = $this->connection($drawer, $guesser);
            $this->migrate($connection);
            $this->migrate($connection, up: false);

            $row = $connection->fetchAssociative('SELECT * FROM ai_setting');
            self::assertIsArray($row);
            self::assertSame([$drawer, $guesser, 'anthropic', self::SEALED], [$row['drawer_model'], $row['guesser_model'], $row['provider'], $row['sealed_api_key']]);
        }
    }

    public function testGoingDownWithRolesOnTwoPlacesSaysWhy(): void
    {
        $connection = $this->connection('claude-haiku-5-5', 'claude-haiku-5-5');
        $this->migrate($connection);
        $connection->executeStatement("INSERT INTO provider_account (provider, address, updated_at) VALUES ('openai_compatible', 'http://gate:8001', '2026-11-19 10:00:00')");
        $connection->executeStatement("INSERT INTO role_model (role, account_id, model, reasoning_off, updated_at) VALUES ('guesser', 2, 'qwen', 0, '2026-11-19 10:00:00')");

        $this->expectException(IrreversibleMigration::class);
        $this->expectExceptionMessage('several places');
        $this->migrate($connection, up: false);
    }
}
