<?php

declare(strict_types=1);

namespace App\Tests\Integration\Upload;

use App\Analysis\AnalyzeDocument;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentEventType;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Upload\DocumentUploader;
use App\Upload\UploadPolicy;
use App\Upload\UploadRejected;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class DocumentUploaderTest extends KernelTestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures/files/';

    private DocumentUploader $uploader;
    private FilesystemOperator $storage;
    private EntityManagerInterface $em;
    private User $user;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $container = static::getContainer();
        $this->uploader = $container->get(DocumentUploader::class);
        $this->storage = $container->get('documents.storage');
        $this->em = $container->get(EntityManagerInterface::class);

        $client = new Client('Boulangerie Martin');
        $this->user = User::forClient($client, 'paul@boulangerie-martin.test', 'Paul Martin');
        $this->em->persist($client);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->temporaryFiles);
        parent::tearDown();
    }

    public function testAcceptedPdfCreatesADocumentAndStoresTheFile(): void
    {
        $path = self::FIXTURES . 'sample.pdf';

        $document = $this->uploader->upload($this->upload('sample.pdf', 'Bilan 2024.pdf'), $this->user, DocumentType::AnnualAccounts);

        $this->em->clear();
        $stored = $this->em->find(Document::class, $document->getId());
        $this->assertInstanceOf(Document::class, $stored);
        $this->assertSame($this->user->getClient()?->getId()->toRfc4122(), $stored->getClient()->getId()->toRfc4122());
        $this->assertSame('Bilan 2024.pdf', $stored->getOriginalFilename());
        $this->assertSame('application/pdf', $stored->getMimeType());
        $this->assertSame(filesize($path), $stored->getSize());
        $this->assertSame(hash_file('sha256', $path), $stored->getSha256());
        $this->assertSame(DocumentType::AnnualAccounts, $stored->getType());
        $this->assertSame(DocumentStatus::Pending, $stored->getStatus());
        $this->assertSame(1, $stored->getCurrentAttempt()->getNumber());
        $this->assertSame([DocumentEventType::DocumentReceived], array_map(static fn ($event) => $event->getType(), $stored->getEvents()->toArray()));

        $this->assertMatchesRegularExpression('#^' . $stored->getClient()->getId()->toRfc4122() . '/[0-9a-f-]{36}$#', $stored->getStorageKey());
        $this->assertSame(file_get_contents($path), $this->storage->read($stored->getStorageKey()));
    }

    public function testTheAnalysisOfTheFirstAttemptIsQueuedExactlyOnce(): void
    {
        $document = $this->uploader->upload($this->upload('sample.pdf', 'releve.pdf'), $this->user, null);

        $transport = static::getContainer()->get('messenger.transport.async');
        $this->assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = $transport->getSent();
        $this->assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        $this->assertInstanceOf(AnalyzeDocument::class, $message);
        $this->assertTrue($document->getId()->equals($message->documentId));
        $this->assertTrue($document->getCurrentAttempt()->getId()->equals($message->attemptId));
        $this->assertNotNull($document->getCurrentAttempt()->getQueuedAt());
    }

    public function testTheTypeIsOptional(): void
    {
        $document = $this->uploader->upload($this->upload('sample.png', 'scan.png'), $this->user, null);

        $this->assertNull($document->getType());
        $this->assertSame('image/png', $document->getMimeType());
    }

    public function testTwoFilesWithTheSameNameAreTwoDocumentsWithDifferentKeys(): void
    {
        $first = $this->uploader->upload($this->upload('sample.pdf', 'releve.pdf'), $this->user, null);
        $second = $this->uploader->upload($this->upload('sample.pdf', 'releve.pdf'), $this->user, null);

        $this->assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        $this->assertNotSame($first->getStorageKey(), $second->getStorageKey());
        $this->assertTrue($this->storage->fileExists($first->getStorageKey()));
        $this->assertTrue($this->storage->fileExists($second->getStorageKey()));
    }

    public function testTheStoredNameNeverContainsTheSubmittedFilename(): void
    {
        $document = $this->uploader->upload($this->upload('sample.pdf', '../../etc/passwd.pdf'), $this->user, null);

        $this->assertSame('passwd.pdf', $document->getOriginalFilename());
        $this->assertStringNotContainsString('passwd', $document->getStorageKey());
    }

    public function testALongFilenameIsTrimmedTo255Characters(): void
    {
        $document = $this->uploader->upload($this->upload('sample.pdf', str_repeat('é', 300) . '.pdf'), $this->user, null);

        $this->assertSame(255, mb_strlen($document->getOriginalFilename()));
    }

    public function testOfficeAndHeicFilesAreAccepted(): void
    {
        foreach (['sample.docx' => UploadPolicy::DOCX_MIME, 'sample.xlsx' => UploadPolicy::XLSX_MIME, 'sample.heic' => 'image/heic'] as $fixture => $mime) {
            $this->assertSame($mime, $this->uploader->upload($this->upload($fixture, $fixture), $this->user, null)->getMimeType());
        }
    }

    public function testUnsupportedFormatIsRefusedWhateverTheClientDeclares(): void
    {
        $file = new UploadedFile(self::FIXTURES . 'sample.txt', 'facture.pdf', 'application/pdf', null, true);

        $this->assertRejected($file, 'Ce format n\'est pas accepté. Formats acceptés : PDF, JPEG, PNG, HEIC, Word (.docx), Excel (.xlsx).');
    }

    public function testEmptyFileIsRefused(): void
    {
        $this->assertRejected($this->upload($this->temporaryFile(0), 'vide.pdf'), 'Ce fichier est vide.');
    }

    public function testFileOverTheLimitIsRefused(): void
    {
        // Sparse file: exceeds the limit by one byte without writing 20 MB to disk.
        $path = $this->temporaryFile(UploadPolicy::MAX_FILE_SIZE + 1);

        $this->assertRejected($this->upload($path, 'enorme.pdf'), 'Ce fichier dépasse 20 Mo.');
    }

    public function testFileAtTheLimitPassesTheSizeCheck(): void
    {
        $path = $this->temporaryFile(UploadPolicy::MAX_FILE_SIZE);

        // Zeroes are not a PDF: refused for the format, not for the size.
        $this->assertRejected($this->upload($path, 'limite.pdf'), 'Ce format n\'est pas accepté.');
    }

    public function testPhpUploadErrorsGetAUserMessage(): void
    {
        $tooBig = new UploadedFile(self::FIXTURES . 'sample.pdf', 'a.pdf', null, \UPLOAD_ERR_INI_SIZE, true);
        $partial = new UploadedFile(self::FIXTURES . 'sample.pdf', 'b.pdf', null, \UPLOAD_ERR_PARTIAL, true);

        $this->assertRejected($tooBig, 'Ce fichier dépasse 20 Mo.');
        $this->assertRejected($partial, 'L\'envoi de ce fichier a été interrompu, réessayez.');
    }

    public function testNothingIsStoredNorPersistedForARefusedFile(): void
    {
        $before = $this->em->getRepository(Document::class)->count([]);

        try {
            $this->uploader->upload($this->upload('sample.txt', 'note.txt'), $this->user, null);
            $this->fail('The text file should have been refused.');
        } catch (UploadRejected) {
        }

        $this->assertSame($before, $this->em->getRepository(Document::class)->count([]));
        $this->assertSame([], $this->storage->listContents('', true)->toArray());
    }

    /**
     * @param non-empty-string $messageStart
     */
    private function assertRejected(UploadedFile $file, string $messageStart): void
    {
        try {
            $this->uploader->upload($file, $this->user, null);
            $this->fail('The file should have been refused.');
        } catch (UploadRejected $e) {
            $this->assertStringStartsWith($messageStart, $e->getMessage());
        }
    }

    /**
     * @param string $fixtureOrPath fixture name, or absolute path
     */
    private function upload(string $fixtureOrPath, string $clientName): UploadedFile
    {
        $path = str_contains($fixtureOrPath, '/') ? $fixtureOrPath : self::FIXTURES . $fixtureOrPath;

        // test: true skips the is_uploaded_file() check.
        return new UploadedFile($path, $clientName, null, null, true);
    }

    /**
     * @param int<0, max> $size
     */
    private function temporaryFile(int $size): string
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        $this->assertIsString($path);
        $this->temporaryFiles[] = $path;

        $handle = fopen($path, 'wb');
        $this->assertIsResource($handle);
        ftruncate($handle, $size);
        fclose($handle);

        return $path;
    }
}
