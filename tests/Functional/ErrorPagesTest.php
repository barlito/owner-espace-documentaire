<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

/**
 * Error pages are only rendered when debug is off, so these tests boot the kernel without debug.
 */
final class ErrorPagesTest extends WebTestCase
{
    use CreatesAccounts;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = static::createClient(['debug' => false]);
    }

    public function testUnknownPageShowsTheFrenchNotFoundPage(): void
    {
        $this->browser->request('GET', '/page-qui-n-existe-pas');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSelectorTextContains('h1', 'Page introuvable');
        $this->assertSelectorTextContains('main', 'ou n\'est pas accessible depuis votre compte');
        $this->assertSelectorExists('a[href="/"]');
    }

    public function testForbiddenSpaceShowsTheFrenchAccessDeniedPage(): void
    {
        $this->browser->loginUser($this->createClientUser());
        $this->browser->request('GET', '/admin');

        $this->assertResponseStatusCodeSame(403);
        $this->assertSelectorTextContains('h1', 'Accès refusé');
    }

    /**
     * Status codes that are hard to trigger through HTTP in a test are checked on the template itself.
     */
    #[DataProvider('statusCodes')]
    public function testEveryStatusCodeHasAnExplicitFrenchTitle(int $statusCode, string $expectedTitle): void
    {
        $html = static::getContainer()->get(Environment::class)->render('@Twig/Exception/error.html.twig', [
            'status_code' => $statusCode,
            'status_text' => '',
            'exception' => null,
        ]);

        $this->assertStringContainsString('<h1 class="text-lg">' . $expectedTitle . '</h1>', $html);
        $this->assertStringContainsString('Erreur ' . $statusCode, $html);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function statusCodes(): iterable
    {
        yield 'bad request' => [400, 'Demande invalide'];
        yield 'method not allowed' => [405, 'Action indisponible'];
        yield 'payload too large' => [413, 'Envoi trop volumineux'];
        yield 'too many requests' => [429, 'Trop de tentatives'];
        yield 'server error' => [500, 'Une erreur est survenue'];
        yield 'unavailable' => [503, 'Une erreur est survenue'];
        yield 'other client error' => [418, 'Demande impossible'];
    }
}
