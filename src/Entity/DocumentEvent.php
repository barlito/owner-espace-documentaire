<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DocumentEventType;
use App\Repository\DocumentEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * Dated entry of a document timeline. Append-only: read-only for Doctrine and without setters.
 */
#[ORM\Entity(repositoryClass: DocumentEventRepository::class, readOnly: true)]
#[ORM\Index(name: 'idx_document_event_timeline', columns: ['document_id', 'occurred_at'])]
class DocumentEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false)]
    private Document $document;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Client $client;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?AnalysisAttempt $attempt;

    #[ORM\Column(length: 40, enumType: DocumentEventType::class)]
    private DocumentEventType $type;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /**
     * Author of an intervention; null when the event comes from the system.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $actor;

    /**
     * @var array<string, scalar|null>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $payload;

    /**
     * @param array<string, scalar|null> $payload
     */
    public function __construct(
        Document $document,
        DocumentEventType $type,
        ?AnalysisAttempt $attempt = null,
        ?User $actor = null,
        array $payload = [],
    ) {
        $this->id = Uuid::v7();
        $this->document = $document;
        $this->client = $document->getClient();
        $this->attempt = $attempt;
        $this->type = $type;
        $this->actor = $actor;
        $this->payload = $payload;
        $this->occurredAt = now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getAttempt(): ?AnalysisAttempt
    {
        return $this->attempt;
    }

    public function getType(): DocumentEventType
    {
        return $this->type;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
