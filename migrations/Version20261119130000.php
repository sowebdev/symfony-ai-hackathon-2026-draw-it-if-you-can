<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The indefinite article of the word to draw, kept apart from the word (null for the rounds already played).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE round ADD COLUMN target_article VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE round DROP COLUMN target_article');
    }
}
