<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Exception\DriverException;

trait DatabaseConstraintAssertions
{
    /**
     * Asserts that the statement is rejected by the given PostgreSQL constraint.
     */
    private function assertConstraintViolation(string $sqlState, string $constraint, callable $statement): void
    {
        try {
            $statement();
            $this->fail(\sprintf('Constraint "%s" should have been violated.', $constraint));
        } catch (DriverException $exception) {
            $this->assertSame($sqlState, $exception->getSQLState());
            $this->assertStringContainsString($constraint, $exception->getMessage());
        }
    }
}
