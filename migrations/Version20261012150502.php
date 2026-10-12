<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012150502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clients, comptes utilisateurs et documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(180) NOT NULL, full_name VARCHAR(120) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, client_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)');
        $this->addSql('CREATE INDEX IDX_88BDF3E919EB6921 ON app_user (client_id)');
        $this->addSql('CREATE TABLE client (id UUID NOT NULL, name VARCHAR(180) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE document (id UUID NOT NULL, original_filename VARCHAR(255) NOT NULL, storage_key VARCHAR(255) NOT NULL, mime_type VARCHAR(127) NOT NULL, size INT NOT NULL, sha256 CHAR(64) NOT NULL, type VARCHAR(40) DEFAULT NULL, period_start DATE DEFAULT NULL, period_end DATE DEFAULT NULL, status VARCHAR(20) NOT NULL, uploaded_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, client_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D8698A76111795A5 ON document (storage_key)');
        $this->addSql('CREATE INDEX idx_document_client_uploaded_at ON document (client_id, uploaded_at)');
        $this->addSql('CREATE INDEX idx_document_client_status ON document (client_id, status)');
        $this->addSql('CREATE INDEX idx_document_failed ON document (status) WHERE status = \'failed\'');
        $this->addSql('CREATE INDEX IDX_D8698A7619EB6921 ON document (client_id)');
        // Contraintes non exprimables dans le mapping Doctrine, garanties par la base.
        $this->addSql('ALTER TABLE document ADD CONSTRAINT chk_document_period CHECK (period_start IS NULL OR period_end IS NULL OR period_end >= period_start)');
        $this->addSql("ALTER TABLE document ADD CONSTRAINT chk_document_status CHECK (status IN ('received', 'processing', 'analyzed', 'failed'))");
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E919EB6921 FOREIGN KEY (client_id) REFERENCES client (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7619EB6921 FOREIGN KEY (client_id) REFERENCES client (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT FK_88BDF3E919EB6921');
        $this->addSql('ALTER TABLE document DROP CONSTRAINT FK_D8698A7619EB6921');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE client');
        $this->addSql('DROP TABLE document');
    }
}
