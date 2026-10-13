<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Development helpers (styleguide, error page previews) must never be routable in production.
 */
final class ProductionRoutesTest extends TestCase
{
    public function testDevelopmentRoutesAreNotLoadedInProduction(): void
    {
        $kernel = new Kernel('prod', false);
        $kernel->boot();

        try {
            $router = $kernel->getContainer()->get('router');
            $this->assertInstanceOf(RouterInterface::class, $router);

            $paths = array_map(
                static fn ($route): string => $route->getPath(),
                $router->getRouteCollection()->all(),
            );

            $this->assertContains('/connexion', $paths, 'Sanity check: production routes are loaded.');
            foreach ($paths as $name => $path) {
                $this->assertStringStartsNotWith('/_', $path, \sprintf('Route "%s" (%s) must not exist in production.', $name, $path));
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
