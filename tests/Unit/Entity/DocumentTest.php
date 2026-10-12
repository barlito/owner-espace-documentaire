<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Client;
use App\Entity\Document;
use App\Enum\DocumentStatus;
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase
{
    public function testNewDocumentIsReceivedWithUnknownNatureAndPeriod(): void
    {
        $document = new Document(new Client('Client A'), 'scan.pdf', 'key', 'application/pdf', 1024, str_repeat('a', 64));

        $this->assertSame(DocumentStatus::Received, $document->getStatus());
        $this->assertNull($document->getType());
        $this->assertNull($document->getPeriodStart());
    }

    public function testPeriodEndCannotPrecedeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Document(
            new Client('Client A'),
            'bulletin.pdf',
            'key',
            'application/pdf',
            1024,
            str_repeat('a', 64),
            periodStart: new \DateTimeImmutable('2026-03-31'),
            periodEnd: new \DateTimeImmutable('2026-03-01'),
        );
    }
}
