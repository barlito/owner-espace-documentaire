<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\User;
use App\Enum\FailureReason;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;

/**
 * Stored documents in the states the back office cares about. The class using it provides em() (see CreatesAccounts).
 */
trait CreatesDocuments
{
    private const string PDF_BYTES = "%PDF-1.4\nfake pdf content\n";

    abstract private function em(): EntityManagerInterface;

    private function storedDocument(User $uploader, string $filename = 'Bilan.pdf', string $mimeType = 'application/pdf'): Document
    {
        $client = $uploader->getClient() ?? throw new \LogicException('Client account expected.');
        $key = $client->getId()->toRfc4122() . '/' . bin2hex(random_bytes(8));
        $storage = static::getContainer()->get('documents.storage');
        \assert($storage instanceof FilesystemOperator);
        $storage->write($key, self::PDF_BYTES);

        $document = new Document($client, $uploader, $filename, $key, $mimeType, \strlen(self::PDF_BYTES), hash('sha256', self::PDF_BYTES));
        $this->em()->persist($document);
        $this->em()->flush();

        return $document;
    }

    /**
     * @param string $failedAgo relative date the attempt finished at ("2 days"): failures are listed oldest first
     */
    private function failedDocument(User $uploader, string $filename = 'Scan.pdf', FailureReason $reason = FailureReason::Interrupted, string $failedAgo = '1 hour'): Document
    {
        $document = $this->storedDocument($uploader, $filename);
        $document->startAnalysis('ref-' . bin2hex(random_bytes(3)));
        $document->failAnalysis($reason);
        $this->em()->flush();

        $this->em()->getConnection()->executeStatement(
            'UPDATE analysis_attempt SET finished_at = ? WHERE id = ?',
            [new \DateTimeImmutable("-$failedAgo")->format('Y-m-d H:i:sP'), $document->getCurrentAttempt()->getId()->toRfc4122()],
        );
        $this->em()->refresh($document->getCurrentAttempt());

        return $document;
    }
}
