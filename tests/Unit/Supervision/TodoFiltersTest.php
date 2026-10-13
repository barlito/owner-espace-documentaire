<?php

declare(strict_types=1);

namespace App\Tests\Unit\Supervision;

use App\Enum\FailureReason;
use App\Supervision\TodoFilters;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class TodoFiltersTest extends TestCase
{
    public function testNoParameterMeansNoFilter(): void
    {
        $filters = TodoFilters::fromArray([]);

        $this->assertFalse($filters->isActive());
        $this->assertSame([], $filters->query());
    }

    public function testValidParametersAreRead(): void
    {
        $client = Uuid::v7()->toRfc4122();

        $filters = TodoFilters::fromArray(['client' => $client, 'raison' => 'unreadable_file', 'q' => '  relevé ']);

        $this->assertSame($client, $filters->clientId?->toRfc4122());
        $this->assertSame(FailureReason::UnreadableFile, $filters->reason);
        $this->assertSame('relevé', $filters->search);
        $this->assertSame(['q' => 'relevé', 'client' => $client, 'raison' => 'unreadable_file'], $filters->query());
    }

    public function testMalformedValuesAreDroppedAsIfNotSent(): void
    {
        $filters = TodoFilters::fromArray(['client' => 'nope', 'raison' => 'whatever', 'q' => ['array']]);

        $this->assertFalse($filters->isActive());
    }

    public function testTheFiltersCanBeRemovedOneByOne(): void
    {
        $filters = TodoFilters::fromArray(['client' => Uuid::v7()->toRfc4122(), 'raison' => 'unknown', 'q' => 'a']);

        $this->assertSame(['q', 'raison'], array_keys($filters->without('client')->query()));
        $this->assertSame(['q', 'client'], array_keys($filters->without('raison')->query()));
        $this->assertSame(['client', 'raison'], array_keys($filters->without('q')->query()));
    }
}
