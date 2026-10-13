<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Client;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\FailureReason;
use App\EventListener\QueueDocumentUpdateListener;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\NativeClock;

use function Symfony\Component\Clock\now;

/**
 * About 14 months of history for the two demo clients, in every status, to demonstrate the application.
 *
 * Each document is played through the real lifecycle methods with a frozen clock, so that attempts and
 * timeline are consistent with its upload date. The outcome of a few documents is scripted per client
 * (by position from the newest), the others are analyzed.
 *
 * Scripted outcomes: a FailureReason (failed), 'pending', 'processing',
 * 'retried' (failed, relaunched by the admin, then analyzed) or 'retried-pending' (relaunched, not picked up yet).
 *
 * @phpstan-type Upload array{at: \DateTimeImmutable, filename: string, type: ?DocumentType, month: ?\DateTimeImmutable}
 */
final class DemoDocumentFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const int HISTORY_MONTHS = 14;

    private Randomizer $random;

    public function __construct(
        private readonly DocumentFactory $factory,
        private readonly QueueDocumentUpdateListener $liveUpdates,
    ) {
        $this->random = new Randomizer(new Mt19937(7));
    }

    public function getDependencies(): array
    {
        return [AccountFixtures::class];
    }

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function load(ObjectManager $manager): void
    {
        $this->liveUpdates->mute();
        $now = now();
        $admin = $this->getReference(AccountFixtures::ADMIN, User::class);

        try {
            $this->loadClient($manager, AccountFixtures::MARTIN_CLIENT, AccountFixtures::MARTIN_USER, $admin, $now, $this->bakeryUploads($now), [
                4 => FailureReason::UnreadableFile,
                9 => 'retried',
                15 => FailureReason::UnsupportedFormat,
                22 => FailureReason::Interrupted,
                31 => FailureReason::Unknown,
                40 => 'retried',
            ]);
            $this->loadClient($manager, AccountFixtures::PETITS_PAS_CLIENT, AccountFixtures::PETITS_PAS_USER, $admin, $now, $this->nurseryUploads($now), [
                3 => FailureReason::UnreadableFile,
                8 => 'retried',
                13 => FailureReason::UnsupportedFormat,
                19 => FailureReason::Interrupted,
            ]);

            $manager->flush();
        } finally {
            Clock::set(new NativeClock());
        }
    }

    /**
     * @param list<Upload>                     $uploads
     * @param array<int, FailureReason|string> $scripted position from the newest historical upload => outcome
     */
    private function loadClient(ObjectManager $manager, string $clientReference, string $userReference, User $admin, \DateTimeImmutable $now, array $uploads, array $scripted): void
    {
        $client = $this->getReference($clientReference, Client::class);
        $uploader = $this->getReference($userReference, User::class);

        // Uploads from the last day are replaced by the scripted recent ones below.
        $uploads = array_filter($uploads, static fn (array $upload): bool => $upload['at'] <= $now->modify('-1 day'));
        usort($uploads, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        $count = \count($uploads);

        foreach ($uploads as $position => $upload) {
            $this->play($manager, $client, $uploader, $admin, $now, $upload, $scripted[$count - $position] ?? null);
        }

        foreach ($this->recentUploads($now) as [$minutesAgo, $outcome, $filename, $type]) {
            $this->play($manager, $client, $uploader, $admin, $now, ['at' => $now->modify("-$minutesAgo minutes"), 'filename' => $filename, 'type' => $type, 'month' => null], $outcome);
        }
    }

    /**
     * Uploads of the last hours (plus one document relaunched yesterday): what the admin sees "live".
     *
     * @return list<array{int, string, string, ?DocumentType}>
     */
    private function recentUploads(\DateTimeImmutable $now): array
    {
        $photo = static fn (int $minutesAgo): string => 'IMG_' . $now->modify("-$minutesAgo minutes")->format('Ymd_Hi') . '.jpg';

        return [
            [30 * 60, 'retried-pending', $photo(30 * 60), null],
            [90, 'pending', 'Facture-fournisseur-' . $now->format('Y-m') . '.pdf', DocumentType::Other],
            [4, 'processing', 'Note-de-frais-' . DocumentFactory::monthName($now) . '-' . $now->format('Y') . '.pdf', DocumentType::Other],
            [1, 'pending', $photo(1), null],
        ];
    }

    /**
     * @param Upload $upload
     */
    private function play(ObjectManager $manager, Client $client, User $uploader, User $admin, \DateTimeImmutable $now, array $upload, FailureReason | string | null $outcome): void
    {
        $month = $upload['month'];
        $this->factory->at($upload['at']);
        $document = $this->factory->create($client, $uploader, $upload['filename'], $upload['type'], $month, $month?->modify('last day of this month'));

        if ('pending' !== $outcome) {
            $this->factory->start($document);
        }
        if (null === $outcome || $outcome instanceof FailureReason) {
            $this->factory->finish($document, $outcome);
        } elseif (str_starts_with($outcome, 'retried')) {
            $this->retry($document, $admin, $now, 'retried' === $outcome);
        }

        $manager->persist($document);
    }

    /**
     * First attempt interrupted, relaunched by the admin hours later (never in the future, whatever the upload date).
     */
    private function retry(Document $document, User $admin, \DateTimeImmutable $now, bool $succeeds): void
    {
        $this->factory->finish($document, FailureReason::Interrupted);

        $remaining = $now->modify('-1 hour')->getTimestamp() - Clock::get()->now()->getTimestamp();
        $this->factory->advance(min($this->random->getInt(2 * 3600, 26 * 3600), max(60, $remaining)));
        $document->retryAnalysis($admin);

        if ($succeeds) {
            $this->factory->start($document);
            $this->factory->finish($document);
        }
    }

    /**
     * @return list<Upload>
     */
    private function bakeryUploads(\DateTimeImmutable $now): array
    {
        $uploads = [];
        foreach ($this->months($now) as $month) {
            $name = DocumentFactory::monthName($month);
            $year = $month->format('Y');
            $uploads[] = $this->upload($month, 'Facture-Metro-' . $month->format('Y-m') . '.pdf', DocumentType::Other);
            $uploads[] = $this->upload($month, "Releve-bancaire-CIC-$name-$year.pdf", DocumentType::Other);
            $uploads[] = $this->upload($month, "Bulletins-salaire-$name-$year.pdf", DocumentType::Payslip);
            if (0 === (int) $month->format('n') % 3) {
                $uploads[] = $this->upload($month, 'Declaration-TVA-T' . intdiv((int) $month->format('n'), 3) . "-$year.pdf", DocumentType::Other);
            }
        }

        return [
            ...$uploads,
            $this->oneOff($now, 13, 'Extrait-Kbis-boulangerie-martin.pdf', DocumentType::Kbis),
            $this->oneOff($now, 13, 'Statuts-SARL-Martin.pdf', DocumentType::Articles),
            $this->oneOff($now, 9, 'Contrat-CDI-Lea-Fontaine.pdf', DocumentType::EmploymentContract),
            $this->oneOff($now, 7, 'Attestation-mutuelle-Alan-2026.pdf', DocumentType::HealthInsurance),
            $this->oneOff($now, 6, 'Avenant-temps-travail-Hugo-Petit.pdf', DocumentType::EmploymentContract),
            $this->oneOff($now, 5, 'Bilan-2025.pdf', DocumentType::AnnualAccounts),
            $this->oneOff($now, 5, 'Avis-impot-societes-2025.pdf', DocumentType::TaxNotice),
            $this->photo($now, 11),
            $this->photo($now, 4),
        ];
    }

    /**
     * @return list<Upload>
     */
    private function nurseryUploads(\DateTimeImmutable $now): array
    {
        $uploads = [];
        foreach ($this->months($now) as $month) {
            $name = DocumentFactory::monthName($month);
            $year = $month->format('Y');
            $uploads[] = $this->upload($month, "Bulletins-salaire-$name-$year.pdf", DocumentType::Payslip);
            if (0 === (int) $month->format('n') % 3) {
                $uploads[] = $this->upload($month, 'Attestation-PSU-CAF-T' . intdiv((int) $month->format('n'), 3) . "-$year.pdf", DocumentType::Other);
            }
        }

        return [
            ...$uploads,
            $this->oneOff($now, 13, 'Extrait-Kbis-les-petits-pas.pdf', DocumentType::Kbis),
            $this->oneOff($now, 13, 'Statuts-SAS-Les-Petits-Pas.pdf', DocumentType::Articles),
            $this->oneOff($now, 12, 'Reglement-de-fonctionnement-2025.pdf', DocumentType::ChildcareRegulation),
            $this->oneOff($now, 11, 'Contrat-accueil-Lea-B.pdf', DocumentType::ChildcareRegulation),
            $this->oneOff($now, 9, 'Contrat-accueil-Noe-R.pdf', DocumentType::ChildcareRegulation),
            $this->oneOff($now, 8, 'Contrat-accueil-Ines-K.pdf', DocumentType::ChildcareRegulation),
            $this->oneOff($now, 6, 'Contrat-accueil-Jade-M.pdf', DocumentType::ChildcareRegulation),
            $this->oneOff($now, 4, 'Contrat-accueil-Lucas-D.pdf', DocumentType::ChildcareRegulation),
            $this->oneOff($now, 3, 'Contrat-accueil-Alice-T.pdf', DocumentType::ChildcareRegulation),
            $this->oneOff($now, 10, 'Reglement-interieur-equipe.pdf', DocumentType::CompanyAgreement),
            $this->oneOff($now, 7, 'Attestation-assurance-RC-2026.pdf', null),
            $this->oneOff($now, 5, 'Bilan-2025.pdf', DocumentType::AnnualAccounts),
            $this->photo($now, 2),
        ];
    }

    /**
     * First day of each of the last complete months, oldest first.
     *
     * @return list<\DateTimeImmutable>
     */
    private function months(\DateTimeImmutable $now): array
    {
        $months = [];
        for ($ago = self::HISTORY_MONTHS; $ago >= 1; --$ago) {
            $months[] = $now->modify("first day of -$ago months")->setTime(0, 0);
        }

        return $months;
    }

    /**
     * Monthly document: sent during the first days of the following month.
     *
     * @return Upload
     */
    private function upload(\DateTimeImmutable $month, string $filename, ?DocumentType $type): array
    {
        $at = $month->modify('first day of next month')->modify('+' . $this->random->getInt(1, 9) . ' days');

        // A client sometimes skips the optional type.
        return ['at' => $this->workHours($at), 'filename' => $filename, 'type' => 0 === $this->random->getInt(0, 9) ? null : $type, 'month' => $month];
    }

    /**
     * @return Upload
     */
    private function oneOff(\DateTimeImmutable $now, int $monthsAgo, string $filename, ?DocumentType $type): array
    {
        $at = $now->modify("first day of -$monthsAgo months")->modify('+' . $this->random->getInt(1, 25) . ' days');

        return ['at' => $this->workHours($at), 'filename' => $filename, 'type' => $type, 'month' => null];
    }

    /**
     * Phone photo, named after the instant it was taken like phones do.
     *
     * @return Upload
     */
    private function photo(\DateTimeImmutable $now, int $monthsAgo): array
    {
        $upload = $this->oneOff($now, $monthsAgo, '', null);
        $upload['filename'] = 'IMG_' . $upload['at']->format('Ymd_Hi') . '.jpg';

        return $upload;
    }

    private function workHours(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->setTime($this->random->getInt(8, 18), $this->random->getInt(0, 59));
    }
}
