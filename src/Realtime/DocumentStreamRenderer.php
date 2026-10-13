<?php

declare(strict_types=1);

namespace App\Realtime;

use App\Entity\Document;
use Twig\Environment;

/**
 * Turns the current state of a document into the Turbo Stream actions that bring every page watching it up to date.
 *
 * Extension point: each page that must follow documents live contributes its own actions to
 * realtime/document_streams.stream.html.twig (the admin back office and the document page will add theirs there,
 * published on their own topics by the same handler).
 */
final readonly class DocumentStreamRenderer
{
    public function __construct(private Environment $twig)
    {
    }

    public function render(Document $document, bool $created): string
    {
        return $this->twig->render('realtime/document_streams.stream.html.twig', [
            'document' => $document,
            'created' => $created,
        ]);
    }
}
