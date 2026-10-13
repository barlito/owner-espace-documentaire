<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\DocumentStatus;
use App\Twig\Components\Badge;
use PHPUnit\Framework\TestCase;

final class DocumentStatusTest extends TestCase
{
    public function testEveryStatusMapsToAnExistingBadgeVariant(): void
    {
        foreach (DocumentStatus::cases() as $status) {
            $badge = new Badge();
            $badge->variant = $status->badgeVariant();

            $this->assertNotSame('', $badge->getClasses(), $status->name);
            $this->assertStringStartsWith('lucide:', $status->icon());
        }
    }

    public function testLabelsAreUserFacingFrench(): void
    {
        $this->assertSame('En attente d\'analyse', DocumentStatus::Pending->label());
        $this->assertSame('Échec de l\'analyse', DocumentStatus::Failed->label());
    }

    public function testEveryStatusHasADistinctAnnouncementForScreenReaders(): void
    {
        $announcements = array_map(static fn (DocumentStatus $status): string => $status->announcement(), DocumentStatus::cases());

        $this->assertCount(\count(DocumentStatus::cases()), array_unique($announcements));
        $this->assertSame('analyse terminée', DocumentStatus::Analyzed->announcement());
        $this->assertSame('échec de l\'analyse', DocumentStatus::Failed->announcement());
    }
}
