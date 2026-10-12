<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseConnectionTest extends KernelTestCase
{
    public function testPostgresIsReachable(): void
    {
        $connection = self::getContainer()->get(Connection::class);

        $this->assertSame(1, (int) $connection->fetchOne('SELECT 1'));
        $this->assertStringStartsWith('18', (string) $connection->fetchOne('SHOW server_version'));
    }
}
