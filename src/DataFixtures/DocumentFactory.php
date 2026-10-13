<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\FailureReason;
use League\Flysystem\FilesystemOperator;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Builds fixture documents with a real stored file, and moves them through the analysis lifecycle.
 *
 * Dates come from the Symfony clock: callers freeze it with at() and the lifecycle methods advance it,
 * so that every entity gets a coherent timeline without any setter on the entities.
 */
final class DocumentFactory
{
    private const array MIME_TYPES = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg'];
    private const array MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    private readonly Randomizer $random;

    /** @var array<string, string> */
    private array $images = [];

    public function __construct(private readonly FilesystemOperator $documentsStorage)
    {
        $this->random = new Randomizer(new Mt19937(42));
    }

    public static function monthName(\DateTimeImmutable $date): string
    {
        return self::MONTHS[(int) $date->format('n') - 1];
    }

    /**
     * Freezes the clock: from now on, entities are dated from this instant.
     */
    public function at(\DateTimeImmutable $moment): void
    {
        Clock::set(new MockClock($moment));
    }

    /**
     * Only meaningful on the frozen clock set by at(): a native clock would really sleep.
     */
    public function advance(int $seconds): void
    {
        Clock::get()->sleep($seconds);
    }

    /**
     * Stores the file under the same key format as DocumentUploader, then builds the pending document.
     */
    public function create(Client $client, User $uploader, string $filename, ?DocumentType $type = null, ?\DateTimeImmutable $periodStart = null, ?\DateTimeImmutable $periodEnd = null): Document
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $content = 'pdf' === $extension
            ? SamplePdf::render([$client->getName(), $type?->label() ?? 'Document non catégorisé', $filename])
            : $this->image($extension);

        $storageKey = \sprintf('%s/%s', $client->getId()->toRfc4122(), Uuid::v7()->toRfc4122());
        $this->documentsStorage->write($storageKey, $content);

        return new Document($client, $uploader, $filename, $storageKey, self::MIME_TYPES[$extension], \strlen($content), hash('sha256', $content), $type, $periodStart, $periodEnd);
    }

    public function start(Document $document): void
    {
        $this->advance($this->random->getInt(3, 20));
        $document->startAnalysis();
    }

    public function finish(Document $document, ?FailureReason $failure = null): void
    {
        $this->advance($this->random->getInt(20, 90));

        if (null === $failure) {
            $document->completeAnalysis($this->analysisResult($document));
        } else {
            $document->failAnalysis($failure);
        }
    }

    /**
     * Placeholder payload: the real structure is defined by the analysis itself.
     *
     * @return array<string, mixed>
     */
    private function analysisResult(Document $document): array
    {
        $type = $document->getType() ?? DocumentType::Other;

        return [
            'detectedType' => $type->value,
            'periodStart' => $document->getPeriodStart()?->format('Y-m-d'),
            'periodEnd' => $document->getPeriodEnd()?->format('Y-m-d'),
            'summary' => \sprintf('Document « %s » reconnu : %s.', $document->getOriginalFilename(), mb_strtolower($type->label())),
            'confidence' => $this->random->getInt(80, 99) / 100,
        ];
    }

    private function image(string $extension): string
    {
        return $this->images[$extension] ??= (string) file_get_contents(__DIR__ . '/files/sample.' . $extension);
    }
}
