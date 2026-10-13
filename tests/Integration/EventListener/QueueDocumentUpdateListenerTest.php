<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventListener;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\User;
use App\Message\PublishDocumentUpdate;
use App\Tests\Integration\AnalysisTestCase;

final class QueueDocumentUpdateListenerTest extends AnalysisTestCase
{
    public function testAnInsertedDocumentQueuesOneCreationUpdate(): void
    {
        $document = $this->createDocument();

        $publications = $this->publications();
        $this->assertCount(1, $publications);
        $this->assertTrue($document->getId()->equals($publications[0]->documentId));
        $this->assertTrue($publications[0]->created);
    }

    public function testAStatusChangeQueuesOneUpdate(): void
    {
        $document = $this->createDocument();
        $before = \count($this->publications());

        $this->asWorker(function () use ($document): void {
            $this->em->find(Document::class, $document->getId())?->startAnalysis('provider-ref');
        });

        $publications = $this->publications();
        $this->assertCount($before + 1, $publications);
        $last = $publications[$before];
        $this->assertTrue($document->getId()->equals($last->documentId));
        $this->assertFalse($last->created);
    }

    public function testAFlushThatDoesNotChangeTheStatusQueuesNothing(): void
    {
        $document = $this->createDocument();
        $before = \count($this->publications());

        // Only the attempt changes (queued_at): nothing a page shows.
        $this->asWorker(function () use ($document): void {
            $this->em->find(Document::class, $document->getId())?->markAnalysisQueued();
        });

        $this->assertCount($before, $this->publications());
    }

    public function testAnEmptyFlushQueuesNothing(): void
    {
        $this->createDocument();
        $before = \count($this->publications());

        $this->em->flush();

        $this->assertCount($before, $this->publications());
    }

    public function testADocumentInsertedAndChangedInTheSameFlushQueuesOneUpdate(): void
    {
        $client = new Client('Garage Durand');
        $uploader = User::forClient($client, 'durand@garage-durand.test', 'Julien Durand');
        $document = new Document($client, $uploader, 'again.pdf', 'key-' . bin2hex(random_bytes(4)), 'application/pdf', 10, str_repeat('b', 64));
        $document->startAnalysis('provider-ref');
        $before = \count($this->publications());
        $this->em->persist($client);
        $this->em->persist($uploader);
        $this->em->persist($document);
        $this->em->flush();

        $publications = $this->publications();
        $this->assertCount($before + 1, $publications);
        $this->assertTrue($publications[$before]->created);
    }

    public function testEachFlushPublishesItsOwnChangesOnlyOnce(): void
    {
        $document = $this->createDocument();
        $before = \count($this->publications());

        $this->asWorker(function () use ($document): void {
            $this->em->find(Document::class, $document->getId())?->startAnalysis('provider-ref');
        });
        $this->em->flush();

        $this->assertCount($before + 1, $this->publications());
    }

    /**
     * The message must be written while the caller's transaction is still open, so that it is committed or
     * rolled back with the change it announces. (A rollback itself cannot be shown here: the test transport is
     * in memory, only the Doctrine transport in dev and production shares the database transaction.)
     */
    public function testTheUpdateIsQueuedInsideTheCallersTransactionNotAfterItsCommit(): void
    {
        $document = $this->createDocument();
        $this->em->clear();
        $connection = $this->em->getConnection();
        $levelOutside = $connection->getTransactionNestingLevel();
        $before = \count($this->publications());
        $levelWhenQueued = null;

        $this->em->wrapInTransaction(function () use ($document, $connection, $before, &$levelWhenQueued): void {
            $this->em->find(Document::class, $document->getId())?->startAnalysis('provider-ref');
            $this->em->flush();

            if (\count($this->publications()) > $before) {
                $levelWhenQueued = $connection->getTransactionNestingLevel();
            }
        });

        $this->assertSame($levelOutside + 1, $levelWhenQueued, 'Queued after the flush, before the outer commit.');
    }

    public function testClearingTheEntityManagerForgetsWhatWasCollected(): void
    {
        $document = $this->createDocument();
        $this->assertCount(1, $this->publications());

        $this->em->clear();
        $this->em->flush();

        $this->assertCount(1, $this->publications());
        $this->assertContainsOnlyInstancesOf(PublishDocumentUpdate::class, $this->publications());
        $this->assertTrue($document->getId()->equals($this->publications()[0]->documentId));
    }
}
