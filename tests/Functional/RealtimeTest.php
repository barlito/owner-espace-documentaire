<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\User;
use App\Realtime\Topics;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RealtimeTest extends WebTestCase
{
    use CreatesAccounts;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
    }

    public function testDashboardRowsCarryTheIdTheLiveUpdatesTarget(): void
    {
        $paul = $this->createClientUser();
        $document = $this->createDocumentFor($paul, 'Bilan.pdf');
        $this->browser->loginUser($paul);

        $crawler = $this->browser->request('GET', '/espace');

        $this->assertCount(1, $crawler->filter(\sprintf('ul#dashboard-documents > li#document-%s', $document->getId()->toRfc4122())));
    }

    public function testAnEmptyDashboardKeepsATargetForTheFirstDocument(): void
    {
        $this->browser->loginUser($this->createClientUser());

        $crawler = $this->browser->request('GET', '/espace');

        $this->assertCount(1, $crawler->filter('#dashboard-empty'));
        $this->assertCount(1, $crawler->filter('ul#dashboard-documents'));
    }

    public function testClientPagesListenToTheirOwnTopicOnlyAndHaveOneLiveRegion(): void
    {
        $paul = $this->createClientUser();
        $this->browser->loginUser($paul);
        $topic = Topics::client($this->clientId($paul));

        foreach (['/espace', '/espace/depot'] as $path) {
            $crawler = $this->browser->request('GET', $path);

            $source = $crawler->filter('turbo-mercure-stream-source[private]');
            $this->assertCount(1, $source, $path);
            $query = (string) parse_url((string) $source->attr('src'), \PHP_URL_QUERY);
            parse_str($query, $params);
            $this->assertSame($topic, $params['topic'] ?? null, $path);
            $this->assertCount(1, $crawler->filter('[aria-live]'), $path);
            $this->assertCount(1, $crawler->filter('#live-status[aria-live=polite]'), $path);
        }
    }

    public function testTheSubscriptionCookieGrantsTheConnectedClientsTopicAndNothingElse(): void
    {
        $paul = $this->createClientUser();
        $claire = $this->createClientUser('Micro-crèche Les Petits Pas', 'claire@lespetitspas.test');
        $this->browser->loginUser($paul);

        $this->browser->request('GET', '/espace');

        $claims = $this->subscriptionClaims();
        $this->assertSame([Topics::client($this->clientId($paul))], $claims['mercure']['subscribe']);
        $this->assertNotContains(Topics::client($this->clientId($claire)), $claims['mercure']['subscribe']);
        $this->assertArrayNotHasKey('publish', $claims['mercure'], 'A browser can never publish.');
    }

    public function testEachClientGetsItsOwnCookie(): void
    {
        $paul = $this->createClientUser();
        $claire = $this->createClientUser('Micro-crèche Les Petits Pas', 'claire@lespetitspas.test');

        $this->browser->loginUser($claire);
        $this->browser->request('GET', '/espace/depot');

        $this->assertSame([Topics::client($this->clientId($claire))], $this->subscriptionClaims()['mercure']['subscribe']);
        $this->assertNotSame(Topics::client($this->clientId($paul)), $this->subscriptionClaims()['mercure']['subscribe'][0]);
    }

    public function testAdminPagesListenToTheAdminTopicOnlyAndGrantNoClientTopic(): void
    {
        $paul = $this->createClientUser();
        $document = $this->createDocumentFor($paul, 'Bilan.pdf');
        $this->browser->loginUser($this->createAdmin());

        foreach (['/admin', '/admin/pieces/' . $document->getId()->toRfc4122()] as $path) {
            $crawler = $this->browser->request('GET', $path);

            $this->assertResponseIsSuccessful();
            $source = $crawler->filter('turbo-mercure-stream-source[private]');
            $this->assertCount(1, $source, $path);
            parse_str((string) parse_url((string) $source->attr('src'), \PHP_URL_QUERY), $params);
            $this->assertSame(Topics::admin(), $params['topic'] ?? null, $path);
            $this->assertSame([Topics::admin()], $this->subscriptionClaims()['mercure']['subscribe'], $path);
            $this->assertArrayNotHasKey('publish', $this->subscriptionClaims()['mercure']);
            $this->assertCount(1, $crawler->filter('[aria-live]'), $path);
        }
    }

    public function testBackOfficePagesWithNothingLiveGrantNoSubscription(): void
    {
        $paul = $this->createClientUser();
        $this->browser->loginUser($this->createAdmin());

        foreach (['/admin/clients', '/admin/clients/' . $paul->getClient()?->getId()] as $path) {
            $crawler = $this->browser->request('GET', $path);

            $this->assertResponseIsSuccessful();
            $this->assertCount(0, $crawler->filter('turbo-mercure-stream-source'), $path);
            $this->assertNull($this->authorizationCookie(), $path);
        }
    }

    public function testNoClientPageEverGetsTheAdminTopic(): void
    {
        $paul = $this->createClientUser();
        $document = $this->createDocumentFor($paul, 'Bilan.pdf');
        $this->browser->loginUser($paul);

        foreach (['/espace', '/espace/depot', '/espace/pieces/' . $document->getId()->toRfc4122()] as $path) {
            $crawler = $this->browser->request('GET', $path);

            $this->assertResponseIsSuccessful();
            $this->assertStringNotContainsString(rawurlencode(Topics::admin()), (string) $crawler->html(), $path);
            $this->assertNotContains(Topics::admin(), $this->subscriptionClaims()['mercure']['subscribe'], $path);
            $this->assertSame([Topics::client($this->clientId($paul))], $this->subscriptionClaims()['mercure']['subscribe'], $path);
        }
    }

    public function testTheUploadResponseCarriesTheStatusMarkupLiveUpdatesReplace(): void
    {
        $paul = $this->createClientUser();
        $this->browser->loginUser($paul);
        $crawler = $this->browser->request('GET', '/espace/depot');
        $token = (string) $crawler->filter('[data-upload-token-value]')->attr('data-upload-token-value');
        $file = new \Symfony\Component\HttpFoundation\File\UploadedFile(__DIR__ . '/../Fixtures/files/sample.pdf', 'Bilan.pdf', 'application/pdf', null, true);

        $this->browser->request('POST', '/espace/depot/fichier', ['_token' => $token], ['file' => $file]);

        $this->assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertStringContainsString(\sprintf('id="upload-status-%s"', $payload['id']), $payload['status_html']);
        $this->assertStringContainsString('En attente d&#039;analyse', $payload['status_html']);
    }

    private function createDocumentFor(User $user, string $filename): Document
    {
        $client = $user->getClient() ?? throw new \LogicException('Client account expected.');
        $document = new Document($client, $user, $filename, 'key-' . bin2hex(random_bytes(4)), 'application/pdf', 1024, str_repeat('a', 64));
        $this->em()->persist($document);
        $this->em()->flush();

        return $document;
    }

    private function clientId(User $user): \Symfony\Component\Uid\Uuid
    {
        return ($user->getClient() ?? throw new \LogicException('Client account expected.'))->getId();
    }

    private function authorizationCookie(): ?string
    {
        foreach ($this->browser->getResponse()->headers->getCookies() as $cookie) {
            if ('mercureAuthorization' === $cookie->getName()) {
                return $cookie->getValue();
            }
        }

        return null;
    }

    /**
     * Claims of the subscriber JWT sent to the browser (its signature is the hub's concern).
     *
     * @return array{mercure: array{subscribe: list<string>}}
     */
    private function subscriptionClaims(): array
    {
        $jwt = $this->authorizationCookie() ?? throw new \LogicException('No mercureAuthorization cookie was set.');
        $payload = base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/'), true);

        return json_decode((string) $payload, true, flags: \JSON_THROW_ON_ERROR);
    }
}
