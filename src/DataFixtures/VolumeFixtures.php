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
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * Load testing dataset: many small clients and one very large one (about 5,000 documents in total).
 *
 * Memory stays flat: documents are written by batches, then the entity manager is cleared. Clients are
 * therefore reloaded from their identifier after each batch instead of being kept (they would be detached).
 */
final class VolumeFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const int CLIENTS = 40;
    private const int BIG_CLIENT_DOCUMENTS = 3000;
    private const int BATCH_SIZE = 200;
    private const int HISTORY_DAYS = 420;
    private const array TRADES = ['Boulangerie', 'Pâtisserie', 'Garage', 'Cabinet dentaire', 'Salon de coiffure', 'Fleuriste', 'Restaurant', 'Pharmacie', 'Librairie', 'Plomberie', 'Électricité', 'Menuiserie', 'Auto-école', 'Café', 'Épicerie', 'Cabinet vétérinaire', 'Institut de beauté', 'Atelier', 'Bar-tabac', 'Traiteur'];
    private const array NAMES = ['Durand', 'Lefèvre', 'Moreau', 'Girard', 'Bonnet', 'Fontaine', 'Roux', 'Vincent', 'Faure', 'Mercier', 'Blanc', 'Guérin', 'Muller', 'Henry', 'Rousseau', 'Clément', 'Gauthier', 'Perrin', 'Morel', 'Chevalier', 'Lambert', 'Masson', 'Marchand', 'Dumas', 'Brun', 'Giraud', 'Barbier', 'Arnaud', 'Roy', 'Picard', 'Renard', 'Leclerc', 'Colin', 'Vidal', 'Caron', 'Meyer', 'Lopez', 'Jacquet', 'Maillard', 'Deschamps'];
    private const array FIRST_NAMES = ['Julien', 'Camille', 'Nicolas', 'Sandrine', 'Thomas', 'Émilie', 'Laurent', 'Céline', 'Antoine', 'Isabelle'];
    private const array SUPPLIERS = ['Metro', 'Sysco', 'Edf', 'Orange-Pro', 'Point-P', 'Rexel', 'Brake', 'Leclerc-Pro'];
    private const array BANKS = ['CIC', 'Credit-Agricole', 'BNP-Paribas', 'Banque-Populaire', 'Credit-Mutuel'];

    private Randomizer $random;

    public function __construct(
        private readonly DocumentFactory $factory,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly QueueDocumentUpdateListener $liveUpdates,
    ) {
        $this->random = new Randomizer(new Mt19937(42));
    }

    public function getDependencies(): array
    {
        return [AccountFixtures::class];
    }

    public static function getGroups(): array
    {
        return ['volume'];
    }

    public function load(ObjectManager $manager): void
    {
        $this->liveUpdates->mute();
        $now = now();

        try {
            $targets = $this->createClients($manager);

            foreach ($targets as [$clientId, $userId, $count]) {
                $this->loadDocuments($manager, $clientId, $userId, $count, $now);
            }
        } finally {
            Clock::set(new NativeClock());
        }
    }

    /**
     * @return list<array{Uuid, Uuid, int}> client id, account id and number of documents to create
     */
    private function createClients(ObjectManager $manager): array
    {
        Clock::set(new MockClock('-15 months'));

        $names = $this->random->shuffleArray(self::NAMES);
        $slugger = new AsciiSlugger();
        $targets = [];
        $hashedPassword = null;

        for ($i = 0; $i <= self::CLIENTS; ++$i) {
            $isBig = self::CLIENTS === $i;
            $name = $isBig ? 'Groupe Delmas Restauration' : self::TRADES[$i % \count(self::TRADES)] . ' ' . $names[$i];

            $client = new Client($name);
            $user = User::forClient($client, \sprintf('contact@%s.test', $slugger->slug($name)->lower()), self::FIRST_NAMES[$i % \count(self::FIRST_NAMES)] . ' ' . ($isBig ? 'Delmas' : $names[$i]));
            // Same password for every account: hash it once, bcrypt is slow by design.
            $hashedPassword ??= $this->passwordHasher->hashPassword($user, AccountFixtures::DEMO_PASSWORD);
            $user->setPassword($hashedPassword);

            $manager->persist($client);
            $manager->persist($user);
            $targets[] = [$client->getId(), $user->getId(), $isBig ? self::BIG_CLIENT_DOCUMENTS : $this->random->getInt(3, 100)];
        }
        $manager->flush();
        $manager->clear();

        return $targets;
    }

    private function loadDocuments(ObjectManager $manager, Uuid $clientId, Uuid $userId, int $count, \DateTimeImmutable $now): void
    {
        foreach (array_chunk($this->uploadTimes($count, $now), self::BATCH_SIZE) as $batch) {
            $client = $manager->find(Client::class, $clientId) ?? throw new \LogicException('Client not found.');
            $uploader = $manager->find(User::class, $userId) ?? throw new \LogicException('User not found.');

            foreach ($batch as $uploadedAt) {
                [$filename, $type, $month] = $this->describe($uploadedAt);

                $this->factory->at($uploadedAt);
                $document = $this->factory->create($client, $uploader, $filename, $type, $month, $month?->modify('last day of this month'));
                $this->play($document, $uploadedAt > $now->modify('-3 hours'));
                $manager->persist($document);
            }

            $manager->flush();
            $manager->clear();
        }
    }

    /**
     * Mostly analyzed, a few failures; documents of the last hours are still in the queue.
     */
    private function play(Document $document, bool $recent): void
    {
        if ($recent) {
            if (0 === $this->random->getInt(0, 1)) {
                $this->factory->start($document);
            }

            return;
        }

        $this->factory->start($document);
        $this->factory->finish($document, $this->random->getInt(1, 100) <= 4 ? $this->pick(FailureReason::cases()) : null);
    }

    /**
     * @return array{string, ?DocumentType, ?\DateTimeImmutable} filename, type and period start
     */
    private function describe(\DateTimeImmutable $uploadedAt): array
    {
        $month = $uploadedAt->modify('first day of last month')->setTime(0, 0);
        $monthName = DocumentFactory::monthName($month);
        $year = $month->format('Y');

        [$filename, $type, $period] = match ($this->random->getInt(1, 10)) {
            1, 2, 3 => ['Facture-' . $this->pick(self::SUPPLIERS) . '-' . $month->format('Y-m') . '.pdf', DocumentType::Other, $month],
            4, 5 => ['Releve-bancaire-' . $this->pick(self::BANKS) . "-$monthName-$year.pdf", DocumentType::Other, $month],
            6 => ["Bulletins-salaire-$monthName-$year.pdf", DocumentType::Payslip, $month],
            7 => ['DSN-' . $month->format('Y-m') . '.pdf', DocumentType::Dsn, $month],
            8 => ['IMG_' . $uploadedAt->format('Ymd_Hi') . '.jpg', null, null],
            9 => ['Contrat-CDI-' . $this->pick(self::FIRST_NAMES) . '.pdf', DocumentType::EmploymentContract, null],
            default => $this->pick([
                ['Extrait-Kbis-' . $uploadedAt->format('Y') . '.pdf', DocumentType::Kbis, null],
                ['Bilan-' . ((int) $year - 1) . '.pdf', DocumentType::AnnualAccounts, null],
                ['Avis-impot-' . ((int) $year - 1) . '.pdf', DocumentType::TaxNotice, null],
                ['Attestation-mutuelle-' . $year . '.pdf', DocumentType::HealthInsurance, null],
            ]),
        };

        // A client sometimes skips the optional type.
        return [$filename, 0 === $this->random->getInt(0, 9) ? null : $type, $period];
    }

    /**
     * @template T
     *
     * @param non-empty-list<T> $items
     *
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[$this->random->getInt(0, \count($items) - 1)];
    }

    /**
     * Sorted upload instants: the history, then a few documents from the last hours, one per fiftieth at least.
     *
     * @return list<\DateTimeImmutable>
     */
    private function uploadTimes(int $count, \DateTimeImmutable $now): array
    {
        $recent = max(1, intdiv($count, 50));
        $times = [];
        for ($i = 0; $i < $count - $recent; ++$i) {
            $times[] = $now->modify('-' . $this->random->getInt(1, self::HISTORY_DAYS) . ' days')->setTime($this->random->getInt(8, 18), $this->random->getInt(0, 59), $this->random->getInt(0, 59));
        }
        for ($i = 0; $i < $recent; ++$i) {
            $times[] = $now->modify('-' . $this->random->getInt(1, 180) . ' minutes');
        }
        sort($times);

        return $times;
    }
}
