<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventListener;

use App\Enum\DocumentEventType;
use App\Enum\DocumentStatus;
use App\Enum\FailureReason;
use App\EventListener\FailAnalysisOnGivenUpMessageListener;
use App\Message\CheckAnalysisOutcome;
use App\Message\StartAnalysis;
use App\MessageHandler\StartAnalysisHandler;
use App\Tests\Integration\AnalysisTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class FailAnalysisOnGivenUpMessageListenerTest extends AnalysisTestCase
{
    public function testAGivenUpAnalyzeMessageFailsThePendingAttempt(): void
    {
        $document = $this->createDocument();

        $this->giveUp(new StartAnalysis($document->getId(), $document->getCurrentAttempt()->getId()));

        $failed = $this->reload($document);
        $this->assertSame(DocumentStatus::Failed, $failed->getStatus());
        $this->assertSame(FailureReason::Unknown, $failed->getCurrentAttempt()->getFailureReason());
        $this->assertNull($failed->getCurrentAttempt()->getStartedAt());
        $this->assertSame(DocumentEventType::AnalysisFailed, $this->lastEvent($document)->getType());
    }

    public function testAGivenUpCompleteMessageFailsTheProcessingAttempt(): void
    {
        $document = $this->createDocument();
        $handler = self::getContainer()->get(StartAnalysisHandler::class);
        $this->asWorker(static fn () => $handler(new StartAnalysis($document->getId(), $document->getCurrentAttempt()->getId())));

        $this->giveUp(new CheckAnalysisOutcome($document->getId(), $document->getCurrentAttempt()->getId()));

        $failed = $this->reload($document);
        $this->assertSame(DocumentStatus::Failed, $failed->getStatus());
        $this->assertSame(FailureReason::Unknown, $failed->getCurrentAttempt()->getFailureReason());
    }

    public function testNothingHappensWhileMessengerWillStillRetry(): void
    {
        $document = $this->createDocument();

        $this->giveUp(new StartAnalysis($document->getId(), $document->getCurrentAttempt()->getId()), willRetry: true);

        $this->assertSame(DocumentStatus::Pending, $this->reload($document)->getStatus());
    }

    public function testAMessageForAnAttemptThatMovedOnIsIgnored(): void
    {
        $document = $this->createDocument();

        // The attempt is pending, a completion message expects it processing.
        $this->giveUp(new CheckAnalysisOutcome($document->getId(), $document->getCurrentAttempt()->getId()));

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
        self::getContainer()->get(FailAnalysisOnGivenUpMessageListener::class)($event);
        $this->em->clear();
    }
}
