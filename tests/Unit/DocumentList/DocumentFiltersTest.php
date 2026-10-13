<?php

declare(strict_types=1);

namespace App\Tests\Unit\DocumentList;

use App\DocumentList\DocumentFilters;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use PHPUnit\Framework\TestCase;

final class DocumentFiltersTest extends TestCase
{
    public function testNoParameterMeansNoFilter(): void
    {
        $filters = DocumentFilters::fromArray([]);

        $this->assertFalse($filters->isActive());
        $this->assertSame([], $filters->query());
    }

    public function testValidParametersAreRead(): void
    {
        $filters = DocumentFilters::fromArray(['q' => '  relevé  ', 'statut' => 'failed', 'nature' => 'payslip', 'periode' => '2026-03']);

        $this->assertSame('relevé', $filters->search);
        $this->assertSame(DocumentStatus::Failed, $filters->status);
        $this->assertSame(DocumentType::Payslip, $filters->type);
        $this->assertSame('2026-03', $filters->period?->value);
        $this->assertTrue($filters->isActive());
        $this->assertSame(['q' => 'relevé', 'statut' => 'failed', 'nature' => 'payslip', 'periode' => '2026-03'], $filters->query());
    }

    public function testUncategorizedIsAChoiceOfItsOwn(): void
    {
        $filters = DocumentFilters::fromArray(['nature' => 'aucune']);

        $this->assertTrue($filters->uncategorized);
        $this->assertNull($filters->type);
        $this->assertSame(['nature' => 'aucune'], $filters->query());
    }

    public function testInvalidValuesAreDropped(): void
    {
        $filters = DocumentFilters::fromArray(['statut' => 'unknown', 'nature' => 'nope', 'periode' => '2026-13', 'q' => '   ']);

        $this->assertFalse($filters->isActive());
    }

    public function testNonScalarValuesAreDropped(): void
    {
        $filters = DocumentFilters::fromArray(['q' => ['a'], 'statut' => ['failed'], 'nature' => ['payslip'], 'periode' => ['2026']]);

        $this->assertFalse($filters->isActive());
    }

    public function testInvalidUtf8AndControlCharactersAreNeutralised(): void
    {
        $this->assertNull(DocumentFilters::fromArray(['q' => "\xC3\x28"])->search);
        $this->assertSame('a b', DocumentFilters::fromArray(['q' => "a\x00\nb"])->search);
    }

    public function testSearchIsTruncated(): void
    {
        $filters = DocumentFilters::fromArray(['q' => str_repeat('é', 500)]);

        $this->assertSame(100, mb_strlen((string) $filters->search));
    }

    public function testWithoutRemovesOneFilterOnly(): void
    {
        $filters = DocumentFilters::fromArray(['q' => 'bilan', 'statut' => 'analyzed', 'nature' => 'aucune']);

        $this->assertSame(['statut' => 'analyzed', 'nature' => 'aucune'], $filters->without('q')->query());
        $this->assertSame(['q' => 'bilan', 'nature' => 'aucune'], $filters->without('statut')->query());
        $this->assertSame(['q' => 'bilan', 'statut' => 'analyzed'], $filters->without('nature')->query());
    }

    public function testLikeWildcardsAreEscaped(): void
    {
        $this->assertSame('100\\% \\_ a\\\\b', DocumentFilters::escapeLike('100% _ a\\b'));
    }
}
