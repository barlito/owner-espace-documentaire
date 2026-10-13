<?php

declare(strict_types=1);

namespace App\Tests\Integration\Realtime;

use App\Entity\User;
use App\Enum\FailureReason;
use App\Realtime\DocumentStreamRenderer;
use App\Tests\Integration\AnalysisTestCase;

final class DocumentPageStreamsTest extends AnalysisTestCase
{
    public function testAnUpdateReplacesTheStatusAndTheTimelineOfTheDocumentPage(): void
    {
        $document = $this->createDocument();
        $document->startAnalysis('ref');
        $id = $document->getId()->toRfc4122();

        $stream = self::getContainer()->get(DocumentStreamRenderer::class)->render($document, false);

        $this->assertStringContainsString(\sprintf('<turbo-stream action="replace" target="document-page-status-%s">', $id), $stream);
        $this->assertStringContainsString(\sprintf('<turbo-stream action="replace" target="document-page-timeline-%s">', $id), $stream);
        $this->assertStringContainsString(\sprintf('id="document-page-status-%s"', $id), $stream, 'The replacement keeps the target id.');
        $this->assertStringContainsString('Analyse en cours', $stream);
        $this->assertStringContainsString('Analyse démarrée', $stream);
    }

    public function testTheWordingSentToTheClientNeverNamesTheAdministrator(): void
    {
        $document = $this->createDocument();
        $document->startAnalysis('ref');
        $document->failAnalysis(FailureReason::Unknown);
        $document->retryAnalysis(User::admin('sophie@owner.test', 'Sophie Laurent'));

        $stream = self::getContainer()->get(DocumentStreamRenderer::class)->render($document, false);

        $this->assertStringContainsString('Analyse relancée par Sophie Laurent (équipe OWNER)', $stream);
    }

    public function testACreationDoesNotTouchThePageOfTheDocument(): void
    {
        $stream = self::getContainer()->get(DocumentStreamRenderer::class)->render($this->createDocument(), true);

        $this->assertStringNotContainsString('document-page-', $stream);
    }
}
