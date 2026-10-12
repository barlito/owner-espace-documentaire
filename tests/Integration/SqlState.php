<?php

declare(strict_types=1);

namespace App\Tests\Integration;

/**
 * PostgreSQL error codes used to assert which kind of constraint rejected a statement.
 */
final class SqlState
{
    public const string CHECK_VIOLATION = '23514';
    public const string UNIQUE_VIOLATION = '23505';
}
