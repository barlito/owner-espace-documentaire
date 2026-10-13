<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013100100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Queued date and provider reference of an analysis attempt and partial index of unfinished documents, for the reconciliation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analysis_attempt ADD queued_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE analysis_attempt ADD provider_reference VARCHAR(255) DEFAULT NULL');
        // An attempt that started was submitted to the provider: its reference is needed to fetch the outcome.
        $this->addSql('ALTER TABLE analysis_attempt ADD CONSTRAINT chk_attempt_provider_reference CHECK (started_at IS NULL OR provider_reference IS NOT NULL)');
        $this->addSql('CREATE INDEX idx_document_unfinished ON document (status) WHERE ((status)::text = ANY (ARRAY[(\'pending\'::character varying)::text, (\'processing\'::character varying)::text]))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_document_unfinished');
        $this->addSql('ALTER TABLE analysis_attempt DROP CONSTRAINT chk_attempt_provider_reference');
        $this->addSql('ALTER TABLE analysis_attempt DROP provider_reference');
        $this->addSql('ALTER TABLE analysis_attempt DROP queued_at');
    }
}
