<?php

declare(strict_types=1);

namespace App\DocumentPage;

use App\Entity\Document;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\String\UnicodeString;

/**
 * Streams a stored file to its owner, as a download or for display in the page.
 *
 * Callers must have checked access first. The response never relies on the extension: it sends the MIME type
 * detected at upload, with nosniff, and is private so that no shared cache keeps a client's document.
 */
final readonly class DocumentFileResponder
{
    public function __construct(
        private FilesystemOperator $documentsStorage,
        private LoggerInterface $logger,
    ) {
    }

    public function download(Document $document): Response
    {
        return $this->stream($document, HeaderUtils::DISPOSITION_ATTACHMENT);
    }

    /**
     * @throws NotFoundHttpException when the format cannot be displayed in a page
     */
    public function preview(Document $document): Response
    {
        $kind = FileKind::fromMimeType($document->getMimeType());
        if (!$kind->isPreviewable()) {
            throw new NotFoundHttpException('This format has no preview.');
        }

        $response = $this->stream($document, HeaderUtils::DISPOSITION_INLINE);
        // Only our own pages may frame it (the global default is DENY).
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        // A PDF is rendered by the browser's PDF viewer, which a sandboxed frame blocks: it relies on the
        // detected MIME type and nosniff instead. Images, which the browser decodes as pictures, are sandboxed.
        $response->headers->set('Content-Security-Policy', FileKind::Pdf === $kind ? "frame-ancestors 'self'" : "sandbox; frame-ancestors 'self'");

        return $response;
    }

    private function stream(Document $document, string $disposition): Response
    {
        $key = $document->getStorageKey();

        try {
            if (!$this->documentsStorage->fileExists($key)) {
                throw new \RuntimeException('Stored file is missing.');
            }
            $length = $this->documentsStorage->fileSize($key);
            $stream = $this->documentsStorage->readStream($key);
        } catch (FilesystemException | \RuntimeException $e) {
            // The database says the file exists: this is an integrity problem worth an alert, not a client error.
            $this->logger->error('Stored file of a document cannot be read.', ['document' => $document->getId()->toRfc4122(), 'exception' => $e]);

            throw new NotFoundHttpException('The stored file is unavailable.', $e);
        }

        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->set('Content-Type', $document->getMimeType());
        $response->headers->set('Content-Length', (string) $length);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $disposition,
            $this->withoutPathSeparators($document->getOriginalFilename()),
            $this->asciiFallback($document->getOriginalFilename()),
        ));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPrivate();

        return $response;
    }

    /**
     * A header filename cannot hold a path separator (HeaderUtils refuses it); the name displayed on the page keeps it.
     */
    private function withoutPathSeparators(string $filename): string
    {
        return preg_replace('/[\\\\\/\x00-\x1F\x7F]/', '_', $filename) ?? 'document';
    }

    /**
     * ASCII-only name for clients that ignore the UTF-8 "filename*" parameter: accents are dropped, anything else unsafe becomes "_".
     */
    private function asciiFallback(string $filename): string
    {
        $ascii = new UnicodeString($filename)->ascii()->toString();
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', $ascii) ?? 'document';

        return '' === trim($safe, '._ ') ? 'document' : $safe;
    }
}
