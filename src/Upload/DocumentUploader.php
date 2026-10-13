<?php

declare(strict_types=1);

namespace App\Upload;

use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class DocumentUploader
{
    private const int FILENAME_MAX_LENGTH = 255;

    public function __construct(
        private ValidatorInterface $validator,
        private UploadPolicy $policy,
        private MimeTypeResolver $mimeTypeResolver,
        private EntityManagerInterface $entityManager,
        private FilesystemOperator $documentsStorage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Stores the file then records the document. The client is always the uploader's: it never comes from the request.
     *
     * @throws UploadRejected when the file is refused
     */
    public function upload(UploadedFile $file, User $uploader, ?DocumentType $type): Document
    {
        $client = $uploader->getClient() ?? throw new \LogicException('Only a client account can upload documents.');

        $mimeType = $this->validate($file);

        $path = $file->getPathname();
        $sha256 = hash_file('sha256', $path);
        $size = $file->getSize();
        if (false === $sha256 || false === $size) {
            throw new UploadRejected($this->policy->interruptedMessage());
        }

        $storageKey = \sprintf('%s/%s', $client->getId()->toRfc4122(), Uuid::v7()->toRfc4122());
        $this->store($path, $storageKey);

        // The file is written first: a document must never exist without its file. If the database write
        // fails, the file is removed again. A crash between the two steps can only leave an orphan file.
        try {
            $document = new Document(
                $client,
                $uploader,
                $this->cleanFilename($file->getClientOriginalName()),
                $storageKey,
                $mimeType,
                $size,
                $sha256,
                $type,
            );
            $this->entityManager->persist($document);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->discard($storageKey);

            throw $e;
        }

        return $document;
    }

    /**
     * @return string the detected MIME type
     */
    private function validate(UploadedFile $file): string
    {
        $violations = $this->validator->validate($file, new File(
            maxSize: UploadPolicy::MAX_FILE_SIZE,
            maxSizeMessage: $this->policy->tooLargeMessage(),
            disallowEmptyMessage: $this->policy->emptyFileMessage(),
            uploadIniSizeErrorMessage: $this->policy->tooLargeMessage(),
            uploadFormSizeErrorMessage: $this->policy->tooLargeMessage(),
            uploadPartialErrorMessage: $this->policy->interruptedMessage(),
            uploadNoFileErrorMessage: $this->policy->interruptedMessage(),
            uploadNoTmpDirErrorMessage: $this->policy->interruptedMessage(),
            uploadCantWriteErrorMessage: $this->policy->interruptedMessage(),
            uploadExtensionErrorMessage: $this->policy->interruptedMessage(),
            uploadErrorMessage: $this->policy->interruptedMessage(),
            notFoundMessage: $this->policy->interruptedMessage(),
            notReadableMessage: $this->policy->interruptedMessage(),
        ));
        if (\count($violations) > 0) {
            throw new UploadRejected((string) $violations->get(0)->getMessage());
        }

        // Checked apart from the File constraint: see MimeTypeResolver for the OOXML fallback.
        $mimeType = $this->mimeTypeResolver->resolve($file);
        if (!$this->policy->isAcceptedMimeType($mimeType)) {
            throw new UploadRejected($this->policy->unsupportedFormatMessage());
        }

        return $mimeType;
    }

    private function store(string $path, string $storageKey): void
    {
        $stream = fopen($path, 'rb');
        if (false === $stream) {
            throw new UploadRejected($this->policy->interruptedMessage());
        }

        try {
            $this->documentsStorage->writeStream($storageKey, $stream);
        } finally {
            fclose($stream);
        }
    }

    private function discard(string $storageKey): void
    {
        try {
            $this->documentsStorage->delete($storageKey);
        } catch (FilesystemException $e) {
            $this->logger->error('Could not remove the stored file after a failed database write.', [
                'storageKey' => $storageKey,
                'exception' => $e,
            ]);
        }
    }

    /**
     * The submitted name is display-only (never used in a path): keep the basename, drop control characters.
     */
    private function cleanFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));

        return '' === $name ? 'document' : mb_substr($name, 0, self::FILENAME_MAX_LENGTH);
    }
}
