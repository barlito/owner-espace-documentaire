<?php

declare(strict_types=1);

namespace App\Tests\Unit\DocumentPage;

use App\Analysis\AnalysisResult;
use App\DocumentPage\ClientTimelinePresenter;
use App\Entity\Client;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\FailureReason;
use PHPUnit\Framework\TestCase;

final class ClientTimelinePresenterTest extends TestCase
{
    private Client $client;
    private User $uploader;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = new Client('Client A');
        $this->uploader = User::forClient($this->client, 'paul@client-a.test', 'Paul Martin');
        $this->admin = User::admin('sophie@owner.test', 'Sophie Laurent');
    }

    public function testASuccessfulAnalysisReadsInChronologicalOrder(): void
    {
        $document = $this->document();
        $document->startAnalysis('ref');
        $document->completeAnalysis(new AnalysisResult(null, null, null, 'Résumé', [], 0.9));

        $entries = new ClientTimelinePresenter()->present($document);

        $this->assertSame(['Pièce reçue', 'Analyse démarrée', 'Analyse terminée'], array_column($this->rows($entries), 'label'));
        $this->assertSame('Déposée par Paul Martin', $entries[0]->detail);
        $this->assertSame(['lucide:file-up', 'lucide:loader-circle', 'lucide:circle-check'], array_column($this->rows($entries), 'icon'));
    }

    public function testAFailureGivesAClientFriendlyCauseForEveryReason(): void
    {
        foreach (FailureReason::cases() as $reason) {
            $document = $this->document();
            $document->startAnalysis('ref');
            $document->failAnalysis($reason);

            $failure = new ClientTimelinePresenter()->present($document)[2];

            $this->assertSame('Échec de l\'analyse', $failure->label, $reason->name);
            $this->assertNotEmpty($failure->detail, $reason->name);
            $this->assertStringEndsWith('.', (string) $failure->detail);
            $this->assertStringNotContainsString($reason->value, (string) $failure->detail, 'No technical code on the client side.');
        }
    }

    public function testARetryIsAttributedToTheTeamNeverToTheAdministrator(): void
    {
        $document = $this->document();
        $document->startAnalysis('ref-1');
        $document->failAnalysis(FailureReason::Interrupted);
        $document->retryAnalysis($this->admin);
        $document->startAnalysis('ref-2');

        $entries = new ClientTimelinePresenter()->present($document);

        $this->assertSame(
            ['Pièce reçue', 'Analyse démarrée', 'Échec de l\'analyse', 'Analyse relancée par l\'équipe OWNER', 'Analyse démarrée'],
            array_column($this->rows($entries), 'label'),
        );
        $this->assertSame('Tentative n° 2', $entries[4]->detail);
        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('Sophie', $entry->label . $entry->detail);
            $this->assertStringNotContainsString('Laurent', $entry->label . $entry->detail);
        }
    }

    private function document(): Document
    {
        return new Document($this->client, $this->uploader, 'scan.pdf', 'key', 'application/pdf', 10, str_repeat('a', 64));
    }

    /**
     * @param list<\App\DocumentPage\TimelineEntry> $entries
     *
     * @return list<array{label: string, icon: string}>
     */
    private function rows(array $entries): array
    {
        return array_map(static fn ($entry): array => ['label' => $entry->label, 'icon' => $entry->icon], $entries);
    }
}
