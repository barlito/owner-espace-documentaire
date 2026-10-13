<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analysis\Simulation;

use App\Analysis\Simulation\ParsedFilename;
use App\Enum\DocumentType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParsedFilenameTest extends TestCase
{
    public function testKeywordsIgnoreCaseAccentsAndSeparators(): void
    {
        $filename = new ParsedFilename('Relevé_ÉCHEC illisible.PDF');

        $this->assertTrue($filename->has('echec'));
        $this->assertTrue($filename->has('illisible'));
        $this->assertFalse($filename->has('lent'));
    }

    public function testKeywordsMatchWholeWordsOnly(): void
    {
        $this->assertFalse(new ParsedFilename('excellent-travail.pdf')->has('lent'));
        $this->assertTrue(new ParsedFilename('analyse-lent.pdf')->has('lent'));
    }

    /**
     * @return iterable<string, array{string, ?DocumentType}>
     */
    public static function typeGuesses(): iterable
    {
        yield 'payslip' => ['Bulletin de salaire - Mars 2026.pdf', DocumentType::Payslip];
        yield 'kbis' => ['kbis_2026.pdf', DocumentType::Kbis];
        yield 'articles' => ['Statuts SAS.pdf', DocumentType::Articles];
        yield 'annual accounts' => ['liasse-fiscale-2025.pdf', DocumentType::AnnualAccounts];
        yield 'childcare before contract' => ['Contrat accueil Léo.pdf', DocumentType::ChildcareRegulation];
        yield 'regulation' => ['reglement-de-fonctionnement.pdf', DocumentType::ChildcareRegulation];
        yield 'employment contract' => ['avenant-contrat.pdf', DocumentType::EmploymentContract];
        yield 'dsn' => ['DSN-2026-02.pdf', DocumentType::Dsn];
        yield 'health insurance' => ['attestation mutuelle.pdf', DocumentType::HealthInsurance];
        yield 'tax notice' => ['Avis d\'impôt 2025.pdf', DocumentType::TaxNotice];
        yield 'unknown' => ['IMG_20260310_1200.jpg', null];
    }

    #[DataProvider('typeGuesses')]
    public function testTypeIsGuessedFromTheName(string $filename, ?DocumentType $expected): void
    {
        $this->assertSame($expected, new ParsedFilename($filename)->guessType());
    }

    /**
     * @return iterable<string, array{string, bool, ?string, ?string}>
     */
    public static function periods(): iterable
    {
        yield 'numeric month' => ['paie-2026-03.pdf', false, '2026-03-01', '2026-03-31'];
        yield 'leap february' => ['paie-2028-02.pdf', false, '2028-02-01', '2028-02-29'];
        yield 'french month' => ['Bulletin Décembre 2025.pdf', false, '2025-12-01', '2025-12-31'];
        yield 'quarter' => ['TVA-T1-2026.pdf', false, '2026-01-01', '2026-03-31'];
        yield 'last quarter' => ['TVA-t4-2025.pdf', false, '2025-10-01', '2025-12-31'];
        yield 'year' => ['bilan-2025.pdf', true, '2025-01-01', '2025-12-31'];
        yield 'no date' => ['scan.pdf', false, null, null];
        yield 'year only is no month' => ['bilan-2025.pdf', false, null, null];
        yield 'invalid month' => ['paie-2026-13.pdf', false, null, null];
    }

    #[DataProvider('periods')]
    public function testPeriodIsParsedFromTheName(string $filename, bool $yearly, ?string $start, ?string $end): void
    {
        $period = new ParsedFilename($filename)->period($yearly);

        $this->assertSame($start, ($period[0] ?? null)?->format('Y-m-d'));
        $this->assertSame($end, ($period[1] ?? null)?->format('Y-m-d'));
    }
}
