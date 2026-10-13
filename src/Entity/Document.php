<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DocumentEventType;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use App\Enum\FailureReason;
use App\Exception\InvalidDocumentTransition;
use App\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * File submitted by a client: one accepted file = one document.
 *
 * The client is stored on the document itself (not derived through a join),
 * so isolating clients boils down to a client_id filter.
 *
 * Every status change goes through a transition method that updates the current attempt
 * and appends the matching timeline event, so status, attempts and history never diverge.
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
    private DocumentStatus $status = DocumentStatus::Pending;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $uploadedAt;

    /**
     * Always set once constructed; nullable only so the document can be inserted before its first attempt.
     */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?AnalysisAttempt $currentAttempt = null;

    /**
     * @var Collection<int, AnalysisAttempt>
     */
    #[ORM\OneToMany(targetEntity: AnalysisAttempt::class, mappedBy: 'document', cascade: ['persist'])]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $attempts;

    /**
     * @var Collection<int, DocumentEvent>
     */
    #[ORM\OneToMany(targetEntity: DocumentEvent::class, mappedBy: 'document', cascade: ['persist'])]
    #[ORM\OrderBy(['occurredAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $events;

    public function __construct(
        Client $client,
        User $uploadedBy,
        string $originalFilename,
        string $storageKey,
        string $mimeType,
        int $size,
        string $sha256,
        ?DocumentType $type = null,
        ?\DateTimeImmutable $periodStart = null,
        ?\DateTimeImmutable $periodEnd = null,
    ) {
        if ($uploadedBy->getClient() !== $client) {
            throw new \InvalidArgumentException('A document can only be uploaded by an account of its client.');
        }

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
        $this->uploadedAt = now();
        $this->attempts = new ArrayCollection();
        $this->events = new ArrayCollection();

        $this->record(DocumentEventType::DocumentReceived, actor: $uploadedBy);
        $this->currentAttempt = $this->newAttempt();
    }

    public function startAnalysis(): AnalysisAttempt
    {
        $this->assertStatus('startAnalysis', DocumentStatus::Pending);

        $this->getCurrentAttempt()->start();
        $this->status = DocumentStatus::Processing;
        $this->record(DocumentEventType::AnalysisStarted, $this->getCurrentAttempt());

        return $this->getCurrentAttempt();
    }

    /**
     * @param array<string, mixed> $result
     */
    public function completeAnalysis(array $result): void
    {
        $this->assertStatus('completeAnalysis', DocumentStatus::Processing);

        $this->getCurrentAttempt()->succeed($result);
        $this->status = DocumentStatus::Analyzed;
        $this->record(DocumentEventType::AnalysisSucceeded, $this->getCurrentAttempt());
    }

    public function failAnalysis(FailureReason $reason): void
    {
        $this->assertStatus('failAnalysis', DocumentStatus::Processing);

        $this->getCurrentAttempt()->fail($reason);
        $this->status = DocumentStatus::Failed;
        $this->record(DocumentEventType::AnalysisFailed, $this->getCurrentAttempt(), payload: ['reason' => $reason->value]);
    }

    /**
     * Same document, same client: a retry only adds a new attempt.
     */
    public function retryAnalysis(User $admin): AnalysisAttempt
    {
        if (!$admin->isAdmin()) {
            throw new \InvalidArgumentException('Only an administrator can retry an analysis.');
        }

        $this->assertStatus('retryAnalysis', DocumentStatus::Failed);

        $attempt = $this->newAttempt($admin);
        $this->currentAttempt = $attempt;
        $this->status = DocumentStatus::Pending;
        $this->record(DocumentEventType::AnalysisRetried, $attempt, $admin);

        return $attempt;
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

    public function getCurrentAttempt(): AnalysisAttempt
    {
        return $this->currentAttempt ?? throw new \LogicException('A document always has a current attempt once constructed.');
    }

    /**
     * @return Collection<int, AnalysisAttempt>
     */
    public function getAttempts(): Collection
    {
        return $this->attempts;
    }

    /**
     * @return Collection<int, DocumentEvent>
     */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    private function newAttempt(?User $triggeredBy = null): AnalysisAttempt
    {
        $number = ($this->currentAttempt?->getNumber() ?? 0) + 1;
        $attempt = new AnalysisAttempt($this, $number, $triggeredBy);
        $this->attempts->add($attempt);

        return $attempt;
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function record(DocumentEventType $type, ?AnalysisAttempt $attempt = null, ?User $actor = null, array $payload = []): void
    {
        $this->events->add(new DocumentEvent($this, $type, $attempt, $actor, $payload));
    }

    private function assertStatus(string $action, DocumentStatus $expected): void
    {
        if ($this->status !== $expected) {
            throw new InvalidDocumentTransition($action, $this->status);
        }
    }
}
