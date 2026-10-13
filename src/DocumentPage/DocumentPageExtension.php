<?php

declare(strict_types=1);

namespace App\DocumentPage;

use App\Entity\Document;
use Twig\Attribute\AsTwigFunction;

/**
 * Exposes the document pages' view data (client and admin) to Twig, so that the page and its live updates
 * (realtime/document_streams.stream.html.twig, rendered by the worker) share the same partials.
 */
final readonly class DocumentPageExtension
{
    public function __construct(
        private ClientTimelinePresenter $timeline,
        private AdminTimelinePresenter $adminTimeline,
        private ClientAnalysisPresenter $analysis,
    ) {
    }

    /**
     * @return list<TimelineEntry>
     */
    #[AsTwigFunction('client_timeline')]
    public function clientTimeline(Document $document): array
    {
        return $this->timeline->present($document);
    }

    /**
     * @return list<TimelineEntry>
     */
    #[AsTwigFunction('admin_timeline')]
    public function adminTimeline(Document $document): array
    {
        return $this->adminTimeline->present($document);
    }

    #[AsTwigFunction('client_analysis')]
    public function clientAnalysis(Document $document): ClientAnalysisView
    {
        return $this->analysis->present($document);
    }

    #[AsTwigFunction('file_kind')]
    public function fileKind(Document $document): FileKind
    {
        return FileKind::fromMimeType($document->getMimeType());
    }
}
