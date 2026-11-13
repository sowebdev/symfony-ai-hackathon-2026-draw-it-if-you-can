<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The "food" theme is gone: its rounds were about kitchen utensils and dishes, now everyday objects.
 */
final class Version20261113150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The rounds of the food theme move to everyday objects';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE round SET theme = 'everyday-objects' WHERE theme = 'food'");
        $this->addSql("UPDATE game SET next_theme = 'everyday-objects' WHERE next_theme = 'food'");
    }

    public function down(Schema $schema): void
    {
        // Which rounds were about food is not kept: nothing to restore.
    }
}
