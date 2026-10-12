<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012151322 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Analysis attempts, document timeline and current attempt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE analysis_attempt (id UUID NOT NULL, number INT NOT NULL, status VARCHAR(20) NOT NULL, requested_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, result JSONB DEFAULT NULL, failure_reason VARCHAR(30) DEFAULT NULL, document_id UUID NOT NULL, triggered_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_attempt_document_number ON analysis_attempt (document_id, number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_attempt_document_unfinished ON analysis_attempt (document_id) WHERE (finished_at IS NULL)');
        $this->addSql('CREATE INDEX IDX_623D6460C33F7837 ON analysis_attempt (document_id)');
        $this->addSql('CREATE INDEX IDX_623D646063C5923F ON analysis_attempt (triggered_by_id)');
        $this->addSql('CREATE TABLE document_event (id UUID NOT NULL, type VARCHAR(40) NOT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, payload JSONB NOT NULL, document_id UUID NOT NULL, client_id UUID NOT NULL, attempt_id UUID DEFAULT NULL, actor_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_document_event_timeline ON document_event (document_id, occurred_at)');
        $this->addSql('CREATE INDEX IDX_520F7CD5C33F7837 ON document_event (document_id)');
        $this->addSql('CREATE INDEX IDX_520F7CD519EB6921 ON document_event (client_id)');
        $this->addSql('CREATE INDEX IDX_520F7CD5B191BE6B ON document_event (attempt_id)');
        $this->addSql('CREATE INDEX IDX_520F7CD510DAF24A ON document_event (actor_id)');
        // Constraints that cannot be expressed in the Doctrine mapping, enforced by the database.
        $this->addSql("ALTER TABLE analysis_attempt ADD CONSTRAINT chk_attempt_status CHECK (status IN ('pending', 'running', 'succeeded', 'failed'))");
        $this->addSql('ALTER TABLE analysis_attempt ADD CONSTRAINT chk_attempt_number CHECK (number >= 1)');
        // A failure never carries a result, a success always does, an unfinished attempt has neither.
        $this->addSql(<<<'SQL'
            ALTER TABLE analysis_attempt ADD CONSTRAINT chk_attempt_outcome CHECK (
                (status = 'succeeded' AND result IS NOT NULL AND failure_reason IS NULL AND finished_at IS NOT NULL)
                OR (status = 'failed' AND result IS NULL AND failure_reason IS NOT NULL AND finished_at IS NOT NULL)
                OR (status IN ('pending', 'running') AND result IS NULL AND failure_reason IS NULL AND finished_at IS NULL)
            )
            SQL);
        $this->addSql("ALTER TABLE document_event ADD CONSTRAINT chk_document_event_type CHECK (type IN ('document_received', 'analysis_started', 'analysis_succeeded', 'analysis_failed', 'analysis_retried'))");
        $this->addSql('ALTER TABLE analysis_attempt ADD CONSTRAINT FK_623D6460C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE analysis_attempt ADD CONSTRAINT FK_623D646063C5923F FOREIGN KEY (triggered_by_id) REFERENCES app_user (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document_event ADD CONSTRAINT FK_520F7CD5C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document_event ADD CONSTRAINT FK_520F7CD519EB6921 FOREIGN KEY (client_id) REFERENCES client (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document_event ADD CONSTRAINT FK_520F7CD5B191BE6B FOREIGN KEY (attempt_id) REFERENCES analysis_attempt (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document_event ADD CONSTRAINT FK_520F7CD510DAF24A FOREIGN KEY (actor_id) REFERENCES app_user (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document ADD current_attempt_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A766E2A7719 FOREIGN KEY (current_attempt_id) REFERENCES analysis_attempt (id) NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D8698A766E2A7719 ON document (current_attempt_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analysis_attempt DROP CONSTRAINT FK_623D6460C33F7837');
        $this->addSql('ALTER TABLE analysis_attempt DROP CONSTRAINT FK_623D646063C5923F');
        $this->addSql('ALTER TABLE document_event DROP CONSTRAINT FK_520F7CD5C33F7837');
        $this->addSql('ALTER TABLE document_event DROP CONSTRAINT FK_520F7CD519EB6921');
        $this->addSql('ALTER TABLE document_event DROP CONSTRAINT FK_520F7CD5B191BE6B');
        $this->addSql('ALTER TABLE document_event DROP CONSTRAINT FK_520F7CD510DAF24A');
        $this->addSql('DROP TABLE analysis_attempt');
        $this->addSql('DROP TABLE document_event');
        $this->addSql('ALTER TABLE document DROP CONSTRAINT FK_D8698A766E2A7719');
        $this->addSql('DROP INDEX UNIQ_D8698A766E2A7719');
        $this->addSql('ALTER TABLE document DROP current_attempt_id');
    }
}
