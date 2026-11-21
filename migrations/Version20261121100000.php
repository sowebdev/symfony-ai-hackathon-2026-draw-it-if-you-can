<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A guess is kept as soon as it is said, pending: its verdict stays empty until it is judged.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__guess AS SELECT id, accepted, word, said_after_ms, round_id, author, verdict FROM guess');
        $this->addSql('DROP TABLE guess');
        $this->addSql('CREATE TABLE guess (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, accepted BOOLEAN NOT NULL, word VARCHAR(100) NOT NULL, said_after_ms INTEGER NOT NULL, round_id BLOB NOT NULL, author VARCHAR(10) DEFAULT \'player\' NOT NULL, verdict VARCHAR(10) DEFAULT NULL, CONSTRAINT FK_32D30F96A6005CA0 FOREIGN KEY (round_id) REFERENCES round (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO guess (id, accepted, word, said_after_ms, round_id, author, verdict) SELECT id, accepted, word, said_after_ms, round_id, author, verdict FROM __temp__guess');
        $this->addSql('DROP TABLE __temp__guess');
        $this->addSql('CREATE INDEX IDX_32D30F96A6005CA0 ON guess (round_id)');
    }

    public function down(Schema $schema): void
    {
        // A word still pending was never judged: refused, as when the judge gives no label.
        $this->addSql('CREATE TEMPORARY TABLE __temp__guess AS SELECT id, accepted, word, said_after_ms, round_id, author, COALESCE(verdict, \'refused\') AS verdict FROM guess');
        $this->addSql('DROP TABLE guess');
        $this->addSql('CREATE TABLE guess (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, accepted BOOLEAN NOT NULL, word VARCHAR(100) NOT NULL, said_after_ms INTEGER NOT NULL, round_id BLOB NOT NULL, author VARCHAR(10) DEFAULT \'player\' NOT NULL, verdict VARCHAR(10) DEFAULT \'refused\' NOT NULL, CONSTRAINT FK_32D30F96A6005CA0 FOREIGN KEY (round_id) REFERENCES round (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO guess (id, accepted, word, said_after_ms, round_id, author, verdict) SELECT id, accepted, word, said_after_ms, round_id, author, verdict FROM __temp__guess');
        $this->addSql('DROP TABLE __temp__guess');
        $this->addSql('CREATE INDEX IDX_32D30F96A6005CA0 ON guess (round_id)');
    }
}
