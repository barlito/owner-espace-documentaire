<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Persists accounts with a hashed password; DAMA rolls the data back after each test.
 */
trait CreatesAccounts
{
    private const string PASSWORD = 'secret-pass-1';

    private function createClientUser(string $companyName = 'Boulangerie Martin', string $email = 'paul@boulangerie-martin.test'): User
    {
        $client = new Client($companyName);
        $user = User::forClient($client, $email, 'Paul Martin');
        $this->em()->persist($client);

        return $this->persistWithPassword($user);
    }

    private function createAdmin(): User
    {
        return $this->persistWithPassword(User::admin('admin@owner.test', 'Sophie Laurent'));
    }

    private function persistWithPassword(User $user): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
