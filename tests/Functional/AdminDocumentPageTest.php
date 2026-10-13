<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentStatus;
use App\Enum\FailureReason;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

final class AdminDocumentPageTest extends WebTestCase
{
    use CreatesAccounts;
    use CreatesDocuments;

    private KernelBrowser $browser;
    private User $paul;
    private User $admin;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        // The in-memory test storage and transport must survive from the fixture set-up to the requests.
        $this->browser->disableReboot();
        $this->paul = $this->createClientUser('Boulangerie Martin', 'paul@boulangerie-martin.test');
        $this->admin = $this->createAdmin();
    }

    public function testTheAdminPageShowsTheClientTheStatusAndTheTechnicalReason(): void
    {
        $document = $this->failedDocument($this->paul, 'Relevé.pdf', FailureReason::UnsupportedFormat);
        $this->browser->loginUser($this->admin);

        $crawler = $this->browser->request('GET', $this->path($document));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Relevé.pdf');
        $this->assertSame('/admin/clients/' . $this->paul->getClient()?->getId(), $crawler->selectLink('Boulangerie Martin')->attr('href'));
        $this->assertSame($this->path($document) . '/fichier', $crawler->selectLink('Télécharger')->attr('href'));
        $status = $crawler->filter('#admin-document-status-' . $document->getId());
        $this->assertStringContainsString('Échec de l\'analyse', $status->text());
        $this->assertStringContainsString('Le contenu de ce fichier ne peut pas être analysé automatiquement', $status->text());
        $this->assertStringContainsString('unsupported_format', $status->text());
        $this->assertSame('document-' . $document->getId(), $crawler->filter('#live-status')->attr('data-live-scope'));
        $this->assertSame('/admin', $crawler->selectLink('Retour à la supervision')->attr('href'));
    }

    public function testTheAttemptsTableListsEveryAttemptWithWhoTriggeredIt(): void
    {
        $document = $this->failedDocument($this->paul, 'Relevé.pdf', FailureReason::Interrupted);
        $document->retryAnalysis($this->admin);
        $document->startAnalysis('provider-ref-2');
        $document->failAnalysis(FailureReason::UnreadableFile);
        $this->em()->flush();
        $this->browser->loginUser($this->admin);

        $crawler = $this->browser->request('GET', $this->path($document));

        $attempts = $crawler->filter('#admin-document-attempts-' . $document->getId() . ' li[data-attempt]');
        $this->assertCount(2, $attempts);
        $latest = $attempts->eq(0);
        $this->assertSame('Tentative n° 2 (actuelle)', $this->squash($latest->filter('h3')->text()));
        $this->assertSame('Sophie Laurent', $latest->filter('[data-triggered-by]')->text());
        $this->assertStringContainsString('provider-ref-2', $latest->text());
        $this->assertStringContainsString('Échouée', $latest->text());
        $this->assertStringContainsString('unreadable_file', $latest->text());
        $this->assertStringContainsString('Durée', $latest->text());
        $first = $attempts->eq(1);
        $this->assertSame('Dépôt', $first->filter('[data-triggered-by]')->text());
        $this->assertStringContainsString('interrupted', $first->text());
    }

    public function testTheAdminTimelineNamesTheAdministratorAndTheTechnicalCause(): void
    {
        $document = $this->failedDocument($this->paul, 'Relevé.pdf', FailureReason::Interrupted);
        $document->retryAnalysis($this->admin);
        $this->em()->flush();
        $this->browser->loginUser($this->admin);

        $crawler = $this->browser->request('GET', $this->path($document));

        $entries = $crawler->filter('#admin-document-timeline-' . $document->getId() . ' ol > li')->each(static fn (Crawler $item): string => preg_replace('/\s+/', ' ', trim($item->filter('div')->text())) ?? '');
        $this->assertCount(4, $entries);
        $this->assertStringContainsString('Pièce reçue Déposée par Paul Martin', $entries[0]);
        $this->assertStringContainsString('Analyse démarrée Tentative n° 1', $entries[1]);
        $this->assertStringContainsString('Échec de l\'analyse Le traitement a été interrompu avant la fin (interrupted) · Tentative n° 1', $entries[2]);
        $this->assertStringContainsString('Analyse relancée par Sophie Laurent (tentative n° 2)', $entries[3]);
    }

    public function testTheClientPageOfTheSameRetriedDocumentNeverShowsTheAdministrator(): void
    {
        $document = $this->failedDocument($this->paul, 'Relevé.pdf', FailureReason::Interrupted);
        $document->retryAnalysis($this->admin);
        $this->em()->flush();
        $this->browser->loginUser($this->paul);

        $crawler = $this->browser->request('GET', '/espace/pieces/' . $document->getId());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Analyse relancée par Sophie Laurent (équipe OWNER)', $crawler->filter('#document-page-timeline-' . $document->getId())->text());
        $content = (string) $this->browser->getResponse()->getContent();
        $this->assertStringNotContainsString('interrupted', $content);
        $this->assertStringNotContainsString('admin-document-', $content);
    }

    public function testRetryingCreatesANewAttemptQueuesItAndComesBackToThePage(): void
    {
        $document = $this->failedDocument($this->paul);
        $this->browser->loginUser($this->admin);
        $form = $this->browser->request('GET', $this->path($document))->selectButton('Relancer l\'analyse')->form();

        $this->browser->submit($form);

        $this->assertResponseRedirects($this->path($document));
        $crawler = $this->browser->followRedirect();
        $this->assertStringContainsString('Analyse relancée (tentative n° 2).', $crawler->filter('main')->text());
        $reloaded = $this->reload($document);
        $this->assertSame(DocumentStatus::Pending, $reloaded->getStatus());
        $this->assertCount(2, $reloaded->getAttempts());
        $this->assertSame($this->admin->getId()->toRfc4122(), $reloaded->getCurrentAttempt()->getTriggeredBy()?->getId()->toRfc4122());
        $this->assertNotNull($reloaded->getCurrentAttempt()->getQueuedAt(), 'The analysis message is sent with the attempt.');
        $this->assertCount(0, $crawler->selectButton('Relancer l\'analyse'), 'Nothing left to retry.');
    }

    public function testASecondPostIsReportedAsAlreadyHandledAndAddsNothing(): void
    {
        $document = $this->failedDocument($this->paul);
        $this->browser->loginUser($this->admin);
        $form = $this->browser->request('GET', $this->path($document))->selectButton('Relancer l\'analyse')->form();

        $this->browser->submit($form);
        $this->browser->followRedirect();
        $this->browser->submit($form);

        $this->assertResponseRedirects($this->path($document));
        $crawler = $this->browser->followRedirect();
        $this->assertStringContainsString('Relance déjà prise en compte.', $crawler->filter('main')->text());
        $this->assertStringNotContainsString('Analyse relancée (tentative', $crawler->filter('main')->text());
        $this->assertCount(2, $this->reload($document)->getAttempts());
    }

    public function testRetryingADocumentAnotherAdministratorAlreadyRetriedDoesNothing(): void
    {
        $document = $this->failedDocument($this->paul);
        $colleague = User::admin('marc@owner.test', 'Marc Durand');
        $this->em()->persist($colleague);
        $this->em()->flush();
        $this->browser->loginUser($this->admin);
        // This administrator's page was loaded while the document was failed...
        $token = $this->tokenFor($document);
        // ...and the colleague retried it in the meantime.
        $document->retryAnalysis($colleague);
        $this->em()->flush();

        $this->browser->request('POST', $this->path($document) . '/relancer', ['_token' => $token]);

        $this->assertResponseRedirects($this->path($document));
        $this->assertStringContainsString('Relance déjà prise en compte.', $this->browser->followRedirect()->filter('main')->text());
        $this->assertCount(2, $this->reload($document)->getAttempts());
    }

    public function testRetryRequiresAValidCsrfToken(): void
    {
        $document = $this->failedDocument($this->paul);
        $other = $this->failedDocument($this->paul, 'Autre.pdf');
        $this->browser->loginUser($this->admin);
        $otherToken = $this->tokenFor($other);

        $this->browser->request('POST', $this->path($document) . '/relancer');
        $this->assertResponseStatusCodeSame(403, 'No token.');

        $this->browser->request('POST', $this->path($document) . '/relancer', ['_token' => 'forged']);
        $this->assertResponseStatusCodeSame(403, 'Invalid token.');

        $this->browser->request('POST', $this->path($document) . '/relancer', ['_token' => $otherToken]);
        $this->assertResponseStatusCodeSame(403, 'The token of another document.');

        $this->browser->request('POST', $this->path($document) . '/relancer', ['_token' => ['array']]);
        $this->assertResponseStatusCodeSame(403, 'Not a string.');

        $this->assertCount(1, $this->reload($document)->getAttempts());
    }

    public function testRetryIsAPostOnlyAction(): void
    {
        $document = $this->failedDocument($this->paul);
        $this->browser->loginUser($this->admin);

        $this->browser->request('GET', $this->path($document) . '/relancer');

        $this->assertResponseStatusCodeSame(405);
    }

    public function testAnUnknownOrMalformedDocumentIsNotFound(): void
    {
        $this->browser->loginUser($this->admin);

        foreach ([Uuid::v7()->toRfc4122(), 'pas-un-uuid'] as $id) {
            foreach (['', '/actions', '/fichier', '/apercu'] as $suffix) {
                $this->browser->request('GET', '/admin/pieces/' . $id . $suffix);
                $this->assertResponseStatusCodeSame(404, $id . $suffix);
            }
            $this->browser->request('POST', '/admin/pieces/' . $id . '/relancer', ['_token' => 'x']);
            $this->assertResponseStatusCodeSame(404, $id . ' retry');
        }
    }

    public function testAnUnreadableFileWarnsThatARetryWillProbablyFail(): void
    {
        $unreadable = $this->failedDocument($this->paul, 'Flou.pdf', FailureReason::UnreadableFile);
        $interrupted = $this->failedDocument($this->paul, 'Coupé.pdf', FailureReason::Interrupted);
        $this->browser->loginUser($this->admin);

        $warning = $this->browser->request('GET', $this->path($unreadable));
        $this->assertStringContainsString(
            'Le fichier est illisible : une relance échouera probablement. Demandez un nouveau fichier au client.',
            $warning->filter('#admin-document-actions-' . $unreadable->getId())->text(),
        );
        $this->assertCount(1, $warning->selectButton('Relancer l\'analyse'), 'Still allowed.');

        $quiet = $this->browser->request('GET', $this->path($interrupted));
        $this->assertStringNotContainsString('relance échouera probablement', $quiet->filter('#admin-document-actions-' . $interrupted->getId())->text());
    }

    public function testTheRetryButtonDisablesItselfWhileSubmitting(): void
    {
        $document = $this->failedDocument($this->paul);
        $this->browser->loginUser($this->admin);

        $crawler = $this->browser->request('GET', $this->path($document));

        $button = $crawler->selectButton('Relancer l\'analyse');
        $this->assertSame('Relance en cours…', $button->attr('data-turbo-submits-with'));
        $this->assertSame('_top', $button->closest('form')?->attr('data-turbo-frame'));
    }

    public function testTheActionsFrameIsServedAloneAndOnlyOffersARetryWhenFailed(): void
    {
        $failed = $this->failedDocument($this->paul);
        $pending = $this->storedDocument($this->paul, 'En attente.pdf');
        $this->browser->loginUser($this->admin);

        $crawler = $this->browser->request('GET', $this->path($failed) . '/actions');
        $this->assertCount(1, $crawler->filter('turbo-frame#admin-document-actions-' . $failed->getId() . ' form'));
        $this->assertNotSame('', (string) $crawler->filter('input[name=_token]')->attr('value'));

        $empty = $this->browser->request('GET', $this->path($pending) . '/actions');
        $this->assertCount(1, $empty->filter('turbo-frame#admin-document-actions-' . $pending->getId()));
        $this->assertCount(0, $empty->filter('form'));
    }

    public function testTheReturnPathKeepsTheListTheAdministratorCameFrom(): void
    {
        $document = $this->failedDocument($this->paul);
        $this->browser->loginUser($this->admin);
        $retour = '/admin?raison=interrupted&page=2';

        $crawler = $this->browser->request('GET', $this->path($document), ['retour' => $retour]);

        $this->assertSame($retour, $crawler->selectLink('Retour à la supervision')->attr('href'));
        $this->assertSame($retour, $crawler->filter('form input[name=retour]')->attr('value'));

        $this->browser->submit($crawler->selectButton('Relancer l\'analyse')->form());
        $this->assertResponseRedirects();
        $location = (string) $this->browser->getResponse()->headers->get('Location');
        $this->assertSame($this->path($document), parse_url($location, \PHP_URL_PATH));
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        $this->assertSame($retour, $query['retour'] ?? null);
    }

    public function testTheActionsFrameFindsTheReturnPathInTheRefererOfThePage(): void
    {
        $document = $this->failedDocument($this->paul);
        $this->browser->loginUser($this->admin);

        $crawler = $this->browser->request('GET', $this->path($document) . '/actions', server: ['HTTP_REFERER' => 'http://localhost' . $this->path($document) . '?retour=/admin/clients']);

        $this->assertSame('/admin/clients', $crawler->filter('input[name=retour]')->attr('value'));
    }

    #[DataProvider('foreignReturnPaths')]
    public function testOnlyInternalReturnPathsAreAccepted(string $retour): void
    {
        $document = $this->failedDocument($this->paul);
        $this->browser->loginUser($this->admin);

        $crawler = $this->browser->request('GET', $this->path($document), ['retour' => $retour]);

        $this->assertSame('/admin', $crawler->selectLink('Retour à la supervision')->attr('href'));
        $this->assertCount(0, $crawler->filter('input[name=retour]'));

        $token = $this->tokenFor($document);
        $this->browser->request('POST', $this->path($document) . '/relancer', ['_token' => $token, 'retour' => $retour]);
        $this->assertResponseRedirects($this->path($document));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignReturnPaths(): iterable
    {
        yield 'absolute url' => ['https://evil.test/admin'];
        yield 'protocol-relative' => ['//evil.test/admin'];
        yield 'client space' => ['/espace'];
        yield 'similar prefix' => ['/administrator'];
        yield 'backslash' => ['/admin\\@evil.test'];
        yield 'line break' => ["/admin\r\nLocation: https://evil.test"];
        yield 'javascript' => ['javascript:alert(1)'];
    }

    public function testTheAdminFileRoutesServeAnyClientsDocument(): void
    {
        $document = $this->storedDocument($this->paul, 'Bilan été.pdf');
        $this->browser->loginUser($this->admin);

        $this->browser->request('GET', $this->path($document) . '/fichier');
        $this->assertResponseIsSuccessful();
        $download = $this->browser->getResponse();
        $this->assertStringStartsWith('attachment;', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', (string) $download->headers->get('Cache-Control'));

        $this->browser->request('GET', $this->path($document) . '/apercu');
        $this->assertResponseIsSuccessful();
        $preview = $this->browser->getResponse();
        $this->assertStringStartsWith('inline;', (string) $preview->headers->get('Content-Disposition'));
        $this->assertSame('SAMEORIGIN', $preview->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $preview->headers->get('Content-Security-Policy'));
    }

    public function testThePreviewFrameUsesTheAdminRoute(): void
    {
        $document = $this->storedDocument($this->paul, 'Bilan.pdf');
        $this->browser->loginUser($this->admin);

        $crawler = $this->browser->request('GET', $this->path($document));

        $this->assertSame($this->path($document) . '/apercu', $crawler->filter('iframe')->attr('src'));
        $this->assertStringNotContainsString('/espace/', (string) $crawler->html());
    }

    private function path(Document $document): string
    {
        return '/admin/pieces/' . $document->getId()->toRfc4122();
    }

    /**
     * The CSRF token the page embeds for this (failed) document, read from the page itself.
     */
    private function tokenFor(Document $document): string
    {
        return (string) $this->browser->request('GET', $this->path($document) . '/actions')->filter('input[name=_token]')->attr('value');
    }

    private function reload(Document $document): Document
    {
        $this->em()->clear();

        return $this->em()->find(Document::class, $document->getId()) ?? throw new \LogicException('Document not found.');
    }

    private function squash(string $text): string
    {
        return preg_replace('/\s+/', ' ', trim($text)) ?? '';
    }
}
