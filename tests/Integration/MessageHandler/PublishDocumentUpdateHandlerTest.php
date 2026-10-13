<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\User;
use App\Enum\FailureReason;
use App\Message\PublishDocumentUpdate;
use App\MessageHandler\PublishDocumentUpdateHandler;
use App\Realtime\DocumentStreamRenderer;
use App\Realtime\Topics;
use App\Repository\DocumentRepository;
use App\Tests\Integration\AnalysisTestCase;
use App\Tests\Support\PublishedUpdates;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

final class PublishDocumentUpdateHandlerTest extends AnalysisTestCase
{
    public function testAStatusChangeIsPublishedAsReplacementsOnTheClientsPrivateTopic(): void
    {
        $document = $this->createDocument('Bulletin-mars.pdf');

        $this->handle(new PublishDocumentUpdate($document->getId()));

        $updates = $this->published();
        $this->assertCount(2, $updates, 'One update for the client, one for the back office.');
        $this->assertSame([Topics::client($document->getClient()->getId())], $updates[0]->getTopics());
        $this->assertTrue($updates[0]->isPrivate());

        $id = $document->getId()->toRfc4122();
        $data = $updates[0]->getData();
        $this->assertStringContainsString(\sprintf('<turbo-stream action="replace" target="document-%s">', $id), $data);
        $this->assertStringContainsString(\sprintf('<turbo-stream action="replace" target="upload-status-%s">', $id), $data);
        $this->assertStringContainsString(\sprintf('<turbo-stream action="update" targets="#live-status[data-live-scope=\'documents\'], #live-status[data-live-scope=\'document-%s\']">', $id), $data);
        $this->assertStringContainsString('Bulletin-mars.pdf : analyse en attente.', $data);
        $this->assertStringNotContainsString('action="prepend"', $data);
        $this->assertStringNotContainsString('admin-', $data, 'The client payload knows nothing of the back office.');
    }

    public function testTheBackOfficeGetsItsOwnPrivateUpdateOnTheAdminTopic(): void
    {
        $document = $this->createDocument('Bulletin-mars.pdf');
        $this->asWorker(function () use ($document): void {
            $managed = $this->em->find($document::class, $document->getId()) ?? throw new \LogicException('Document not found.');
            $managed->startAnalysis('ref');
            $managed->failAnalysis(FailureReason::UnreadableFile);
        });

        $this->handle(new PublishDocumentUpdate($document->getId()));

        $updates = $this->published();
        $this->assertSame([Topics::admin()], $updates[1]->getTopics());
        $this->assertTrue($updates[1]->isPrivate());
        $id = $document->getId()->toRfc4122();
        $data = $updates[1]->getData();
        $this->assertStringContainsString('<turbo-stream action="replace" target="admin-kpis">', $data);
        $this->assertStringContainsString('id="admin-kpis"', $data);
        $this->assertStringContainsString(\sprintf('<turbo-stream action="replace" target="admin-document-status-%s">', $id), $data);
        $this->assertStringContainsString(\sprintf('<turbo-stream action="replace" target="admin-document-attempts-%s">', $id), $data);
        $this->assertStringContainsString(\sprintf('<turbo-stream action="replace" target="admin-document-timeline-%s">', $id), $data);
        $this->assertStringContainsString(\sprintf('<turbo-stream action="replace" target="admin-document-actions-%s">', $id), $data);
        $this->assertStringContainsString(\sprintf('src="/admin/pieces/%s/actions"', $id), $data, 'The retry button is fetched by the viewer: it needs their CSRF token.');
        $this->assertStringContainsString('unreadable_file', $data);
        $this->assertStringNotContainsString('csrf', strtolower($data));
        // Failed: the list is not reordered under the cursor, the administrator is told instead.
        $this->assertStringContainsString('<turbo-stream action="update" target="admin-todo-notice">', $data);
        $this->assertStringNotContainsString(\sprintf('action="remove" target="admin-todo-%s"', $id), $data);
        $this->assertStringContainsString("targets=\"#live-status[data-live-scope='admin-todo']\"", $data);
        $this->assertStringContainsString('Bulletin-mars.pdf : échec de l&#039;analyse', $data);
        $this->assertStringContainsString(\sprintf("targets=\"#live-status[data-live-scope='document-%s']\"", $id), $data);
    }

    public function testADocumentNoLongerFailedLeavesTheToDoListWithoutNotice(): void
    {
        $document = $this->createDocument('Bulletin-mars.pdf');

        $this->handle(new PublishDocumentUpdate($document->getId()));

        $data = $this->published()[1]->getData();
        $this->assertStringContainsString(\sprintf('<turbo-stream action="remove" target="admin-todo-%s"></turbo-stream>', $document->getId()->toRfc4122()), $data);
        $this->assertStringNotContainsString('admin-todo-notice', $data);
        $this->assertStringNotContainsString("data-live-scope='admin-todo'", $data, 'Ordinary traffic is not announced on the supervision page.');
    }

    public function testARetryIsAnnouncedOnTheSupervisionPageAndNamesTheAdministratorOnBothSides(): void
    {
        $document = $this->createDocument('Bulletin-mars.pdf');
        $this->asWorker(function () use ($document): void {
            $managed = $this->em->find($document::class, $document->getId()) ?? throw new \LogicException('Document not found.');
            $managed->startAnalysis('ref');
            $managed->failAnalysis(FailureReason::Interrupted);
            // Flushed apart, as in real life: a document never has two unfinished attempts at once.
            $this->em->flush();
            $admin = User::admin('sophie@owner.test', 'Sophie Laurent');
            $this->em->persist($admin);
            $managed->retryAnalysis($admin);
        });

        $this->handle(new PublishDocumentUpdate($document->getId()));

        [$client, $admin] = array_map(static fn (Update $update): string => $update->getData(), $this->published());
        $this->assertStringContainsString("targets=\"#live-status[data-live-scope='admin-todo']\"", $admin);
        $this->assertStringContainsString('Bulletin-mars.pdf : analyse relancée.', $admin);
        $this->assertStringContainsString('Analyse relancée par Sophie Laurent (tentative n° 2)', $admin);
        $this->assertStringContainsString('Analyse relancée par Sophie Laurent (équipe OWNER)', $client);
    }

    public function testACreatedDocumentOnlyRefreshesTheCountsForTheBackOffice(): void
    {
        $document = $this->createDocument();

        $this->handle(new PublishDocumentUpdate($document->getId(), created: true));

        $data = $this->published()[1]->getData();
        $this->assertStringContainsString('target="admin-kpis"', $data);
        $this->assertStringNotContainsString('admin-document-', $data);
    }

    public function testANewDocumentIsPrependedWithoutEverDuplicatingARow(): void
    {
        $document = $this->createDocument();

        $this->handle(new PublishDocumentUpdate($document->getId(), created: true));

        $data = $this->published()[0]->getData();
        $id = $document->getId()->toRfc4122();
        $this->assertStringContainsString('<turbo-stream action="remove" target="dashboard-empty">', $data);
        $this->assertStringContainsString(\sprintf('<turbo-stream action="remove" target="document-%s">', $id), $data);
        $this->assertStringContainsString('<turbo-stream action="prepend" target="dashboard-documents">', $data);
        $this->assertStringContainsString('scan.pdf : pièce reçue.', $data);
    }

    public function testTheFilenameIsEscaped(): void
    {
        $document = $this->createDocument('<script>alert(1)</script>.pdf');

        $this->handle(new PublishDocumentUpdate($document->getId(), created: true));

        $data = $this->published()[0]->getData();
        $this->assertStringNotContainsString('<script>', $data);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;.pdf', $data);
    }

    public function testTheCurrentStateIsPublishedNotTheOneAtQueueTime(): void
    {
        $document = $this->createDocument();
        $this->asWorker(function () use ($document): void {
            $this->em->find($document::class, $document->getId())?->startAnalysis('provider-ref');
        });

        $this->handle(new PublishDocumentUpdate($document->getId(), created: true));

        $this->assertStringContainsString('En cours d&#039;analyse', $this->published()[0]->getData());
    }

    public function testAMissingDocumentPublishesNothing(): void
    {
        $this->handle(new PublishDocumentUpdate(Uuid::v7()));

        $this->assertSame([], $this->published());
    }

    public function testAHubFailureIsNotSwallowedSoThatMessengerRetries(): void
    {
        $document = $this->createDocument();
        $real = self::getContainer()->get(HubInterface::class);
        $down = new MockHub($real->getPublicUrl(), new StaticTokenProvider('test'), static fn (Update $update): string => throw new \RuntimeException('Hub unreachable.'));
        $handler = new PublishDocumentUpdateHandler(
            self::getContainer()->get(DocumentRepository::class),
            self::getContainer()->get(DocumentStreamRenderer::class),
            $down,
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hub unreachable.');

        $handler(new PublishDocumentUpdate($document->getId()));
    }

    private function handle(PublishDocumentUpdate $message): void
    {
        $this->em->clear();
        self::getContainer()->get(PublishDocumentUpdateHandler::class)($message);
    }

    /**
     * @return list<Update>
     */
    private function published(): array
    {
        // Test-only service (config/services.yaml, when@test): unknown to the dev container PHPStan inspects.
        $published = self::getContainer()->get(PublishedUpdates::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        \assert($published instanceof PublishedUpdates);

        return $published->all();
    }
}
