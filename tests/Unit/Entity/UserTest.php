<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Client;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testClientAccountIsAttachedToItsClient(): void
    {
        $client = new Client('Boulangerie Martin');
        $user = User::forClient($client, 'Paul.Martin@Example.com', 'Paul Martin');

        $this->assertSame($client, $user->getClient());
        $this->assertSame('paul.martin@example.com', $user->getUserIdentifier());
        $this->assertSame([User::ROLE_CLIENT, 'ROLE_USER'], $user->getRoles());
        $this->assertFalse($user->isAdmin());
    }

    public function testAdminAccountHasNoClient(): void
    {
        $admin = User::admin('equipe@owner.test', 'Équipe OWNER');

        $this->assertNull($admin->getClient());
        $this->assertTrue($admin->isAdmin());
        $this->assertContains(User::ROLE_ADMIN, $admin->getRoles());
    }
}
