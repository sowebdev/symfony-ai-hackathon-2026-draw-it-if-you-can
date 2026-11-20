<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The judge: the verdict of each guess, the pairs it judged (kept across games), and the easy mode of the settings and of each game.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE judged_pair (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, judged_at DATETIME NOT NULL, word VARCHAR(100) NOT NULL, answer VARCHAR(100) NOT NULL, language VARCHAR(2) NOT NULL, link VARCHAR(12) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX judged_pair_unique ON judged_pair (word, answer, language)');
        $this->addSql('ALTER TABLE game_setting ADD COLUMN easy_mode BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE game ADD COLUMN easy_mode BOOLEAN DEFAULT 0 NOT NULL');
        // The guesses before the judge were accepted or refused, nothing in between.
        $this->addSql('ALTER TABLE guess ADD COLUMN verdict VARCHAR(10) DEFAULT \'refused\' NOT NULL');
        $this->addSql('UPDATE guess SET verdict = \'accepted\' WHERE accepted = 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guess DROP COLUMN verdict');
        $this->addSql('ALTER TABLE game DROP COLUMN easy_mode');
        $this->addSql('ALTER TABLE game_setting DROP COLUMN easy_mode');
        $this->addSql('DROP TABLE judged_pair');
    }
}
