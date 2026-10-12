<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Client;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo accounts only: documents are created through the application.
 */
final class AppFixtures extends Fixture
{
    public const string DEMO_PASSWORD = 'demo1234';

    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $martin = new Client('Boulangerie Martin');
        $petitsPas = new Client('Micro-crèche Les Petits Pas');

        $users = [
            User::admin('admin@owner.test', 'Équipe OWNER'),
            User::forClient($martin, 'paul@boulangerie-martin.test', 'Paul Martin'),
            User::forClient($petitsPas, 'claire@lespetitspas.test', 'Claire Dubois'),
        ];

        $manager->persist($martin);
        $manager->persist($petitsPas);
        foreach ($users as $user) {
            $user->setPassword($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));
            $manager->persist($user);
        }

        $manager->flush();
    }
}
