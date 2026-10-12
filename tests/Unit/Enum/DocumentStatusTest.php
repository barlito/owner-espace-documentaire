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
        $this->assertSame('Reçue', DocumentStatus::Received->label());
        $this->assertSame('Échec de l\'analyse', DocumentStatus::Failed->label());
    }
}
