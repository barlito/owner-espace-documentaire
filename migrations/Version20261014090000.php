<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'unaccent extension, for the accent-insensitive search of "Mon dossier"';
    }

    public function up(Schema $schema): void
    {
        // Trusted extension (PostgreSQL 13+): the database owner may create it, no superuser needed.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP EXTENSION IF EXISTS unaccent');
    }
}
