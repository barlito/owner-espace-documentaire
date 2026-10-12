<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityTest extends WebTestCase
{
    use CreatesAccounts;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
    }

    /**
     * #[\PHPUnit\Framework\Attributes\DataProvider('protectedUrls')]
     * public function testAnonymousVisitorIsSentToTheLoginPage(string $url): void
     * {
     * $this->browser->request('GET', $url);
     *
     * $this->assertResponseRedirects('http://localhost/connexion');
     * }
     * /**
     * @return iterable<string, array{string}>
     */
    public static function protectedUrls(): iterable
    {
        yield 'client space' => ['/espace'];
        yield 'admin space' => ['/admin'];
        yield 'home' => ['/'];
    }

    public function testClientLogsInThroughTheFormAndLandsOnItsSpace(): void
    {
        $user = $this->createClientUser();

        $this->submitLoginForm($user->getEmail(), self::PASSWORD);
        $this->assertResponseRedirects('/');

        $this->browser->followRedirect();
        $this->assertResponseRedirects('/espace');

        $this->browser->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Mon dossier');
        $this->assertSelectorTextContains('main', 'Boulangerie Martin');
        $this->assertSelectorTextContains('header', 'Paul Martin');
    }

    public function testAdminLogsInAndLandsOnSupervision(): void
    {
        $admin = $this->createAdmin();

        $this->submitLoginForm($admin->getEmail(), self::PASSWORD);
        $this->browser->followRedirect();
        $this->assertResponseRedirects('/admin');
    }

    public function testWrongPasswordStaysOnTheLoginPageWithAFrenchError(): void
    {
        $user = $this->createClientUser();

        $this->submitLoginForm($user->getEmail(), 'wrong-password');
        $this->assertResponseRedirects('/connexion');

        $this->browser->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[role="alert"]', 'Identifiants invalides.');
        $this->assertInputValueSame('_username', $user->getEmail());
    }

    public function testLoginPageDoesNotAdvertiseDemoAccountsOutsideDev(): void
    {
        $this->browser->request('GET', '/connexion');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('label[for="username"]');
        $this->assertSelectorExists('label[for="password"]');
        $this->assertSelectorNotExists('#demo-accounts');
        $this->assertSelectorTextNotContains('body', 'demo1234');
    }

    public function testClientCannotAccessTheAdminSpace(): void
    {
        $this->browser->loginUser($this->createClientUser());

        $this->browser->request('GET', '/admin');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCannotAccessTheClientSpace(): void
    {
        $this->browser->loginUser($this->createAdmin());

        $this->browser->request('GET', '/espace');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesAllClientsWithTheirDocumentCount(): void
    {
        $this->createClientUser('Boulangerie Martin', 'paul@boulangerie-martin.test');
        $this->createClientUser('Micro-crèche Les Petits Pas', 'claire@lespetitspas.test');
        $this->browser->loginUser($this->createAdmin());

        $this->browser->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Supervision');
        $this->assertSelectorTextContains('tbody', 'Boulangerie Martin');
        $this->assertSelectorTextContains('tbody', 'Micro-crèche Les Petits Pas');
        $this->assertSelectorTextContains('tbody', '0 document');
        $this->assertSelectorTextContains('header', 'Équipe OWNER');
    }

    public function testLogoutRequiresPostAndEndsTheSession(): void
    {
        $this->browser->loginUser($this->createClientUser());

        $crawler = $this->browser->request('GET', '/espace');
        $this->assertResponseIsSuccessful();

        $this->browser->submit($crawler->selectButton('Déconnexion')->form());
        $this->assertResponseRedirects();

        $this->browser->request('GET', '/espace');
        $this->assertResponseRedirects('http://localhost/connexion');
    }

    public function testLogoutWithoutCsrfTokenIsRejected(): void
    {
        $this->browser->loginUser($this->createClientUser());

        $this->browser->request('POST', '/deconnexion');
        $this->browser->request('GET', '/espace');

        $this->assertResponseIsSuccessful();
    }

    private function submitLoginForm(string $email, string $password): void
    {
        $crawler = $this->browser->request('GET', '/connexion');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => $email,
            '_password' => $password,
        ]);
        $this->browser->submit($form);
    }
}
