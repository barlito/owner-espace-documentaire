<?php

declare(strict_types=1);

namespace App\DocumentPage;

use App\Entity\Document;
use App\Entity\DocumentEvent;
use App\Enum\DocumentEventType;
use App\Enum\FailureReason;

/**
 * Words a document's events for its client, oldest first. Events store codes, not sentences (see DocumentEventType).
 *
 * The client sees who acted (an intervention names its author) but never technical causes or codes.
 * An admin variant (technical reason, admin name) is another class producing the same TimelineEntry.
 */
final readonly class ClientTimelinePresenter
{
    public function __construct(private ClientFailureWording $failureWording = new ClientFailureWording())
    {
    }

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
        $attemptNumber = $event->getAttempt()?->getNumber() ?? 1;

        return match ($event->getType()) {
            DocumentEventType::DocumentReceived => new TimelineEntry(
                $at,
                'lucide:file-up',
                'Pièce reçue',
                null !== $event->getActor() ? \sprintf('Déposée par %s', $event->getActor()->getFullName()) : null,
            ),
            DocumentEventType::AnalysisStarted => new TimelineEntry($at, 'lucide:loader-circle', 'Analyse démarrée', $attemptNumber > 1 ? \sprintf('Tentative n° %d', $attemptNumber) : null, 'info'),
            DocumentEventType::AnalysisSucceeded => new TimelineEntry($at, 'lucide:circle-check', 'Analyse terminée', null, 'success'),
            DocumentEventType::AnalysisFailed => new TimelineEntry($at, 'lucide:circle-x', 'Échec de l\'analyse', $this->failureCause($event), 'danger'),
            DocumentEventType::AnalysisRetried => new TimelineEntry(
                $at,
                'lucide:rotate-ccw',
                null !== $event->getActor()
                    ? \sprintf('Analyse relancée par %s (équipe OWNER)', $event->getActor()->getFullName())
                    : 'Analyse relancée par l\'équipe OWNER',
            ),
        };
    }

    private function failureCause(DocumentEvent $event): string
    {
        $reason = $event->getPayload()['reason'] ?? null;

        return $this->failureWording->cause(\is_string($reason) ? FailureReason::tryFrom($reason) ?? FailureReason::Unknown : FailureReason::Unknown);
    }
}
