<?php

declare(strict_types=1);

namespace App\Realtime;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use Twig\Environment;

/**
 * Turns the current state of a document into the Turbo Stream actions that bring every page watching it up to date.
 *
 * Two audiences, two topics, two templates: the client's pages (realtime/document_streams.stream.html.twig, on the
 * client's private topic) and the back office (realtime/admin_streams.stream.html.twig, on the admin topic, with
 * technical details a client must never receive). The same handler publishes both.
 */
final readonly class DocumentStreamRenderer
{
    public function __construct(
        private Environment $twig,
        private DocumentRepository $documents,
    ) {
    }

    public function render(Document $document, bool $created): string
    {
        return $this->twig->render('realtime/document_streams.stream.html.twig', [
            'document' => $document,
            'created' => $created,
        ]);
    }

    /**
     * For the administrators' pages. Carries fresh global counts: one grouped COUNT query per call.
     */
    public function renderForAdmin(Document $document, bool $created): string
    {
        return $this->twig->render('realtime/admin_streams.stream.html.twig', [
            'document' => $document,
            'created' => $created,
            'statusCounts' => $this->documents->countByStatus(),
        ]);
    }
}
