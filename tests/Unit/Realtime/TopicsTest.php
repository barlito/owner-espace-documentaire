<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime;

use App\Realtime\Topics;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class TopicsTest extends TestCase
{
    public function testEachClientHasItsOwnTopic(): void
    {
        $martin = Uuid::v7();
        $petitsPas = Uuid::v7();

        $this->assertSame(\sprintf('/clients/%s/documents', $martin->toRfc4122()), Topics::client($martin));
        $this->assertNotSame(Topics::client($martin), Topics::client($petitsPas));
    }
}
