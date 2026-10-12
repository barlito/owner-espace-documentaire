<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Repository\DocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * File submitted by a client: one accepted file = one document.
 *
 * The client is stored on the document itself (not derived through a join),
 * so isolating clients boils down to a client_id filter.
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Index(name: 'idx_document_client_uploaded_at', columns: ['client_id', 'uploaded_at'])]
#[ORM\Index(name: 'idx_document_client_status', columns: ['client_id', 'status'])]
// Predicate written in PostgreSQL's normalized form, otherwise doctrine:schema:validate reports a difference.
#[ORM\Index(name: 'idx_document_failed', columns: ['status'], options: ['where' => "((status)::text = 'failed'::text)"])]
class Document
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Client $client;

    #[ORM\Column(length: 255)]
    private string $originalFilename;

    /**
     * Unique storage key, independent from the submitted name: two files
     * with the same name can never overwrite each other.
     */
    #[ORM\Column(length: 255, unique: true)]
    private string $storageKey;

    #[ORM\Column(length: 127)]
    private string $mimeType;

    #[ORM\Column]
    private int $size;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $sha256;

    #[ORM\Column(length: 40, nullable: true, enumType: DocumentType::class)]
    private ?DocumentType $type;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $periodStart;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $periodEnd;

    #[ORM\Column(length: 20, enumType: DocumentStatus::class)]
    private DocumentStatus $status = DocumentStatus::Received;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $uploadedAt;

    public function __construct(
        Client $client,
        string $originalFilename,
        string $storageKey,
        string $mimeType,
        int $size,
        string $sha256,
        ?DocumentType $type = null,
        ?\DateTimeImmutable $periodStart = null,
        ?\DateTimeImmutable $periodEnd = null,
    ) {
        if (null !== $periodStart && null !== $periodEnd && $periodEnd < $periodStart) {
            throw new \InvalidArgumentException('The period end cannot precede its start.');
        }

        $this->id = Uuid::v7();
        $this->client = $client;
        $this->originalFilename = $originalFilename;
        $this->storageKey = $storageKey;
        $this->mimeType = $mimeType;
        $this->size = $size;
        $this->sha256 = $sha256;
        $this->type = $type;
        $this->periodStart = $periodStart;
        $this->periodEnd = $periodEnd;
        $this->uploadedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getType(): ?DocumentType
    {
        return $this->type;
    }

    public function getPeriodStart(): ?\DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function getPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function getStatus(): DocumentStatus
    {
        return $this->status;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }
}
