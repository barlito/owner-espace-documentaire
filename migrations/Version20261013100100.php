<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013100100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Queued date and requeue count of an analysis attempt and partial index of unfinished documents, for the reconciliation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analysis_attempt ADD queued_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE analysis_attempt ADD requeue_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE analysis_attempt ADD CONSTRAINT chk_attempt_requeue_count CHECK (requeue_count >= 0)');
        $this->addSql('CREATE INDEX idx_document_unfinished ON document (status) WHERE ((status)::text = ANY (ARRAY[(\'pending\'::character varying)::text, (\'processing\'::character varying)::text]))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_document_unfinished');
        $this->addSql('ALTER TABLE analysis_attempt DROP requeue_count');
        $this->addSql('ALTER TABLE analysis_attempt DROP queued_at');
    }
}
