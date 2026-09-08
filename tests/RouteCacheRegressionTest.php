<?php

declare(strict_types=1);

namespace Omegaalfa\SwiftRouter\Tests;

use Omegaalfa\SwiftRouter\Router\SwiftRouter;
use Omegaalfa\SwiftRouter\Router\TreeRouter;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class RouteCacheRegressionTest extends TestCase
{
    public function testSustainedChurnPreservesExactLruOrderAndParameters(): void
    {
        $router = new SwiftRouter();
        $router->get('/users/:id', static fn () => null);
        $policy = new ReflectionProperty(TreeRouter::class, 'clockPolicy');
        $policy->setValue($router, false);
        $cache = new ReflectionProperty(TreeRouter::class, 'routeCache');
        $expected = [];

        for ($i = 0; $i < 12000; ++$i) {
            $id = $i % 4 === 0 ? 'hot-' . ($i % 128) : 'unique-' . $i;
            $key = '/GET::/users/' . $id;
            unset($expected[$key]);
            $expected[$key] = true;
            if (count($expected) > 2048) {
                unset($expected[array_key_first($expected)]);
            }

            self::assertSame(['id' => $id], $router->findRoute('GET', '/users/' . $id)['params']);
            if ($i % 127 === 0) {
                self::assertSame(array_keys($expected), array_keys($cache->getValue($router)));
            }
        }

        self::assertSame(array_keys($expected), array_keys($cache->getValue($router)));
        self::assertSame(2048, $router->getStats()['cached_routes']);
        $router->clearCache();
        self::assertSame([], $cache->getValue($router));
        self::assertSame(['id' => 'fresh'], $router->findRoute('GET', '/users/fresh')['params']);
    }

    public function testClockVariantRespectsLimitSecondChanceResizeAndClear(): void
    {
        $router = new SwiftRouter();
        $router->get('/users/:id', static fn () => null);
        $policy = new \ReflectionProperty(TreeRouter::class, 'clockPolicy');
        $policy->setValue($router, true);
        foreach (['a', 'b'] as $id) {
            self::assertSame(['id' => $id], $router->findRoute('GET', '/users/' . $id)['params']);
        }
        // Hit a receives a second chance; b is evicted first.
        $router->findRoute('GET', '/users/a');
        $router->setCacheLimit(2);
        $router->findRoute('GET', '/users/c');
        self::assertSame(['id' => 'a'], $router->findRoute('GET', '/users/a')['params']);
        self::assertLessThanOrEqual(2, $router->getStats()['cached_routes']);
        $router->setCacheLimit(1);
        self::assertLessThanOrEqual(1, $router->getStats()['cached_routes']);
        $router->clearCache();
        self::assertSame(0, $router->getStats()['cached_routes']);
        self::assertSame(['id' => 'fresh'], $router->findRoute('GET', '/users/fresh')['params']);
    }
}
