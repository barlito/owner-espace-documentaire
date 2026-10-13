<?php

declare(strict_types=1);

namespace App\DocumentPage;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Enum\DocumentEventType;
use App\Enum\FailureReason;

/**
 * Words a document's events for the OWNER team, oldest first: who did what, which attempt, and the technical cause
 * of a failure. The client-facing counterpart (ClientTimelinePresenter) hides all of this.
 */
final readonly class AdminTimelinePresenter
{
    /**
     * @return list<TimelineEntry>
     */
    public function present(Document $document): array
    {
        return array_values(array_map($this->entry(...), $document->getEvents()->toArray()));
    }

    private function entry(DocumentEvent $event): TimelineEntry
    {
        $at = $event->getOccurredAt();
        $attempt = \sprintf('tentative n° %d', $event->getAttempt()?->getNumber() ?? 1);

        return match ($event->getType()) {
            DocumentEventType::DocumentReceived => new TimelineEntry(
                $at,
                'lucide:file-up',
                'Pièce reçue',
                null !== $event->getActor() ? \sprintf('Déposée par %s', $event->getActor()->getFullName()) : null,
            ),
            DocumentEventType::AnalysisStarted => new TimelineEntry($at, 'lucide:loader-circle', 'Analyse démarrée', ucfirst($attempt), 'info'),
            DocumentEventType::AnalysisSucceeded => new TimelineEntry($at, 'lucide:circle-check', 'Analyse terminée', ucfirst($attempt), 'success'),
            DocumentEventType::AnalysisFailed => new TimelineEntry($at, 'lucide:circle-x', 'Échec de l\'analyse', \sprintf('%s · %s', $this->technicalReason($event), ucfirst($attempt)), 'danger'),
            DocumentEventType::AnalysisRetried => new TimelineEntry(
                $at,
                'lucide:rotate-ccw',
                null !== $event->getActor() ? \sprintf('Analyse relancée par %s (%s)', $event->getActor()->getFullName(), $attempt) : \sprintf('Analyse relancée (%s)', $attempt),
            ),
        };
    }

    private function technicalReason(DocumentEvent $event): string
    {
        $value = $event->getPayload()['reason'] ?? null;
        $reason = \is_string($value) ? FailureReason::tryFrom($value) ?? FailureReason::Unknown : FailureReason::Unknown;

        return \sprintf('%s (%s)', $reason->label(), $reason->value);
    }
}
