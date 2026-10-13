<?php

declare(strict_types=1);

namespace App\Tests\Unit\Supervision;

use App\Supervision\ReturnPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReturnPathTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testBackOfficePathsAreAccepted(string $path): void
    {
        $this->assertSame($path, ReturnPath::parse($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function accepted(): iterable
    {
        yield 'supervision' => ['/admin'];
        yield 'with filters' => ['/admin?raison=unreadable_file&q=relev%C3%A9&page=2'];
        yield 'clients' => ['/admin/clients'];
        yield 'client' => ['/admin/clients/01a13599-0b0d-71a4-b933-fd1c91ea1eec?statut=failed'];
    }

    #[DataProvider('refused')]
    public function testAnythingElseIsRefused(mixed $value): void
    {
        $this->assertNull(ReturnPath::parse($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refused(): iterable
    {
        yield 'null' => [null];
        yield 'array' => [['/admin']];
        yield 'empty' => [''];
        yield 'absolute' => ['https://evil.test/admin'];
        yield 'scheme-relative' => ['//evil.test'];
        yield 'client space' => ['/espace'];
        yield 'similar prefix' => ['/administrator'];
        yield 'userinfo' => ['/admin@evil.test'];
        yield 'backslash' => ['/admin/\\evil.test'];
        yield 'space' => ['/admin /x'];
        yield 'line break' => ["/admin\n/x"];
        yield 'too long' => ['/admin?' . str_repeat('a', 600)];
    }

    public function testTheBackLinkIsWordedAfterTheListItLeadsTo(): void
    {
        $this->assertSame('Retour à la supervision', ReturnPath::label('/admin?page=2'));
        $this->assertSame('Retour aux clients', ReturnPath::label('/admin/clients?q=a'));
        $this->assertSame('Retour au dossier du client', ReturnPath::label('/admin/clients/01a13599-0b0d-71a4-b933-fd1c91ea1eec'));
    }
}
