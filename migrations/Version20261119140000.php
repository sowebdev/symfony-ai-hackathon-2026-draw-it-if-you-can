<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'One AI per role, each at the provider of its choice: the one setting becomes accounts (a place and its key) and a model per role.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE provider_account (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, sealed_api_key CLOB DEFAULT NULL, masked_api_key VARCHAR(10) DEFAULT NULL, updated_at DATETIME NOT NULL, provider VARCHAR(20) NOT NULL, address VARCHAR(255) DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX provider_account_place ON provider_account (provider, address)');
        $this->addSql('CREATE TABLE role_model (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, updated_at DATETIME NOT NULL, role VARCHAR(20) NOT NULL, model VARCHAR(200) NOT NULL, reasoning_off BOOLEAN DEFAULT 0 NOT NULL, account_id INTEGER NOT NULL, CONSTRAINT FK_20499AF69B6B5FBA FOREIGN KEY (account_id) REFERENCES provider_account (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_20499AF657698A6A ON role_model (role)');
        $this->addSql('CREATE INDEX IDX_20499AF69B6B5FBA ON role_model (account_id)');

        // The row becomes one account (its key stays sealed as it is: nothing is decrypted), the model of the
        // drawer, and the model of the guesser only when it is another one: else the guesser follows the drawer.
        $this->addSql('INSERT INTO provider_account (provider, address, sealed_api_key, masked_api_key, updated_at) SELECT provider, address, sealed_api_key, masked_api_key, updated_at FROM ai_setting ORDER BY id LIMIT 1');
        $this->addSql("INSERT INTO role_model (role, account_id, model, reasoning_off, updated_at) SELECT 'drawer', (SELECT id FROM provider_account LIMIT 1), drawer_model, reasoning_off, updated_at FROM ai_setting ORDER BY id LIMIT 1");
        $this->addSql("INSERT INTO role_model (role, account_id, model, reasoning_off, updated_at) SELECT 'guesser', (SELECT id FROM provider_account LIMIT 1), guesser_model, reasoning_off, updated_at FROM (SELECT * FROM ai_setting ORDER BY id LIMIT 1) WHERE guesser_model <> drawer_model");

        $this->addSql('DROP TABLE ai_setting');
    }

    public function down(Schema $schema): void
    {
        // The old setting is one place and two models: more than that cannot be told in it.
        $accounts = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM provider_account');
        $roles = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM role_model');
        $onFirst = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM role_model WHERE account_id = (SELECT id FROM provider_account ORDER BY id LIMIT 1)');
        $switches = (int) $this->connection->fetchOne('SELECT COUNT(DISTINCT reasoning_off) FROM role_model');
        if ($accounts > 1 || $onFirst !== $roles || $roles > 2 || $switches > 1) {
            $this->throwIrreversibleMigrationException('The roles use several places: the old setting has one provider for both. Make the roles use one account, or forget the others, then go down again.');
        }

        $this->addSql('CREATE TABLE ai_setting (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, sealed_api_key CLOB DEFAULT NULL, masked_api_key VARCHAR(10) DEFAULT NULL, updated_at DATETIME NOT NULL, provider VARCHAR(20) NOT NULL, address VARCHAR(255) DEFAULT NULL, drawer_model VARCHAR(200) NOT NULL, guesser_model VARCHAR(200) NOT NULL, reasoning_off BOOLEAN DEFAULT 0 NOT NULL)');
        $this->addSql("INSERT INTO ai_setting (provider, address, sealed_api_key, masked_api_key, updated_at, drawer_model, guesser_model, reasoning_off) SELECT a.provider, a.address, a.sealed_api_key, a.masked_api_key, a.updated_at, COALESCE(d.model, g.model), COALESCE(g.model, d.model), COALESCE(d.reasoning_off, g.reasoning_off) FROM provider_account a LEFT JOIN role_model d ON d.account_id = a.id AND d.role = 'drawer' LEFT JOIN role_model g ON g.account_id = a.id AND g.role = 'guesser' WHERE d.id IS NOT NULL OR g.id IS NOT NULL ORDER BY a.id LIMIT 1");
        $this->addSql('DROP TABLE role_model');
        $this->addSql('DROP TABLE provider_account');
    }
}
