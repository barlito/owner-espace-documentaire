<?php

declare(strict_types=1);

namespace App\Tests\Unit\DocumentPage;

use App\Analysis\AnalysisResult;
use App\DocumentPage\AdminTimelinePresenter;
use App\DocumentPage\ClientTimelinePresenter;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\FailureReason;
use PHPUnit\Framework\TestCase;

final class AdminTimelinePresenterTest extends TestCase
{
    private Document $document;

    protected function setUp(): void
    {
        $client = new Client('Client A');
        $this->document = new Document($client, User::forClient($client, 'paul@client-a.test', 'Paul Martin'), 'scan.pdf', 'key', 'application/pdf', 10, str_repeat('a', 64));
    }

    public function testTheTeamSeesWhoRetriedWhichAttemptAndWhyItFailed(): void
    {
        $this->document->startAnalysis('ref');
        $this->document->failAnalysis(FailureReason::UnreadableFile);
        $this->document->retryAnalysis(User::admin('sophie@owner.test', 'Sophie Laurent'));
        $this->document->startAnalysis('ref-2');
        $this->document->completeAnalysis(new AnalysisResult(null, null, null, 'Résumé', [], 0.9));

        $entries = new AdminTimelinePresenter()->present($this->document);

        $this->assertSame(
            [
                ['Pièce reçue', 'Déposée par Paul Martin'],
                ['Analyse démarrée', 'Tentative n° 1'],
                ['Échec de l\'analyse', 'Le fichier est illisible (unreadable_file) · Tentative n° 1'],
                ['Analyse relancée par Sophie Laurent (tentative n° 2)', null],
                ['Analyse démarrée', 'Tentative n° 2'],
                ['Analyse terminée', 'Tentative n° 2'],
            ],
            array_map(static fn ($entry): array => [$entry->label, $entry->detail], $entries),
        );
    }

    public function testItFollowsTheClientTimelineStepForStepButNeverTheOtherWayRound(): void
    {
        $admin = User::admin('sophie@owner.test', 'Sophie Laurent');
        $this->document->startAnalysis('ref');
        $this->document->failAnalysis(FailureReason::Interrupted);
        $this->document->retryAnalysis($admin);

        $forAdmin = new AdminTimelinePresenter()->present($this->document);
        $forClient = new ClientTimelinePresenter()->present($this->document);

        $this->assertCount(\count($forAdmin), $forClient);
        $this->assertSame(array_map(static fn ($e): string => $e->icon, $forAdmin), array_map(static fn ($e): string => $e->icon, $forClient));
        $clientText = implode(' ', array_map(static fn ($e): string => $e->label . ' ' . $e->detail, $forClient));
        $this->assertStringNotContainsString('Sophie', $clientText);
        $this->assertStringNotContainsString('interrupted', $clientText);
    }
}
