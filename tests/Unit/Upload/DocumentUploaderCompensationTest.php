<?php

declare(strict_types=1);

namespace App\Tests\Unit\Upload;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\User;
use App\Upload\DocumentUploader;
use App\Upload\MimeTypeResolver;
use App\Upload\UploadPolicy;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Validation;

final class DocumentUploaderCompensationTest extends TestCase
{
    public function testTheStoredFileIsDeletedAndTheErrorRethrownWhenTheDatabaseWriteFails(): void
    {
        $failure = new \RuntimeException('database down');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist')->with($this->isInstanceOf(Document::class));
        $entityManager->expects($this->once())->method('flush')->willThrowException($failure);

        $storage = new Filesystem(new InMemoryFilesystemAdapter());
        $uploader = new DocumentUploader(
            Validation::createValidator(),
            new UploadPolicy(),
            new MimeTypeResolver(),
            $entityManager,
            $storage,
            new NullLogger(),
        );

        $client = new Client('Boulangerie Martin');
        $user = User::forClient($client, 'paul@boulangerie-martin.test', 'Paul Martin');
        $file = new UploadedFile(__DIR__ . '/../../Fixtures/files/sample.pdf', 'bilan.pdf', null, null, true);

        try {
            $uploader->upload($file, $user, null);
            $this->fail('The database failure should have been rethrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame($failure, $e);
        }

        $this->assertSame([], $storage->listContents('', true)->toArray(), 'No orphan file must remain.');
    }
}
