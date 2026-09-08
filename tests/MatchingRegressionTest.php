<?php

declare(strict_types=1);

namespace Omegaalfa\SwiftRouter\Tests;

use InvalidArgumentException;
use Omegaalfa\SwiftRouter\Router\SwiftRouter;
use Omegaalfa\SwiftRouter\Router\TreeRouter;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class MatchingRegressionTest extends TestCase
{
    public function testFallbackDiscardsParametersFromFailedStaticBranch(): void
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            $router = new SwiftRouter();
            $router->addRoute($method, '/:tenant/files/new/:discard/details', static fn () => 'failed');
            $handler = static fn () => 'fallback';
            $router->addRoute($method, '/:tenant/files/:id/:action/edit', $handler);

            for ($i = 0; $i < 3; ++$i) {
                $route = $router->findRoute(strtolower($method), '/acme/files/new/value/edit');
                self::assertSame($handler, $route['handler']);
                self::assertSame(['tenant' => 'acme', 'id' => 'new', 'action' => 'value'], $route['params']);
                $router->clearCache();
            }
            self::assertNull($router->findRoute($method, '/acme/files/new/value/missing'));
        }
    }

    public function testDeepUncachedRouteAndRepeatedParameterNames(): void
    {
        $router = new SwiftRouter();
        $prefix = '/' . implode('/', array_fill(0, 128, 'segment'));
        $router->get($prefix . '/:id/:id', static fn () => null);
        for ($i = 0; $i < 10; ++$i) {
            self::assertSame(['id' => (string) $i], $router->findRoute('GET', $prefix . '/first/' . $i)['params']);
        }
    }

    public function testCachePromotionEvictionAndRegistrationPrecedence(): void
    {
        $router = new SwiftRouter();
        $router->setCacheLimit(2);
        $router->get('/users/:id', static fn () => 'dynamic');
        foreach (['a', 'b', 'a', 'c'] as $id) {
            $router->findRoute('GET', '/users/' . $id);
        }
        $cache = (new ReflectionProperty(TreeRouter::class, 'routeCache'))->getValue($router);
        self::assertSame(['/GET::/users/a', '/GET::/users/c'], array_keys($cache));

        $handler = static fn () => 'static';
        $router->get('/users/a', $handler);
        self::assertSame(0, $router->getStats()['cached_routes']);
        self::assertSame($handler, $router->findRoute('GET', '/users/a')['handler']);
    }

    public function testNormalizedStaticLookupAndReturnedArraysRemainIndependent(): void
    {
        $router = new SwiftRouter();
        $handler = static fn () => null;
        $router->get('/users/a%20b', $handler);
        $router->get('/users/:id', $handler);
        $static = $router->findRoute('get', '//users//a%20b///');
        self::assertSame($handler, $static['handler']);
        self::assertSame([], $static['params']);
        self::assertSame(0, $router->getStats()['cached_routes']);
        $result = $router->findRoute('GET', '/users/42');
        $result['params']['id'] = 'changed';
        self::assertSame(['id' => '42'], $router->findRoute('GET', '/users/42')['params']);

        $this->expectException(InvalidArgumentException::class);
        $router->findRoute('GET', '/users/%5c');
    }

    public function testCacheMatchesLruAcrossCompactionAndLimitChanges(): void
    {
        $router = new SwiftRouter();
        $router->get('/users/:id', static fn () => null);
        (new ReflectionProperty(TreeRouter::class, 'clockPolicy'))->setValue($router, false);
        $property = new ReflectionProperty(TreeRouter::class, 'routeCache');
        $expected = [];
        foreach ([0, 1, 2, 16, 2, 0, 1] as $limit) {
            $router->setCacheLimit($limit);
            for ($i = 0; $i < 300; ++$i) {
                $id = (string) (($i * 17) % 31);
                $key = '/GET::/users/' . $id;
                if ($i % 5 === 0 && $expected !== []) {
                    $keys = array_keys($expected);
                    $key = $keys[$i % count($keys)];
                    $id = substr($key, strlen('/GET::/users/'));
                }
                $hit = isset($expected[$key]);
                unset($expected[$key]);
                $expected[$key] = true;
                if (!$hit && count($expected) > $limit) {
                    unset($expected[array_key_first($expected)]);
                }
                self::assertSame(['id' => $id], $router->findRoute('GET', '/users/' . $id)['params']);
                self::assertSame(array_keys($expected), array_keys($property->getValue($router)));
            }
        }
        $router->clearCache();
        self::assertSame([], $property->getValue($router));
    }
}
