<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analysis;

use App\Analysis\AnalysisFailureListener;
use App\Analysis\AnalyzeDocument;
use App\Analysis\AnalyzeDocumentHandler;
use App\Analysis\CompleteAnalysis;
use App\Enum\DocumentEventType;
use App\Enum\DocumentStatus;
use App\Enum\FailureReason;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class AnalysisFailureListenerTest extends AnalysisTestCase
{
    public function testAGivenUpAnalyzeMessageFailsThePendingAttempt(): void
    {
        $document = $this->createDocument();

        $this->giveUp(new AnalyzeDocument($document->getId(), $document->getCurrentAttempt()->getId()));

        $failed = $this->reload($document);
        $this->assertSame(DocumentStatus::Failed, $failed->getStatus());
        $this->assertSame(FailureReason::Unknown, $failed->getCurrentAttempt()->getFailureReason());
        $this->assertNull($failed->getCurrentAttempt()->getStartedAt());
        $this->assertSame(DocumentEventType::AnalysisFailed, $this->lastEvent($document)->getType());
    }

    public function testAGivenUpCompleteMessageFailsTheProcessingAttempt(): void
    {
        $document = $this->createDocument();
        $handler = self::getContainer()->get(AnalyzeDocumentHandler::class);
        $this->asWorker(static fn () => $handler(new AnalyzeDocument($document->getId(), $document->getCurrentAttempt()->getId())));

        $this->giveUp(new CompleteAnalysis($document->getId(), $document->getCurrentAttempt()->getId(), null));

        $failed = $this->reload($document);
        $this->assertSame(DocumentStatus::Failed, $failed->getStatus());
        $this->assertSame(FailureReason::Unknown, $failed->getCurrentAttempt()->getFailureReason());
    }

    public function testNothingHappensWhileMessengerWillStillRetry(): void
    {
        $document = $this->createDocument();

        $this->giveUp(new AnalyzeDocument($document->getId(), $document->getCurrentAttempt()->getId()), willRetry: true);

        $this->assertSame(DocumentStatus::Pending, $this->reload($document)->getStatus());
    }

    public function testAMessageForAnAttemptThatMovedOnIsIgnored(): void
    {
        $document = $this->createDocument();

        // The attempt is pending, a completion message expects it processing.
        $this->giveUp(new CompleteAnalysis($document->getId(), $document->getCurrentAttempt()->getId(), null));

        $this->assertSame(DocumentStatus::Pending, $this->reload($document)->getStatus());
    }

    public function testOtherMessagesAreIgnored(): void
    {
        $document = $this->createDocument();

        $this->giveUp(new \stdClass());

        $this->assertSame(DocumentStatus::Pending, $this->reload($document)->getStatus());
    }

    private function giveUp(object $message, bool $willRetry = false): void
    {
        $event = new WorkerMessageFailedEvent(new Envelope($message), 'async', new \RuntimeException('Handler crashed.'));
        if ($willRetry) {
            $event->setForRetry();
        }

        $this->em->clear();
        self::getContainer()->get(AnalysisFailureListener::class)($event);
        $this->em->clear();
    }
}
