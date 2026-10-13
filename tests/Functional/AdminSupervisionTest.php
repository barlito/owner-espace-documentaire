<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Enum\FailureReason;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

final class AdminSupervisionTest extends WebTestCase
{
    use CreatesAccounts;
    use CreatesDocuments;

    private KernelBrowser $browser;
    private User $paul;
    private User $claire;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot();
        $this->paul = $this->createClientUser('Boulangerie Martin', 'paul@boulangerie-martin.test');
        $this->claire = $this->createClientUser('Micro-crèche Les Petits Pas', 'claire@lespetitspas.test');
    }

    public function testTheToDoListHoldsTheFailedDocumentsOfAllClientsOldestFailureFirst(): void
    {
        $recent = $this->failedDocument($this->paul, 'Recent.pdf', failedAgo: '1 hour');
        $oldest = $this->failedDocument($this->claire, 'Ancien.pdf', FailureReason::UnreadableFile, failedAgo: '3 days');
        $middle = $this->failedDocument($this->paul, 'Milieu.pdf', failedAgo: '1 day');
        $this->storedDocument($this->paul, 'En attente.pdf');
        $analyzed = $this->storedDocument($this->claire, 'Fait.pdf');
        $analyzed->startAnalysis('ref');
        $this->em()->flush();
        $this->browser->loginUser($this->createAdmin());

        $crawler = $this->browser->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['Ancien.pdf', 'Milieu.pdf', 'Recent.pdf'], $this->todoFilenames($crawler));
        $row = $crawler->filter('#admin-todo-' . $oldest->getId());
        $this->assertStringContainsString('Micro-crèche Les Petits Pas', $row->text());
        $this->assertStringContainsString('Le fichier est illisible', $row->text());
        $this->assertStringContainsString('Tentative n° 1', $row->text());
        $this->assertStringContainsString('il y a 3 jours', $row->text());
        $this->assertMatchesRegularExpression('~\(\d{2}/\d{2}/\d{4} à \d{2}:\d{2}\)~', $row->text());
        $this->assertSame(
            \sprintf('/admin/pieces/%s?retour=/admin', $oldest->getId()),
            $row->filter('a')->first()->attr('href'),
        );
        $this->assertCount(1, $crawler->filter('#admin-todo-' . $middle->getId()));
        $this->assertCount(1, $crawler->filter('#admin-todo-' . $recent->getId()));
    }

    public function testTheKpiCardsCountEveryStatusGlobally(): void
    {
        $this->failedDocument($this->paul);
        $this->failedDocument($this->claire);
        $this->storedDocument($this->paul);
        $this->browser->loginUser($this->createAdmin());

        $crawler = $this->browser->request('GET', '/admin');

        $kpis = $crawler->filter('#admin-kpis dl > div')->each(static fn (Crawler $card): string => preg_replace('/\s+/', ' ', trim($card->text())) ?? '');
        $this->assertSame(['En attente 1', 'En cours 0', 'En échec 2', 'Analysées 0'], $kpis);
    }

    public function testNothingToDoShowsAnEmptyStateNotAnEmptyTable(): void
    {
        $this->storedDocument($this->paul);
        $this->browser->loginUser($this->createAdmin());

        $crawler = $this->browser->request('GET', '/admin');

        $this->assertSelectorTextContains('main', 'Aucune analyse en échec');
        $this->assertCount(0, $crawler->filter('#admin-todo-list'));
    }

    public function testTheListCanBeFilteredByClientReasonAndFilename(): void
    {
        $unreadableMartin = $this->failedDocument($this->paul, 'Relevé de banque.pdf', FailureReason::UnreadableFile);
        $this->failedDocument($this->paul, 'Facture.pdf', FailureReason::Unknown);
        $this->failedDocument($this->claire, 'Relevé crèche.pdf', FailureReason::UnreadableFile);
        $this->browser->loginUser($this->createAdmin());
        $paulClientId = $this->paul->getClient()?->getId()->toRfc4122();

        $byClient = $this->browser->request('GET', '/admin', ['client' => $paulClientId]);
        $this->assertEqualsCanonicalizing(['Relevé de banque.pdf', 'Facture.pdf'], $this->todoFilenames($byClient));
        $this->assertCount(1, $byClient->filter('a[aria-label="Retirer le filtre Client : Boulangerie Martin"]'));

        $byReason = $this->browser->request('GET', '/admin', ['raison' => 'unreadable_file']);
        $this->assertCount(2, $byReason->filter('#admin-todo-list > li'));

        $bySearch = $this->browser->request('GET', '/admin', ['q' => 'RELEVE']);
        $this->assertCount(2, $bySearch->filter('#admin-todo-list > li'), 'Search ignores case and accents.');

        $combined = $this->browser->request('GET', '/admin', ['client' => $paulClientId, 'raison' => 'unreadable_file', 'q' => 'releve']);
        $this->assertSame(['Relevé de banque.pdf'], $this->todoFilenames($combined));
        $this->assertCount(1, $combined->filter('#admin-todo-' . $unreadableMartin->getId()));
        $this->assertCount(3, $combined->filter('main a[aria-label^="Retirer le filtre"]'));

        $none = $this->browser->request('GET', '/admin', ['q' => 'introuvable']);
        $this->assertSelectorTextContains('main', 'Aucune analyse en échec ne correspond');
        $this->assertCount(0, $none->filter('#admin-todo-list'));
    }

    public function testTheClientChoicesListOnlyClientsWithFailuresAndMalformedFiltersAreIgnored(): void
    {
        $this->failedDocument($this->paul);
        $this->storedDocument($this->claire);
        $this->browser->loginUser($this->createAdmin());

        $crawler = $this->browser->request('GET', '/admin', ['client' => 'pas-un-uuid', 'raison' => 'inconnue']);

        $this->assertResponseIsSuccessful();
        $options = $crawler->filter('#filter-client option')->each(static fn (Crawler $option): string => trim($option->text()));
        $this->assertSame(['Tous les clients', 'Boulangerie Martin (1)'], $options);
        $this->assertCount(1, $crawler->filter('#admin-todo-list > li'));

        $unknown = $this->browser->request('GET', '/admin', ['client' => Uuid::v7()->toRfc4122()]);
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $unknown->filter('#admin-todo-list > li'), 'A client that does not exist is dropped like any malformed value.');
    }

    public function testAClientWithoutFailureLeftStaysSelectableFromASharedLink(): void
    {
        $this->failedDocument($this->paul);
        $this->browser->loginUser($this->createAdmin());

        $crawler = $this->browser->request('GET', '/admin', ['client' => $this->claire->getClient()?->getId()->toRfc4122()]);

        $this->assertSelectorTextContains('main', 'Aucune analyse en échec ne correspond');
        $this->assertSame('Micro-crèche Les Petits Pas (0)', trim($crawler->filter('#filter-client option[selected]')->text()));
    }

    public function testTheListIsPaginatedBy25(): void
    {
        for ($i = 1; $i <= 26; ++$i) {
            $this->failedDocument($this->paul, \sprintf('Piece-%02d.pdf', $i), failedAgo: (60 - $i) . ' minutes');
        }
        $this->browser->loginUser($this->createAdmin());

        $first = $this->browser->request('GET', '/admin');
        $second = $this->browser->request('GET', '/admin', ['page' => '2']);
        $beyond = $this->browser->request('GET', '/admin', ['page' => '99']);

        $this->assertCount(25, $first->filter('#admin-todo-list > li'));
        $this->assertSame('Piece-01.pdf', $this->todoFilenames($first)[0], 'Oldest failure first.');
        $this->assertSame(['Piece-26.pdf'], $this->todoFilenames($second));
        $this->assertSame(['Piece-26.pdf'], $this->todoFilenames($beyond), 'An out-of-range page shows the last one.');
        $this->assertStringContainsString('Suivant', $first->filter('nav[aria-label=Pagination]')->text());
        $this->assertStringContainsString('Pièces 1 à 25 sur 26', $first->filter('main')->text());
    }

    public function testTheSupervisionPageListensToTheAdminLiveRegionScopeAndHasTheAdminNavigation(): void
    {
        $this->browser->loginUser($this->createAdmin());

        $crawler = $this->browser->request('GET', '/admin');

        $this->assertSame('admin-todo', $crawler->filter('#live-status')->attr('data-live-scope'));
        $this->assertCount(1, $crawler->filter('#admin-todo-notice'));
        $this->assertSame('page', $crawler->filter('nav[aria-label=Administration] a[aria-current]')->attr('aria-current'));
        $this->assertSame('/admin/clients', $crawler->filter('nav[aria-label=Administration]')->selectLink('Clients')->attr('href'));
    }

    public function testTheClientsPageShowsDocumentsPerStatusFailuresFirst(): void
    {
        $this->failedDocument($this->claire);
        $this->failedDocument($this->claire);
        $this->storedDocument($this->paul);
        $this->browser->loginUser($this->createAdmin());

        $crawler = $this->browser->request('GET', '/admin/clients');

        $this->assertResponseIsSuccessful();
        $rows = $crawler->filter('main ul.divide-y > li');
        $this->assertStringContainsString('Micro-crèche Les Petits Pas', $rows->eq(0)->text());
        $this->assertStringContainsString('2 · Échec de l\'analyse', $rows->eq(0)->text());
        $this->assertStringContainsString('2 documents', $rows->eq(0)->text());
        $this->assertStringContainsString('Boulangerie Martin', $rows->eq(1)->text());
        $this->assertStringContainsString('1 · En attente d\'analyse', $rows->eq(1)->text());
        $this->assertStringNotContainsString('Échec', $rows->eq(1)->text());
        $this->assertSame(
            '/admin/clients/' . $this->claire->getClient()?->getId(),
            $rows->eq(0)->selectLink('Micro-crèche Les Petits Pas')->attr('href'),
        );
    }

    public function testTheClientsSearchIgnoresCaseAndAccents(): void
    {
        $this->browser->loginUser($this->createAdmin());

        $crawler = $this->browser->request('GET', '/admin/clients', ['q' => 'MICRO-CRECHE']);

        $this->assertCount(1, $crawler->filter('main ul.divide-y > li'));
        $this->assertSelectorTextContains('main', 'Micro-crèche Les Petits Pas');

        $none = $this->browser->request('GET', '/admin/clients', ['q' => 'zzz']);
        $this->assertSelectorTextContains('main', 'Aucun client ne correspond');
        $this->assertCount(0, $none->filter('main ul.divide-y'));
    }

    public function testTheClientViewOnlyShowsThatClientsDocumentsWithTheMonDossierFilters(): void
    {
        $martinInvoice = $this->storedDocument($this->paul, 'Facture Martin.pdf');
        $martinFailed = $this->failedDocument($this->paul, 'Echec Martin.pdf');
        $this->storedDocument($this->claire, 'Facture Crèche.pdf');
        $this->browser->loginUser($this->createAdmin());
        $path = '/admin/clients/' . $this->paul->getClient()?->getId();

        $crawler = $this->browser->request('GET', $path);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Boulangerie Martin');
        $this->assertSelectorTextNotContains('main', 'Facture Crèche.pdf');
        $this->assertCount(2, $crawler->filter('main li[id^=document-]'));
        $this->assertSame(
            \sprintf('/admin/pieces/%s?retour=%s', $martinInvoice->getId(), $path),
            $crawler->selectLink('Facture Martin.pdf')->attr('href'),
        );

        $failedOnly = $this->browser->request('GET', $path, ['statut' => 'failed']);
        $this->assertCount(1, $failedOnly->filter('main li[id^=document-]'));
        $this->assertCount(1, $failedOnly->filter('#document-' . $martinFailed->getId()));

        $search = $this->browser->request('GET', $path, ['q' => 'FACTURE']);
        $this->assertCount(1, $search->filter('main li[id^=document-]'), 'The search stays inside the client.');
        $this->assertSame($path, $search->filter('form[role=search]')->attr('action'));
    }

    public function testTheClientViewOfAClientWithoutDocumentSaysSo(): void
    {
        $this->browser->loginUser($this->createAdmin());

        $this->browser->request('GET', '/admin/clients/' . $this->paul->getClient()?->getId());

        $this->assertSelectorTextContains('main', 'Ce client n\'a encore déposé aucune pièce');
    }

    public function testAnUnknownOrMalformedClientIsNotFound(): void
    {
        $this->browser->loginUser($this->createAdmin());

        $this->browser->request('GET', '/admin/clients/' . Uuid::v7());
        $this->assertResponseStatusCodeSame(404);

        $this->browser->request('GET', '/admin/clients/pas-un-uuid');
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function adminRoutes(): iterable
    {
        yield 'supervision' => ['GET', '/admin'];
        yield 'clients' => ['GET', '/admin/clients'];
        yield 'client' => ['GET', '/admin/clients/{client}'];
        yield 'document' => ['GET', '/admin/pieces/{document}'];
        yield 'actions' => ['GET', '/admin/pieces/{document}/actions'];
        yield 'file' => ['GET', '/admin/pieces/{document}/fichier'];
        yield 'preview' => ['GET', '/admin/pieces/{document}/apercu'];
        yield 'retry' => ['POST', '/admin/pieces/{document}/relancer'];
    }

    #[DataProvider('adminRoutes')]
    public function testAClientIsForbiddenOnEveryAdminRoute(string $method, string $path): void
    {
        $document = $this->failedDocument($this->paul);
        $this->browser->loginUser($this->paul);

        $this->browser->request($method, $this->fill($path, $document->getId()->toRfc4122()));

        $this->assertResponseStatusCodeSame(403);
    }

    #[DataProvider('adminRoutes')]
    public function testAnonymousVisitorsAreSentToTheLoginOnEveryAdminRoute(string $method, string $path): void
    {
        $document = $this->failedDocument($this->paul);

        $this->browser->request($method, $this->fill($path, $document->getId()->toRfc4122()));

        $this->assertResponseRedirects('http://localhost/connexion');
    }

    public function testNoClientPageLinksToTheBackOffice(): void
    {
        $document = $this->failedDocument($this->paul);
        $this->browser->loginUser($this->paul);

        foreach (['/espace', '/espace/depot', '/espace/pieces/' . $document->getId()] as $path) {
            $html = (string) $this->browser->request('GET', $path)->html();
            $this->assertStringNotContainsString('/admin', $html, $path);
        }
    }

    /**
     * @return list<string>
     */
    private function todoFilenames(Crawler $crawler): array
    {
        return $crawler->filter('#admin-todo-list > li p.truncate a')->each(static fn (Crawler $link): string => $link->text());
    }

    private function fill(string $path, string $documentId): string
    {
        return str_replace(['{document}', '{client}'], [$documentId, (string) $this->paul->getClient()?->getId()], $path);
    }
}
