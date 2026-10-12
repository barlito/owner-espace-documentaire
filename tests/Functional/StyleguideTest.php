<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StyleguideTest extends WebTestCase
{
    public function testStyleguideRendersSharedComponents(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/_styleguide');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html[lang="fr"]');
        $this->assertSelectorExists('a[href="#contenu"]');
        $this->assertSelectorTextContains('h1', 'Vos pièces');
        $this->assertCount(1, $crawler->filter('[role="alert"]'));
        $this->assertSelectorExists('a.border-navy-500[href="#"]');
        $this->assertSelectorExists('button[type="button"].bg-primary-500');
    }
}
