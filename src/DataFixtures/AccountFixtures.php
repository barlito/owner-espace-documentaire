<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Client;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo accounts: the administrator and the two demo clients, referenced by the document fixtures.
 */
final class AccountFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public const string DEMO_PASSWORD = 'demo1234';

    public const string ADMIN = 'user-admin';
    public const string MARTIN_CLIENT = 'client-martin';
    public const string MARTIN_USER = 'user-martin';
    public const string PETITS_PAS_CLIENT = 'client-petits-pas';
    public const string PETITS_PAS_USER = 'user-petits-pas';

    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function getDependencies(): array
    {
        return [StoragePurgeFixtures::class];
    }

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function load(ObjectManager $manager): void
    {
        // The clients exist before the history of documents the other fixtures give them.
        Clock::set(new MockClock('-15 months'));

        try {
            $martin = new Client('Boulangerie Martin');
            $petitsPas = new Client('Micro-crèche Les Petits Pas');

            $accounts = [
                self::ADMIN => User::admin('admin@owner.test', 'Sophie Laurent'),
                self::MARTIN_USER => User::forClient($martin, 'paul@boulangerie-martin.test', 'Paul Martin'),
                self::PETITS_PAS_USER => User::forClient($petitsPas, 'claire@lespetitspas.test', 'Claire Dubois'),
            ];

            $manager->persist($martin);
            $manager->persist($petitsPas);
            $this->addReference(self::MARTIN_CLIENT, $martin);
            $this->addReference(self::PETITS_PAS_CLIENT, $petitsPas);

            foreach ($accounts as $reference => $user) {
                $user->setPassword($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));
                $manager->persist($user);
                $this->addReference($reference, $user);
            }

            $manager->flush();
        } finally {
            Clock::set(new NativeClock());
        }
    }
}
