<?php

declare(strict_types=1);

namespace Omegaalfa\SwiftRouter\Tests;

use Omegaalfa\SwiftRouter\Router\{RequestContext, Response, SwiftRouter};
use PHPUnit\Framework\TestCase;

final class DispatchPipelineTest extends TestCase
{
    public function testGlobalAndRouteMiddlewareOrderAndDoubleNext(): void
    {
        $router = new SwiftRouter();
        $events = [];
        $middleware = static function (string $name, array &$events): callable {
            return static function (RequestContext $context, callable $next) use ($name, &$events): Response {
                $events[] = $name . ':before';
                $response = $next($context);
                $events[] = $name . ':after';
                return $response;
            };
        };
        $router->use($middleware('global', $events));
        $router->get('/pipeline', static function () use (&$events): string {
            $events[] = 'handler';
            return 'ok';
        }, [$middleware('route', $events)]);

        self::assertSame('ok', $router->dispatch('GET', '/pipeline')->body);
        self::assertSame(['global:before', 'route:before', 'handler', 'route:after', 'global:after'], $events);

        $events = [];
        $router = new SwiftRouter();
        $router->get('/twice', static function () use (&$events): string { $events[] = 'handler'; return 'ok'; }, [
            static function (RequestContext $context, callable $next): Response {
                $next($context);
                return $next($context);
            },
        ]);
        self::assertSame('ok', $router->dispatch('GET', '/twice')->body);
        self::assertSame(['handler', 'handler'], $events);
    }

    public function testShortCircuitExceptionScalarResponseHeadOptionsAnd404(): void
    {
        $router = new SwiftRouter();
        $router->get('/resource', static fn (): array => ['ok' => true]);
        $router->get('/stop', static fn (): string => 'handler', [
            static fn (RequestContext $context, callable $next): Response => (new Response())->withBody('stopped'),
        ]);
        $router->get('/error', static fn (): string => 'handler', [
            static function (): Response { throw new \RuntimeException('boom'); },
        ]);

        self::assertSame(['ok' => true], $router->dispatch('GET', '/resource')->body);
        self::assertNull($router->dispatch('HEAD', '/resource')->body);
        self::assertSame('stopped', $router->dispatch('GET', '/stop')->body);
        self::assertSame(500, $router->dispatch('GET', '/error')->statusCode);
        self::assertSame(204, $router->dispatch('OPTIONS', '/resource')->statusCode);
        $this->expectException(\RuntimeException::class);
        $router->dispatch('GET', '/missing');
    }

    public function testRoutePipelineIsInvalidatedByRouteRegistrationAndGlobalUse(): void
    {
        $router = new SwiftRouter();
        $calls = 0;
        $middleware = static function (RequestContext $context, callable $next) use (&$calls): Response {
            ++$calls;
            return $next($context);
        };
        $router->get('/cached', static fn (): string => 'old', [$middleware]);
        self::assertSame('old', $router->dispatch('GET', '/cached')->body);
        self::assertSame('old', $router->dispatch('GET', '/cached')->body);
        self::assertSame(2, $calls);

        $router->get('/cached', static fn (): string => 'new', [$middleware]);
        self::assertSame('new', $router->dispatch('GET', '/cached')->body);

        $late = static function (RequestContext $context, callable $next): Response {
            $context->set('late', true);
            return $next($context);
        };
        $router->use($late);
        $router->get('/late', static fn (RequestContext $context): string => $context->get('late', false) ? 'seen' : 'missing', [$middleware]);
        self::assertSame('seen', $router->dispatch('GET', '/cached')->body === 'new' ? 'seen' : 'missing');
        self::assertSame('seen', $router->dispatch('GET', '/late')->body);
    }
}
