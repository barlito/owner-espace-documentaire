<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AttemptStatus;
use App\Enum\FailureReason;
use App\Repository\AnalysisAttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * One analysis run of a document. A retry creates a new attempt and never rewrites a previous one.
 *
 * The result is stored on the attempt itself: it is structurally bound to the attempt that produced it.
 * Database constraints guarantee that a failed attempt never carries a result.
 */
#[ORM\Entity(repositoryClass: AnalysisAttemptRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_attempt_document_number', columns: ['document_id', 'number'])]
// At most one unfinished attempt per document: repeated retries cannot run concurrently.
// "Unfinished" is expressed as finished_at IS NULL, which chk_attempt_outcome ties to pending/running.
#[ORM\UniqueConstraint(name: 'uniq_attempt_document_unfinished', columns: ['document_id'], options: ['where' => '(finished_at IS NULL)'])]
class AnalysisAttempt
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'attempts')]
    #[ORM\JoinColumn(nullable: false)]
    private Document $document;

    #[ORM\Column]
    private int $number;

    #[ORM\Column(length: 20, enumType: AttemptStatus::class)]
    private AttemptStatus $status = AttemptStatus::Pending;

    /**
     * Admin who requested a retry; null when the analysis was triggered automatically on upload.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $triggeredBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    /**
     * When the analysis message was sent. Null only for an attempt whose message was never sent (demo fixtures,
     * data from before the queue existed): the reconciliation queues those. A sent message cannot be lost, since it is
     * written in the same transaction as the attempt; a message Messenger gives up on fails the attempt instead
     * (see FailAnalysisOnGivenUpMessageListener).
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $queuedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $result = null;

    #[ORM\Column(length: 30, nullable: true, enumType: FailureReason::class)]
    private ?FailureReason $failureReason = null;

    public function __construct(Document $document, int $number, ?User $triggeredBy = null)
    {
        $this->id = Uuid::v7();
        $this->document = $document;
        $this->number = $number;
        $this->triggeredBy = $triggeredBy;
        $this->requestedAt = now();
    }

    /**
     * @internal only Document may drive an attempt, so that status and timeline stay in sync
     */
    public function markQueued(): void
    {
        $this->assertStatus(AttemptStatus::Pending);
        $this->queuedAt = now();
    }

    /**
     * @internal only Document may drive an attempt, so that status and timeline stay in sync
     */
    public function start(): void
    {
        $this->assertStatus(AttemptStatus::Pending);
        $this->status = AttemptStatus::Running;
        $this->startedAt = now();
    }

    /**
     * @internal only Document may drive an attempt, so that status and timeline stay in sync
     *
     * @param array<string, mixed> $result
     */
    public function succeed(array $result): void
    {
        $this->assertStatus(AttemptStatus::Running);
        $this->status = AttemptStatus::Succeeded;
        $this->result = $result;
        $this->finishedAt = now();
    }

    /**
     * @internal only Document may drive an attempt, so that status and timeline stay in sync
     */
    public function fail(FailureReason $reason): void
    {
        // An attempt can also be given up before it ever started (its message never got through).
        if (AttemptStatus::Pending !== $this->status) {
            $this->assertStatus(AttemptStatus::Running);
        }
        $this->status = AttemptStatus::Failed;
        $this->failureReason = $reason;
        $this->finishedAt = now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getStatus(): AttemptStatus
    {
        return $this->status;
    }

    public function getTriggeredBy(): ?User
    {
        return $this->triggeredBy;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getQueuedAt(): ?\DateTimeImmutable
    {
        return $this->queuedAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResult(): ?array
    {
        return $this->result;
    }

    public function getFailureReason(): ?FailureReason
    {
        return $this->failureReason;
    }

    private function assertStatus(AttemptStatus $expected): void
    {
        if ($this->status !== $expected) {
            throw new \LogicException(\sprintf('Attempt #%d is "%s", expected "%s".', $this->number, $this->status->value, $expected->value));
        }
    }
}
