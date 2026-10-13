<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analysis;

use App\Analysis\AnalysisRetrier;
use App\Analysis\RetryOutcome;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\AttemptStatus;
use App\Enum\DocumentEventType;
use App\Enum\DocumentStatus;
use App\Enum\FailureReason;
use App\Message\StartAnalysis;
use App\Tests\Integration\AnalysisTestCase;
use Symfony\Component\Uid\Uuid;

final class AnalysisRetrierTest extends AnalysisTestCase
{
    private AnalysisRetrier $retrier;
    private Uuid $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->retrier = self::getContainer()->get(AnalysisRetrier::class);
        $admin = User::admin('admin-' . bin2hex(random_bytes(4)) . '@owner.test', 'Sophie Laurent');
        $this->em->persist($admin);
        $this->em->flush();
        $this->adminId = $admin->getId();
    }

    public function testRetryingAFailedDocumentCreatesTheNextAttemptAndQueuesItOnce(): void
    {
        $document = $this->failedDocument();

        $result = $this->retrier->retry($document->getId(), $this->admin());

        $this->assertSame(RetryOutcome::Retried, $result->outcome);
        $this->assertSame(2, $result->attemptNumber);
        $reloaded = $this->reload($document);
        $this->assertSame(DocumentStatus::Pending, $reloaded->getStatus());
        $this->assertCount(2, $reloaded->getAttempts());
        $attempt = $reloaded->getCurrentAttempt();
        $this->assertSame(2, $attempt->getNumber());
        $this->assertSame(AttemptStatus::Pending, $attempt->getStatus());
        $this->assertSame($this->adminId->toRfc4122(), $attempt->getTriggeredBy()?->getId()->toRfc4122());
        $this->assertNotNull($attempt->getQueuedAt(), 'The message is sent with the attempt.');
        $this->assertSame(DocumentEventType::AnalysisRetried, $this->lastEvent($document)->getType());

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        $this->assertInstanceOf(StartAnalysis::class, $message);
        $this->assertTrue($document->getId()->equals($message->documentId));
        $this->assertTrue($attempt->getId()->equals($message->attemptId));
    }

    public function testASecondRetryDoesNothing(): void
    {
        $document = $this->failedDocument();
        $this->retrier->retry($document->getId(), $this->admin());

        $second = $this->retrier->retry($document->getId(), $this->admin());

        $this->assertSame(RetryOutcome::AlreadyHandled, $second->outcome);
        $this->assertNull($second->attemptNumber);
        $this->assertCount(2, $this->reload($document)->getAttempts());
        $this->assertCount(1, $this->sent(), 'No extra message.');
    }

    public function testAnotherProcessThatRetriedFirstIsDetectedEvenWhenTheDocumentIsAlreadyInMemory(): void
    {
        $document = $this->failedDocument();
        // This process holds the document as failed while the database already moved on.
        $inMemory = $this->em->find(Document::class, $document->getId());
        $this->assertSame(DocumentStatus::Failed, $inMemory?->getStatus());
        $this->em->getConnection()->executeStatement("UPDATE document SET status = 'pending' WHERE id = ?", [$document->getId()->toRfc4122()]);

        $result = $this->retrier->retry($document->getId(), $this->admin());

        $this->assertSame(RetryOutcome::AlreadyHandled, $result->outcome);
        $this->assertSame([], $this->sent());
    }

    public function testADocumentThatIsNotFailedIsNotRetried(): void
    {
        $pending = $this->createDocument();
        $processing = $this->createDocument();
        $this->asWorker(fn () => $this->em->find(Document::class, $processing->getId())?->startAnalysis('provider-ref'));

        $this->assertSame(RetryOutcome::AlreadyHandled, $this->retrier->retry($pending->getId(), $this->admin())->outcome);
        $this->assertSame(RetryOutcome::AlreadyHandled, $this->retrier->retry($processing->getId(), $this->admin())->outcome);
        $this->assertCount(1, $this->reload($pending)->getAttempts());
        $this->assertSame([], $this->sent());
    }

    public function testAnUnknownDocumentIsReported(): void
    {
        $this->assertSame(RetryOutcome::NotFound, $this->retrier->retry(Uuid::v7(), $this->admin())->outcome);
        $this->assertSame([], $this->sent());
    }

    /**
     * The administrator as the controller has it: managed by the entity manager that runs the retry.
     */
    private function admin(): User
    {
        return $this->em->find(User::class, $this->adminId) ?? throw new \LogicException('Admin not found.');
    }

    private function failedDocument(): Document
    {
        $document = $this->createDocument();
        $this->asWorker(function () use ($document): void {
            $managed = $this->em->find(Document::class, $document->getId()) ?? throw new \LogicException('Document not found.');
            $managed->startAnalysis('provider-ref');
            $managed->failAnalysis(FailureReason::UnreadableFile);
        });

        return $document;
    }
}
