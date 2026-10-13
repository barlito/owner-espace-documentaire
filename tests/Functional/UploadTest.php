<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Analysis\AnalyzeDocument;
use App\Entity\Document;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class UploadTest extends WebTestCase
{
    use CreatesAccounts;

    private const string FIXTURES = __DIR__ . '/../Fixtures/files/';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
    }

    public function testUploadPageStatesTheRulesAndOffersAWorkingForm(): void
    {
        $this->browser->loginUser($this->createClientUser());

        $crawler = $this->browser->request('GET', '/espace/depot');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Déposer des pièces');
        $this->assertSelectorTextContains('aside', '20 Mo maximum par fichier');
        $this->assertSelectorTextContains('aside', '20 fichiers maximum par envoi');
        $this->assertSelectorTextContains('aside', 'La réception confirmée ne signifie pas que l\'analyse est terminée');
        $this->assertCount(1, $crawler->filter('form[method=post][enctype="multipart/form-data"][action="/espace/depot"] input[type=file][multiple][name="files[]"]'));
        $this->assertGreaterThan(10, $crawler->filter('select[name=type] optgroup option')->count());
    }

    public function testClientUploadsAFileAndSeesItAfterReload(): void
    {
        $this->browser->loginUser($this->createClientUser());
        $token = $this->tokenFromUploadPage();

        $this->postFile('sample.pdf', 'Bilan 2024.pdf', ['_token' => $token, 'type' => 'annual_accounts']);

        $this->assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('Bilan 2024.pdf', $payload['name']);
        $this->assertSame('En attente d\'analyse', $payload['status_label']);
        $this->assertNotEmpty($payload['id']);
        $this->assertSame('/espace', $payload['url']);

        $this->browser->request('GET', '/espace');
        $this->assertSelectorTextContains('main', 'Dernières pièces reçues');
        $this->assertSelectorTextContains('main', 'Bilan 2024.pdf');
        $this->assertSelectorTextContains('main', 'Bilan, comptes annuels ou liasse fiscale');
        $this->assertSelectorTextContains('main', 'En attente d\'analyse');
        $this->assertSelectorTextNotContains('main', 'Votre dossier est encore vide');
    }

    public function testAnUploadQueuesExactlyOneAnalysis(): void
    {
        $this->browser->loginUser($this->createClientUser());

        $this->postFile('sample.pdf', 'Bilan 2024.pdf', ['_token' => $this->tokenFromUploadPage()]);

        $this->assertResponseStatusCodeSame(201);
        $transport = static::getContainer()->get('messenger.transport.async');
        $this->assertInstanceOf(InMemoryTransport::class, $transport);
        $this->assertCount(1, $transport->getSent());
        $this->assertInstanceOf(AnalyzeDocument::class, $transport->getSent()[0]->getMessage());
    }

    public function testARefusedFileQueuesNothing(): void
    {
        $this->browser->loginUser($this->createClientUser());

        $this->postFile('sample.txt', 'notes.txt', ['_token' => $this->tokenFromUploadPage()]);

        $this->assertResponseStatusCodeSame(422);
        $transport = static::getContainer()->get('messenger.transport.async');
        $this->assertInstanceOf(InMemoryTransport::class, $transport);
        $this->assertSame([], $transport->getSent());
    }

    public function testUncategorizedDocumentShowsItsSizeAndDate(): void
    {
        $this->browser->loginUser($this->createClientUser());
        $this->postFile('sample.png', 'scan.png', ['_token' => $this->tokenFromUploadPage()]);
        $this->assertResponseStatusCodeSame(201);

        $this->browser->request('GET', '/espace');

        $this->assertSelectorTextContains('main li', 'Non catégorisée');
        $this->assertSelectorTextContains('main li', '69 octets');
        $this->assertMatchesRegularExpression('#\d{2}/\d{2}/\d{4} à \d{2}:\d{2}#', $this->browser->getCrawler()->filter('main li')->text());
    }

    public function testDashboardShowsOnlyTheTenLatestDocuments(): void
    {
        $this->browser->loginUser($this->createClientUser());
        $token = $this->tokenFromUploadPage();
        for ($i = 1; $i <= 12; ++$i) {
            $this->postFile('sample.pdf', \sprintf('piece-%02d.pdf', $i), ['_token' => $token]);
            $this->assertResponseStatusCodeSame(201);
        }

        $crawler = $this->browser->request('GET', '/espace');

        $this->assertCount(10, $crawler->filter('main li'));
        $this->assertSelectorTextContains('main', 'piece-12.pdf');
        $this->assertSelectorTextNotContains('main', 'piece-01.pdf');
    }

    public function testUnsupportedFormatIsRefusedWithAFrenchMessage(): void
    {
        $this->browser->loginUser($this->createClientUser());

        $this->postFile('sample.txt', 'notes.txt', ['_token' => $this->tokenFromUploadPage()]);

        $this->assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertStringStartsWith('Ce format n\'est pas accepté. Formats acceptés : PDF', $payload['error']);
        $this->assertSame(0, $this->em()->getRepository(Document::class)->count([]));
    }

    public function testMissingFileAndUnknownTypeAreRefused(): void
    {
        $this->browser->loginUser($this->createClientUser());
        $token = $this->tokenFromUploadPage();

        $this->browser->request('POST', '/espace/depot/fichier', ['_token' => $token]);
        $this->assertResponseStatusCodeSame(422);

        $this->postFile('sample.pdf', 'a.pdf', ['_token' => $token, 'type' => 'not_a_type']);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testMissingOrInvalidCsrfTokenIsForbidden(): void
    {
        $this->browser->loginUser($this->createClientUser());

        $this->postFile('sample.pdf', 'a.pdf', []);
        $this->assertResponseStatusCodeSame(403);

        $this->postFile('sample.pdf', 'a.pdf', ['_token' => 'forged']);
        $this->assertResponseStatusCodeSame(403);

        $this->assertSame(0, $this->em()->getRepository(Document::class)->count([]));
    }

    public function testAnonymousVisitorCannotUpload(): void
    {
        $this->postFile('sample.pdf', 'a.pdf', ['_token' => 'whatever']);

        $this->assertResponseRedirects('http://localhost/connexion');
    }

    public function testAdminCannotUseTheClientUpload(): void
    {
        $this->browser->loginUser($this->createAdmin());

        $this->browser->request('GET', '/espace/depot');
        $this->assertResponseStatusCodeSame(403);

        $this->postFile('sample.pdf', 'a.pdf', ['_token' => 'whatever']);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testBatchFallbackProcessesEachFileIndependently(): void
    {
        $user = $this->createClientUser();
        $this->browser->loginUser($user);
        $token = $this->tokenFromUploadPage();

        $this->browser->request('POST', '/espace/depot', ['_token' => $token, 'type' => 'payslip'], ['files' => [
            $this->upload('sample.pdf', 'bulletin.pdf'),
            $this->upload('sample.txt', 'notes.txt'),
            $this->upload('sample.png', 'photo.png'),
        ]]);

        $this->assertResponseRedirects('/espace');
        $crawler = $this->browser->followRedirect();
        $this->assertStringContainsString('2 pièces reçues. Leur analyse va démarrer.', $crawler->filter('main')->text());
        $this->assertStringContainsString('notes.txt : Ce format n\'est pas accepté.', $crawler->filter('main')->text());

        $documents = $this->em()->getRepository(Document::class)->findAll();
        $this->assertCount(2, $documents);
        foreach ($documents as $document) {
            $this->assertSame($user->getClient()?->getId()->toRfc4122(), $document->getClient()->getId()->toRfc4122());
            $this->assertSame('payslip', $document->getType()?->value);
        }
    }

    public function testBatchFallbackWithOnlyRefusedFilesGoesBackToTheUploadPage(): void
    {
        $this->browser->loginUser($this->createClientUser());

        $this->browser->request('POST', '/espace/depot', ['_token' => $this->tokenFromUploadPage()], ['files' => [$this->upload('sample.txt', 'notes.txt')]]);

        $this->assertResponseRedirects('/espace/depot');
        $this->browser->followRedirect();
        $this->assertSelectorTextContains('main', 'notes.txt : Ce format n\'est pas accepté.');
    }

    public function testBatchFallbackWithoutFileAsksToSelectOne(): void
    {
        $this->browser->loginUser($this->createClientUser());

        $this->browser->request('POST', '/espace/depot', ['_token' => $this->tokenFromUploadPage()]);

        $this->assertResponseRedirects('/espace/depot');
        $this->browser->followRedirect();
        $this->assertSelectorTextContains('main', 'Aucun fichier sélectionné.');
    }

    public function testBatchFallbackDetectsAnExceededPostMaxSize(): void
    {
        $this->browser->loginUser($this->createClientUser());

        // PHP empties $_POST and $_FILES when post_max_size is exceeded, but CONTENT_LENGTH remains.
        $this->browser->request('POST', '/espace/depot', server: ['CONTENT_LENGTH' => 150 * 1024 * 1024]);

        $this->assertResponseRedirects('/espace/depot');
        $this->browser->followRedirect();
        $this->assertSelectorTextContains('main', 'L\'envoi dépasse la taille maximale autorisée (100 Mo au total sans JavaScript).');
    }

    public function testBatchFallbackRejectsAnInvalidCsrfToken(): void
    {
        $this->browser->loginUser($this->createClientUser());

        $this->browser->request('POST', '/espace/depot', ['_token' => 'forged'], ['files' => [$this->upload('sample.pdf', 'a.pdf')]]);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame(0, $this->em()->getRepository(Document::class)->count([]));
    }

    public function testAClientNeverSeesAnotherClientsDocuments(): void
    {
        $paul = $this->createClientUser();
        $claire = $this->createClientUser('Micro-crèche Les Petits Pas', 'claire@lespetitspas.test');

        $this->browser->loginUser($paul);
        $this->postFile('sample.pdf', 'secret-de-paul.pdf', ['_token' => $this->tokenFromUploadPage()]);
        $this->assertResponseStatusCodeSame(201);

        $this->browser->loginUser($claire);
        $this->browser->request('GET', '/espace');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('main', 'secret-de-paul.pdf');
        $this->assertSelectorTextContains('main', 'Votre dossier est encore vide');
    }

    public function testEmptyDashboardOffersToUpload(): void
    {
        $this->browser->loginUser($this->createClientUser());

        $crawler = $this->browser->request('GET', '/espace');

        $this->assertCount(2, $crawler->filter('a[href="/espace/depot"]'));
        $this->assertSelectorTextContains('main', 'Déposer des pièces');
    }

    private function tokenFromUploadPage(): string
    {
        $crawler = $this->browser->request('GET', '/espace/depot');
        $token = $crawler->filter('input[name=_token]')->attr('value');
        $this->assertIsString($token);

        return $token;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function postFile(string $fixture, string $clientName, array $parameters): Crawler
    {
        return $this->browser->request('POST', '/espace/depot/fichier', $parameters, ['file' => $this->upload($fixture, $clientName)]);
    }

    private function upload(string $fixture, string $clientName): UploadedFile
    {
        // Copy: UploadedFile in test mode moves nothing, but a request may consume the temp path.
        $copy = tempnam(sys_get_temp_dir(), 'up');
        copy(self::FIXTURES . $fixture, $copy);

        return new UploadedFile($copy, $clientName, null, null, true);
    }
}
