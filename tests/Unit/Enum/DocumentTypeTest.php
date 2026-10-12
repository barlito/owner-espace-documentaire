<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\DocumentFamily;
use App\Enum\DocumentType;
use PHPUnit\Framework\TestCase;

final class DocumentTypeTest extends TestCase
{
    public function testEveryTypeHasALabelAndAFamily(): void
    {
        foreach (DocumentType::cases() as $type) {
            $this->assertNotSame('', $type->label(), $type->name);
            $this->assertNotSame('', $type->family()->label(), $type->name);
        }
    }

    public function testEveryFamilyHasAtLeastOneType(): void
    {
        $families = array_map(static fn (DocumentType $type): DocumentFamily => $type->family(), DocumentType::cases());

        foreach (DocumentFamily::cases() as $family) {
            $this->assertContains($family, $families, $family->name);
        }
    }

    public function testOtherDocumentBelongsToOtherFamily(): void
    {
        $this->assertSame(DocumentFamily::Other, DocumentType::Other->family());
        $this->assertSame(DocumentFamily::Payroll, DocumentType::Payslip->family());
    }
}
