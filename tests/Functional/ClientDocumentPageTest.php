<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Analysis\AnalysisResult;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\FailureReason;
use App\Upload\UploadPolicy;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ClientDocumentPageTest extends WebTestCase
{
    use CreatesAccounts;

    private const string PDF_BYTES = "%PDF-1.4\nfake pdf content\n";

    private KernelBrowser $browser;
    private User $claire;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        // The in-memory test storage must survive from the fixture set-up to the requests.
        $this->browser->disableReboot();
        $this->claire = $this->createClientUser('Micro-crèche Les Petits Pas', 'claire@lespetitspas.test');
    }

    public function testAClientSeesTheirDocumentPage(): void
    {
        $document = $this->storedDocument('Bulletin de paie - Mars 2026.pdf');
        $this->browser->loginUser($this->claire);

        $crawler = $this->browser->request('GET', $this->path($document));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Bulletin de paie - Mars 2026.pdf');
        $this->assertSame('/espace', $crawler->selectLink('Retour à mon dossier')->attr('href'));
        $this->assertSame($this->path($document) . '/fichier', $crawler->selectLink('Télécharger')->attr('href'));
        $this->assertSelectorTextContains('main', 'Non catégorisée');
        $this->assertSelectorTextContains('main', 'par Paul Martin');
        $this->assertCount(1, $crawler->filter(\sprintf('#document-page-status-%s', $document->getId())));
        $this->assertCount(1, $crawler->filter(\sprintf('#document-page-timeline-%s', $document->getId())));
        $this->assertCount(1, $crawler->filter('turbo-mercure-stream-source[private]'));
    }

    public function testPdfsArePreviewedInATitledFrame(): void
    {
        $document = $this->storedDocument('Bilan.pdf');
        $this->browser->loginUser($this->claire);

        $crawler = $this->browser->request('GET', $this->path($document));

        $frame = $crawler->filter('iframe');
        $this->assertCount(1, $frame);
        $this->assertSame($this->path($document) . '/apercu', $frame->attr('src'));
        $this->assertNotSame('', $frame->attr('title'));
    }

    public function testImagesArePreviewedWithAnAlternativeText(): void
    {
        $document = $this->storedDocument('Photo.jpg', 'image/jpeg');
        $this->browser->loginUser($this->claire);

        $crawler = $this->browser->request('GET', $this->path($document));

        $this->assertCount(0, $crawler->filter('iframe'));
        $this->assertSame('Aperçu de Photo.jpg', $crawler->filter('img[src$="/apercu"]')->attr('alt'));
    }

    #[DataProvider('unpreviewableMimeTypes')]
    public function testOtherFormatsOfferADownloadInsteadOfAPreview(string $mimeType): void
    {
        $document = $this->storedDocument('Fichier.bin', $mimeType);
        $this->browser->loginUser($this->claire);

        $crawler = $this->browser->request('GET', $this->path($document));

        $this->assertCount(0, $crawler->filter('iframe, img[src$="/apercu"]'));
        $this->assertSelectorTextContains('main', 'Aperçu non disponible pour ce format');
        $this->assertCount(2, $crawler->filter('a[href$="/fichier"]'), 'Header and preview area both offer the download.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unpreviewableMimeTypes(): iterable
    {
        yield 'heic' => ['image/heic'];
        yield 'docx' => [UploadPolicy::DOCX_MIME];
        yield 'xlsx' => [UploadPolicy::XLSX_MIME];
    }

    public function testAPendingDocumentExplainsThatTheAnalysisStartsByItself(): void
    {
        $document = $this->storedDocument('Facture.pdf');
        $this->browser->loginUser($this->claire);

        $this->browser->request('GET', $this->path($document));

        $this->assertSelectorTextContains('#document-page-status-' . $document->getId(), 'En attente d\'analyse');
        $this->assertSelectorTextContains('#document-page-status-' . $document->getId(), 'L\'analyse démarre automatiquement');
    }

    public function testAProcessingDocumentSaysTheAnalysisIsRunning(): void
    {
        $document = $this->storedDocument('Facture.pdf');
        $document->startAnalysis('ref-1');
        $this->em()->flush();
        $this->browser->loginUser($this->claire);

        $this->browser->request('GET', $this->path($document));

        $this->assertSelectorTextContains('#document-page-status-' . $document->getId(), 'Analyse en cours…');
    }

    public function testAnAnalyzedDocumentShowsItsResult(): void
    {
        $document = $this->storedDocument('Bulletin.pdf');
        $document->startAnalysis('ref-1');
        $document->completeAnalysis(new AnalysisResult(
            DocumentType::Payslip,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-31'),
            'Bulletin de salaire de mars 2026.',
            [['label' => 'Net à payer', 'value' => '1 820,45 €'], ['label' => 'Salarié', 'value' => 'Jade Moreau']],
            0.92,
        ));
        $this->em()->flush();
        $this->browser->loginUser($this->claire);

        $crawler = $this->browser->request('GET', $this->path($document));

        $status = $crawler->filter('#document-page-status-' . $document->getId());
        $this->assertStringContainsString('Analysée', $status->text());
        $this->assertStringContainsString('Bulletin de salaire de mars 2026.', $status->text());
        $this->assertStringContainsString('Mars 2026', $status->text());
        $this->assertStringContainsString('Bulletin de salaire', $status->text());
        $this->assertCount(2, $status->filter('dl')->last()->filter('dt'));
        $this->assertStringContainsString('Net à payer', $status->text());
        $this->assertStringContainsString('1 820,45 €', $status->text());
        $this->assertStringContainsString('Analyse simulée', $status->text());
    }

    public function testAnUnreadableFileAsksForANewUpload(): void
    {
        $document = $this->failedDocument(FailureReason::UnreadableFile);
        $this->browser->loginUser($this->claire);

        $crawler = $this->browser->request('GET', $this->path($document));

        $status = $crawler->filter('#document-page-status-' . $document->getId());
        $this->assertStringContainsString('Le fichier semble illisible. Déposez une version plus nette', $status->text());
        $this->assertSame('/espace/depot', $status->selectLink('Déposer une nouvelle version')->attr('href'));
    }

    #[DataProvider('otherFailureReasons')]
    public function testOtherFailuresReassureTheClientWithoutAskingForAnUpload(FailureReason $reason): void
    {
        $document = $this->failedDocument($reason);
        $this->browser->loginUser($this->claire);

        $crawler = $this->browser->request('GET', $this->path($document));

        $status = $crawler->filter('#document-page-status-' . $document->getId());
        $this->assertStringContainsString('L\'équipe OWNER a été prévenue et s\'en occupe', $status->text());
        $this->assertCount(0, $status->selectLink('Déposer une nouvelle version'));
    }

    /**
     * @return iterable<string, array{FailureReason}>
     */
    public static function otherFailureReasons(): iterable
    {
        yield 'unsupported' => [FailureReason::UnsupportedFormat];
        yield 'interrupted' => [FailureReason::Interrupted];
        yield 'unknown' => [FailureReason::Unknown];
    }

    public function testARetriedDocumentSaysSoWithoutNamingTheAdministrator(): void
    {
        $document = $this->failedDocument(FailureReason::Interrupted);
        $admin = $this->createAdmin();
        $document->retryAnalysis($admin);
        $this->em()->flush();
        $this->browser->loginUser($this->claire);

        $crawler = $this->browser->request('GET', $this->path($document));

        $status = $crawler->filter('#document-page-status-' . $document->getId());
        $this->assertStringContainsString('relancé l\'analyse de cette pièce (tentative n° 2)', $status->text());
        $labels = $crawler->filter('#document-page-timeline-' . $document->getId() . ' ol > li p.font-medium')->each(static fn (Crawler $node): string => $node->text());
        $this->assertSame(['Pièce reçue', 'Analyse démarrée', 'Échec de l\'analyse', 'Analyse relancée par Sophie Laurent (équipe OWNER)'], $labels);
        $this->assertStringNotContainsString('admin@owner.test', (string) $this->browser->getResponse()->getContent());
    }

    public function testTheTimelineDatesEachStepAndNamesTheUploader(): void
    {
        $document = $this->storedDocument('Facture.pdf');
        $this->browser->loginUser($this->claire);

        $crawler = $this->browser->request('GET', $this->path($document));

        $first = $crawler->filter('#document-page-timeline-' . $document->getId() . ' ol > li')->first();
        $this->assertStringContainsString('Pièce reçue', $first->text());
        $this->assertStringContainsString('Déposée par Paul Martin', $first->text());
        $this->assertNotSame('', $first->filter('time')->attr('datetime'));
    }

    public function testTheDownloadIsAnAttachmentOfTheStoredBytes(): void
    {
        $document = $this->storedDocument('Bilan récapitulatif été.pdf');
        $this->browser->loginUser($this->claire);

        $this->browser->request('GET', $this->path($document) . '/fichier');

        $response = $this->browser->getResponse();
        $this->assertResponseIsSuccessful();
        $this->assertSame(self::PDF_BYTES, $this->browser->getInternalResponse()->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame((string) \strlen(self::PDF_BYTES), $response->headers->get('Content-Length'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString('filename="Bilan recapitulatif ete.pdf"', $disposition);
        $this->assertStringContainsString("filename*=utf-8''Bilan%20r%C3%A9capitulatif%20%C3%A9t%C3%A9.pdf", $disposition);
    }

    public function testTheDownloadSendsTheDetectedMimeTypeWhateverTheExtension(): void
    {
        $document = $this->storedDocument('Scan.pdf', 'image/png');
        $this->browser->loginUser($this->claire);

        $this->browser->request('GET', $this->path($document) . '/fichier');

        $this->assertSame('image/png', $this->browser->getResponse()->headers->get('Content-Type'));
    }

    public function testAnUnsafeFilenameStillGivesAValidAsciiFallback(): void
    {
        $document = $this->storedDocument('日本語/%évil.pdf');
        $this->browser->loginUser($this->claire);

        $this->browser->request('GET', $this->path($document) . '/fichier');

        $this->assertResponseIsSuccessful();
        $this->assertMatchesRegularExpression('/^attachment; filename="[A-Za-z0-9._ -]+"; filename\*=/', (string) $this->browser->getResponse()->headers->get('Content-Disposition'));
    }

    public function testThePdfPreviewIsInlineAndFramableByOurPagesOnly(): void
    {
        $document = $this->storedDocument('Bilan.pdf');
        $this->browser->loginUser($this->claire);

        $this->browser->request('GET', $this->path($document) . '/apercu');

        $headers = $this->browser->getResponse()->headers;
        $this->assertResponseIsSuccessful();
        $this->assertSame(self::PDF_BYTES, $this->browser->getInternalResponse()->getContent());
        $this->assertStringStartsWith('inline;', (string) $headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $headers->get('Content-Type'));
        $this->assertSame('SAMEORIGIN', $headers->get('X-Frame-Options'));
        $this->assertSame("frame-ancestors 'self'", $headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        $this->assertTrue($headers->hasCacheControlDirective('private'));
    }

    public function testTheImagePreviewIsSandboxed(): void
    {
        $document = $this->storedDocument('Photo.png', 'image/png');
        $this->browser->loginUser($this->claire);

        $this->browser->request('GET', $this->path($document) . '/apercu');

        $headers = $this->browser->getResponse()->headers;
        $this->assertResponseIsSuccessful();
        $this->assertSame("sandbox; frame-ancestors 'self'", $headers->get('Content-Security-Policy'));
        $this->assertSame('SAMEORIGIN', $headers->get('X-Frame-Options'));
    }

    #[DataProvider('unpreviewableMimeTypes')]
    public function testThereIsNoPreviewForOtherFormats(string $mimeType): void
    {
        $document = $this->storedDocument('Fichier.bin', $mimeType);
        $this->browser->loginUser($this->claire);

        $this->browser->request('GET', $this->path($document) . '/apercu');

        $this->assertResponseStatusCodeSame(404);
    }

    #[DataProvider('fileRoutes')]
    public function testAMissingStoredFileIsACleanNotFound(string $suffix): void
    {
        $document = $this->storedDocument('Bilan.pdf');
        $this->storage()->delete($document->getStorageKey());
        $this->browser->loginUser($this->claire);

        $this->browser->request('GET', $this->path($document) . $suffix);

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fileRoutes(): iterable
    {
        yield 'download' => ['/fichier'];
        yield 'preview' => ['/apercu'];
    }

    #[DataProvider('allRoutes')]
    public function testAnotherClientGetsANotFoundOnEveryRoute(string $suffix): void
    {
        $document = $this->storedDocument('Bilan.pdf');
        $this->browser->loginUser($this->createClientUser('Boulangerie Martin', 'other@boulangerie-martin.test'));

        $this->browser->request('GET', $this->path($document) . $suffix);

        $this->assertResponseStatusCodeSame(404);
    }

    #[DataProvider('allRoutes')]
    public function testAnUnknownOrInvalidIdIsANotFound(string $suffix): void
    {
        $this->browser->loginUser($this->claire);

        foreach (['018f3a52-7b1e-7c3a-9d2e-4f5a6b7c8d9e', 'not-a-uuid', '123'] as $id) {
            $this->browser->request('GET', '/espace/pieces/' . $id . $suffix);

            $this->assertResponseStatusCodeSame(404, $id);
        }
    }

    #[DataProvider('allRoutes')]
    public function testAnAdministratorCannotUseTheClientRoutes(string $suffix): void
    {
        $document = $this->storedDocument('Bilan.pdf');
        $this->browser->loginUser($this->createAdmin());

        $this->browser->request('GET', $this->path($document) . $suffix);

        $this->assertResponseStatusCodeSame(403);
    }

    #[DataProvider('allRoutes')]
    public function testAnonymousVisitorsAreSentToTheLogin(string $suffix): void
    {
        $document = $this->storedDocument('Bilan.pdf');

        $this->browser->request('GET', $this->path($document) . $suffix);

        $this->assertResponseRedirects('/connexion');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allRoutes(): iterable
    {
        yield 'page' => [''];
        yield 'download' => ['/fichier'];
        yield 'preview' => ['/apercu'];
    }

    public function testTheDashboardRowLinksToThePage(): void
    {
        $document = $this->storedDocument('Bilan.pdf');
        $this->browser->loginUser($this->claire);

        $crawler = $this->browser->request('GET', '/espace');

        $this->assertSame($this->path($document), $crawler->selectLink('Bilan.pdf')->attr('href'));
    }

    public function testTheLiveRegionOnlyAnnouncesTheViewedDocumentWhileTheListAnnouncesThemAll(): void
    {
        $document = $this->storedDocument('Bilan.pdf');
        $this->browser->loginUser($this->claire);

        $page = $this->browser->request('GET', $this->path($document));
        $this->assertSame('document-' . $document->getId()->toRfc4122(), $page->filter('#live-status')->attr('data-live-scope'));

        $list = $this->browser->request('GET', '/espace');
        $this->assertSame('documents', $list->filter('#live-status')->attr('data-live-scope'));
    }

    public function testTheBackLinkReturnsToTheList(): void
    {
        $document = $this->storedDocument('Bilan.pdf');
        $this->browser->loginUser($this->claire);

        $link = $this->browser->request('GET', $this->path($document))->selectLink('Retour à mon dossier');

        $this->assertSame('/espace', $link->attr('href'));
        $this->assertSame('back-to-list', $link->attr('data-controller'));
    }

    private function path(Document $document): string
    {
        return '/espace/pieces/' . $document->getId()->toRfc4122();
    }

    private function storedDocument(string $filename, string $mimeType = 'application/pdf'): Document
    {
        $uploader = $this->claire;
        $client = $uploader->getClient() ?? throw new \LogicException('Client account expected.');
        $key = $client->getId()->toRfc4122() . '/' . bin2hex(random_bytes(8));
        $this->storage()->write($key, self::PDF_BYTES);
        // Uploaded by a colleague of Claire, to check that the uploader's name is the one displayed.
        $paul = User::forClient($client, 'paul-' . bin2hex(random_bytes(3)) . '@lespetitspas.test', 'Paul Martin');
        $this->em()->persist($paul);

        $document = new Document($client, $paul, $filename, $key, $mimeType, \strlen(self::PDF_BYTES), hash('sha256', self::PDF_BYTES));
        $this->em()->persist($document);
        $this->em()->flush();

        return $document;
    }

    private function failedDocument(FailureReason $reason): Document
    {
        $document = $this->storedDocument('Scan.pdf');
        $document->startAnalysis('ref-1');
        $document->failAnalysis($reason);
        $this->em()->flush();

        return $document;
    }

    private function storage(): FilesystemOperator
    {
        $storage = static::getContainer()->get('documents.storage');
        \assert($storage instanceof FilesystemOperator);

        return $storage;
    }
}
