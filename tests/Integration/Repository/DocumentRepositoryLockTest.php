<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Enum\DocumentStatus;
use App\Repository\DocumentRepository;
use App\Tests\Integration\AnalysisTestCase;

final class DocumentRepositoryLockTest extends AnalysisTestCase
{
    public function testADocumentAlreadyInMemoryIsReadAgainUnderTheLock(): void
    {
        $document = $this->createDocument();
        $documentId = $document->getId();
        $attemptId = $document->getCurrentAttempt()->getId();
        $repository = self::getContainer()->get(DocumentRepository::class);

        $found = $this->em->wrapInTransaction(function () use ($repository, $documentId, $attemptId) {
            // The document stays in memory as "pending" while another process moved it on in the database.
            $this->em->getConnection()->executeStatement("UPDATE document SET status = 'failed' WHERE id = ?", [$documentId->toRfc4122()]);

            return [
                'pending' => $repository->findForUpdate($documentId, $attemptId, DocumentStatus::Pending),
                'failed' => $repository->findForUpdate($documentId, $attemptId, DocumentStatus::Failed),
            ];
        });

        $this->assertNull($found['pending']);
        $this->assertSame($document, $found['failed']);
    }
}
