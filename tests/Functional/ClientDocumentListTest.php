<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentStatus;
use App\Enum\DocumentType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ClientDocumentListTest extends WebTestCase
{
    use CreatesAccounts;

    private KernelBrowser $browser;
    private User $paul;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->paul = $this->createClientUser();
        $this->browser->loginUser($this->paul);
    }

    public function testTheListOnlyHoldsTheLoggedInClientsDocuments(): void
    {
        $claire = $this->createClientUser('Micro-crèche Les Petits Pas', 'claire@lespetitspas.test');
        $this->document($this->paul, 'Bilan Paul.pdf');
        $this->document($claire, 'Secret Claire.pdf');

        foreach (['', '?q=Secret', '?q=Secret&client=' . $claire->getClient()?->getId(), '?client[]=x&q[]=y'] as $query) {
            $names = $this->names($this->browser->request('GET', '/espace' . $query));

            $this->assertNotContains('Secret Claire.pdf', $names, $query);
        }

        $this->assertSame(['Bilan Paul.pdf'], $this->names($this->browser->request('GET', '/espace')));
    }

    public function testEachRowOpensTheDocumentPageOutsideTheResultsFrame(): void
    {
        $document = $this->document($this->paul, 'Bilan Paul.pdf');

        $link = $this->browser->request('GET', '/espace')->filter('#document-' . $document->getId() . ' a');

        $this->assertSame('/espace/pieces/' . $document->getId(), $link->attr('href'));
        // Without it Turbo would look for the results frame in the document page and show "Content missing".
        $this->assertSame('_top', $link->attr('data-turbo-frame'));
    }

    public function testSearchIgnoresCaseAndAccents(): void
    {
        $this->document($this->paul, 'Relevé bancaire mars.pdf');
        $this->document($this->paul, 'Facture.pdf');

        foreach (['releve', 'RELEVÉ', 'Relevé', 'bancaire MARS', 'RELEVE BANC'] as $term) {
            $this->assertSame(['Relevé bancaire mars.pdf'], $this->names($this->browser->request('GET', '/espace', ['q' => $term])), $term);
        }

        $this->document($this->paul, 'Resume.pdf');
        $this->assertCount(1, $this->names($this->browser->request('GET', '/espace', ['q' => 'résumé'])));
    }

    public function testLikeWildcardsAreTreatedLiterally(): void
    {
        $this->document($this->paul, 'Remise 10%.pdf');
        $this->document($this->paul, 'Remise 100.pdf');
        $this->document($this->paul, 'Note_finale.pdf');
        $this->document($this->paul, 'Note finale.pdf');
        $this->document($this->paul, 'Chemin\\annexe.pdf');

        $this->assertSame(['Remise 10%.pdf'], $this->names($this->browser->request('GET', '/espace', ['q' => '10%'])));
        $this->assertSame(['Remise 10%.pdf'], $this->names($this->browser->request('GET', '/espace', ['q' => '%'])));
        $this->assertSame(['Note_finale.pdf'], $this->names($this->browser->request('GET', '/espace', ['q' => 'Note_'])));
        $this->assertSame(['Chemin\\annexe.pdf'], $this->names($this->browser->request('GET', '/espace', ['q' => 'in\\a'])));
    }

    public function testStatusAndTypeFiltersWorkAloneAndTogether(): void
    {
        $this->document($this->paul, 'a-bilan-ok.pdf', status: DocumentStatus::Analyzed, type: DocumentType::AnnualAccounts);
        $this->document($this->paul, 'b-bilan-ko.pdf', status: DocumentStatus::Failed, type: DocumentType::AnnualAccounts);
        $this->document($this->paul, 'c-paie-ko.pdf', status: DocumentStatus::Failed, type: DocumentType::Payslip);
        $this->document($this->paul, 'd-sans-nature.pdf');

        $names = fn (array $query): array => $this->sorted($this->browser->request('GET', '/espace', $query));

        $this->assertSame(['b-bilan-ko.pdf', 'c-paie-ko.pdf'], $names(['statut' => 'failed']));
        $this->assertSame(['a-bilan-ok.pdf', 'b-bilan-ko.pdf'], $names(['nature' => 'annual_accounts']));
        $this->assertSame(['d-sans-nature.pdf'], $names(['nature' => 'aucune']));
        $this->assertSame(['b-bilan-ko.pdf'], $names(['statut' => 'failed', 'nature' => 'annual_accounts']));
        $this->assertSame(['b-bilan-ko.pdf'], $names(['statut' => 'failed', 'nature' => 'annual_accounts', 'q' => 'BILAN']));
        $this->assertSame([], $names(['statut' => 'analyzed', 'nature' => 'payslip']));
    }

    public function testPeriodFilterMatchesDocumentsWhosePeriodOverlaps(): void
    {
        $this->document($this->paul, 'q1.pdf', period: ['2026-01-01', '2026-03-31']);
        $this->document($this->paul, 'mars.pdf', period: ['2026-03-01', '2026-03-31']);
        $this->document($this->paul, 'avril.pdf', period: ['2026-04-01', '2026-04-30']);
        $this->document($this->paul, 'annee-2025.pdf', period: ['2025-01-01', '2025-12-31']);
        $this->document($this->paul, 'sans-periode.pdf');

        $names = fn (string $period): array => $this->sorted($this->browser->request('GET', '/espace', ['periode' => $period]));

        $this->assertSame(['mars.pdf', 'q1.pdf'], $names('2026-03'));
        $this->assertSame(['q1.pdf'], $names('2026-02'));
        $this->assertSame(['avril.pdf'], $names('2026-04'));
        $this->assertSame(['avril.pdf', 'mars.pdf', 'q1.pdf'], $names('2026'));
        $this->assertSame(['annee-2025.pdf'], $names('2025'));
        $this->assertSame([], $names('2024'));
    }

    public function testPagesHoldTwentyFiveDocumentsMostRecentFirst(): void
    {
        $this->documents(60);

        $first = $this->browser->request('GET', '/espace');
        $this->assertCount(25, $this->names($first));
        $this->assertSame('Pièce 060.pdf', $this->names($first)[0]);
        $this->assertSame('60 pièces', trim($first->filter('main h2 + p')->text()));
        $this->assertSelectorTextContains('main', 'Pièces 1 à 25 sur 60');
        $this->assertCount(1, $first->filter('nav[aria-label=Pagination] [aria-current=page]'));
        $this->assertCount(1, $first->filter('nav[aria-label=Pagination] a[rel=next][href="/espace?page=2"]'));
        $this->assertCount(0, $first->filter('nav[aria-label=Pagination] a[rel=prev]'));

        $second = $this->browser->request('GET', '/espace', ['page' => '2']);
        $this->assertSame('Pièce 035.pdf', $this->names($second)[0]);
        $this->assertCount(25, $this->names($second));
        $this->assertSame('Page 2', trim($second->filter('[aria-current=page]')->text()));
        $this->assertCount(1, $second->filter('a[rel=prev][href="/espace"]'));

        $third = $this->browser->request('GET', '/espace', ['page' => '3']);
        $this->assertCount(10, $this->names($third));
        $this->assertCount(0, $third->filter('a[rel=next]'));
    }

    public function testPaginationKeepsTheFilters(): void
    {
        $this->documents(30, 'Facture');

        $crawler = $this->browser->request('GET', '/espace', ['q' => 'facture', 'statut' => 'pending']);

        $this->assertCount(1, $crawler->filter('a[rel=next][href="/espace?q=facture&statut=pending&page=2"]'));
    }

    public function testAnOutOfRangePageShowsTheLastOne(): void
    {
        $this->documents(30);

        $crawler = $this->browser->request('GET', '/espace', ['page' => '99']);

        $this->assertResponseIsSuccessful();
        $this->assertCount(5, $this->names($crawler));
        $this->assertSame('Page 2', trim($crawler->filter('[aria-current=page]')->text()));
    }

    public function testAPageNumberBeyondAnIntegerIsTheFirstPage(): void
    {
        $this->documents(30);

        $crawler = $this->browser->request('GET', '/espace', ['page' => '999999999999999999999']);

        $this->assertResponseIsSuccessful();
        $this->assertCount(25, $this->names($crawler));
    }

    public function testInvalidParametersAreIgnoredWithoutError(): void
    {
        $this->document($this->paul, 'Bilan.pdf');

        $queries = [
            ['statut' => 'nope', 'nature' => 'nope', 'periode' => '2026-99', 'page' => 'abc'],
            ['statut' => ['a'], 'nature' => ['b'], 'periode' => ['c'], 'q' => ['d'], 'page' => ['2']],
            ['page' => '-3'], ['page' => '0'], ['q' => "\xC3\x28"], ['periode' => "2026\n"],
        ];
        foreach ($queries as $query) {
            $crawler = $this->browser->request('GET', '/espace', $query);

            $this->assertResponseIsSuccessful(json_encode($query) ?: '');
            $this->assertCount(0, $crawler->filter('[aria-label^="Retirer le filtre"]'), 'Invalid values are not shown as active filters.');
        }

        $this->assertSame(['Bilan.pdf'], $this->names($this->browser->request('GET', '/espace', ['statut' => 'nope'])));
    }

    public function testActiveFiltersAreShownAsRemovableChips(): void
    {
        $this->document($this->paul, 'Bilan.pdf', status: DocumentStatus::Failed);

        $crawler = $this->browser->request('GET', '/espace', ['q' => 'bilan', 'statut' => 'failed']);

        $chips = $crawler->filter('[aria-label^="Retirer le filtre"]');
        $this->assertCount(2, $chips);
        $this->assertSame('/espace?statut=failed', $chips->eq(0)->attr('href'));
        $this->assertSame('/espace?q=bilan', $chips->eq(1)->attr('href'));
        $this->assertSelectorTextContains('main', 'Effacer les filtres');
        $this->assertSame('failed', $crawler->filter('select[name=statut] option[selected]')->attr('value'));
        $this->assertSame('bilan', $crawler->filter('input[name=q]')->attr('value'));
    }

    public function testNoResultsIsDistinctFromAnEmptyFolder(): void
    {
        $this->document($this->paul, 'Bilan.pdf');

        $crawler = $this->browser->request('GET', '/espace', ['q' => 'introuvable']);

        $this->assertSelectorTextContains('main', 'Aucune pièce ne correspond');
        $this->assertCount(0, $crawler->filter('#dashboard-empty'));
        $this->assertCount(0, $crawler->filter('li[id^=document-]'));
        $this->assertSelectorTextNotContains('main', 'Votre dossier est encore vide');
    }

    public function testAnEmptyFolderInvitesToUploadWhateverTheFilters(): void
    {
        foreach ([[], ['q' => 'bilan'], ['statut' => 'failed']] as $query) {
            $crawler = $this->browser->request('GET', '/espace', $query);

            $this->assertSelectorTextContains('#dashboard-empty', 'Votre dossier est encore vide');
            $this->assertCount(0, $crawler->filter('form[role=search]'), 'There is nothing to filter yet.');
            $this->assertCount(1, $crawler->filter('ul#dashboard-documents'), 'The first document received live needs a target.');
        }
    }

    public function testTheLiveTargetExistsOnlyOnTheUnfilteredFirstPage(): void
    {
        $this->documents(30);

        $this->assertCount(1, $this->browser->request('GET', '/espace')->filter('ul#dashboard-documents'));
        $this->assertCount(1, $this->browser->request('GET', '/espace', ['page' => '1'])->filter('ul#dashboard-documents'));

        foreach ([['page' => '2'], ['q' => 'pièce'], ['statut' => 'pending'], ['nature' => 'aucune'], ['periode' => '2026']] as $query) {
            $crawler = $this->browser->request('GET', '/espace', $query);

            $this->assertCount(0, $crawler->filter('#dashboard-documents'), json_encode($query) ?: '');
        }
    }

    public function testTheSummaryCountsEveryStatusAndLinksToItsFilter(): void
    {
        $this->document($this->paul, 'a.pdf', status: DocumentStatus::Failed);
        $this->document($this->paul, 'b.pdf', status: DocumentStatus::Failed);
        $this->document($this->paul, 'c.pdf', status: DocumentStatus::Analyzed);

        $crawler = $this->browser->request('GET', '/espace');

        $links = $crawler->filter('nav[aria-label="Pièces par statut"] a');
        $this->assertCount(2, $links, 'A status without document is not offered.');
        $this->assertStringContainsString('2 · Échec de l\'analyse', $links->eq(1)->text());
        $this->assertSame('/espace?statut=failed', $links->eq(1)->attr('href'));
    }

    public function testRowsShowThePeriodWhenThereIsOne(): void
    {
        $this->document($this->paul, 'avec.pdf', period: ['2026-03-01', '2026-03-31']);
        $this->document($this->paul, 'sans.pdf');

        $crawler = $this->browser->request('GET', '/espace');

        $this->assertStringContainsString('Mars 2026', $crawler->filter('li:contains("avec.pdf")')->text());
        $this->assertStringNotContainsString('2026 ·', $crawler->filter('li:contains("sans.pdf")')->text());
    }

    public function testThePageStillHasASingleLiveRegion(): void
    {
        $this->document($this->paul, 'Bilan.pdf');

        $crawler = $this->browser->request('GET', '/espace', ['q' => 'bilan']);

        $this->assertCount(1, $crawler->filter('[aria-live]'));
        $this->assertCount(1, $crawler->filter('turbo-frame#documents-results [data-announcement]'));
    }

    /**
     * @param array{string, string}|null $period
     */
    private function document(User $owner, string $filename, DocumentStatus $status = DocumentStatus::Pending, ?DocumentType $type = null, ?array $period = null, ?\DateTimeImmutable $uploadedAt = null): Document
    {
        // The browser reboots the kernel between requests: entities held by the test are detached from the new manager.
        $owner = $this->em()->find(User::class, $owner->getId()) ?? throw new \LogicException('Account expected.');
        $client = $owner->getClient() ?? throw new \LogicException('Client account expected.');
        $document = new Document(
            $client,
            $owner,
            $filename,
            'key-' . ++$this->sequence . '-' . bin2hex(random_bytes(4)),
            'application/pdf',
            1024,
            str_repeat('a', 64),
            $type,
            null === $period ? null : new \DateTimeImmutable($period[0]),
            null === $period ? null : new \DateTimeImmutable($period[1]),
        );
        $this->em()->persist($document);
        $this->em()->flush();

        // Statuses and dates are not set by hand in production code: write them where the test needs them.
        $this->em()->getConnection()->executeStatement(
            'UPDATE document SET status = :status, uploaded_at = :uploaded_at WHERE id = :id',
            [
                'status' => $status->value,
                'uploaded_at' => ($uploadedAt ?? new \DateTimeImmutable('2026-01-01 12:00:00'))->modify(\sprintf('+%d seconds', $this->sequence))->format('Y-m-d H:i:sP'),
                'id' => $document->getId()->toRfc4122(),
            ],
        );

        return $document;
    }

    private function documents(int $count, string $prefix = 'Pièce'): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $this->document($this->paul, \sprintf('%s %03d.pdf', $prefix, $i));
        }
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('li[id^=document-] p.truncate')->each(static fn (Crawler $node): string => $node->text());
    }

    /**
     * @return list<string>
     */
    private function sorted(Crawler $crawler): array
    {
        $names = $this->names($crawler);
        sort($names);

        return $names;
    }
}
