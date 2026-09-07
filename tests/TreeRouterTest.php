<?php

declare(strict_types=1);

namespace Omegaalfa\SwiftRouter\Tests;

use Omegaalfa\SwiftRouter\Interfaces\MiddlewareInterface;
use Omegaalfa\SwiftRouter\Router\RequestContext;
use Omegaalfa\SwiftRouter\Router\Response;
use Omegaalfa\SwiftRouter\Router\SwiftRouter;
use Omegaalfa\SwiftRouter\Router\TreeRouter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class TreeRouterTest extends TestCase
{
    public function testDispatchReturnsHandlerResponseObject(): void
    {
        $router = new SwiftRouter();
        $router->get('/hello', function (RequestContext $context, Response $response) {
            return $response->withBody('hello world');
        });

        $response = $router->dispatch('GET', '/hello');

        $this->assertSame('hello world', $response->body);
        $this->assertSame(200, $response->statusCode);
    }

    public function testDispatchHandlesParamsAndInitialData(): void
    {
        $router = new SwiftRouter();
        $router->get('/users/:id', function (RequestContext $context, Response $response) {
            return [
                'id' => $context->params['id'],
                'token' => $context->get('token'),
            ];
        });

        $response = $router->dispatch('GET', '/users/42', ['token' => 'abc']);

        $this->assertSame(['id' => '42', 'token' => 'abc'], $response->body);
    }

    public function testGroupAppliesPrefixToRoutes(): void
    {
        $router = new SwiftRouter();
        $router->group('/api', function (SwiftRouter $router) {
            $router->get('/status', fn (RequestContext $context, Response $response) => 'ok');
        });

        $response = $router->dispatch('GET', '/api/status');

        $this->assertSame('ok', $response->body);
    }

    public function testDispatchThrowsForUnknownRoute(): void
    {
        $router = new SwiftRouter();

        $this->expectException(RuntimeException::class);
        $router->dispatch('GET', '/missing');
    }

    public function testMiddlewareExecutionOrder(): void
    {
        $router = new SwiftRouter();
        $recorder = new CallRecorder();

        $router->use(new RecordingMiddleware($recorder, 'global'));

        $router->group('/api', function (SwiftRouter $router) use ($recorder) {
            $router->get('/ping', function (RequestContext $context, Response $response) use ($recorder) {
                $recorder->record('handler');
                return 'pong';
            }, [new RecordingMiddleware($recorder, 'route')]);
        }, [new RecordingMiddleware($recorder, 'group')]);

        $response = $router->dispatch('GET', '/api/ping');

        $this->assertSame('pong', $response->body);
        $this->assertSame([
            'before:global',
            'before:group',
            'before:route',
            'handler',
            'after:route',
            'after:group',
            'after:global',
        ], $recorder->getItems());
    }

    public function testCallableGlobalMiddlewareIsWrappedAsInterface(): void
    {
        $router = new SwiftRouter();
        $callable = static fn (RequestContext $context, callable $next) => $next($context);

        $router->use($callable);

        $treeReflection = new ReflectionClass(TreeRouter::class);
        $property = $treeReflection->getProperty('globalMiddlewares');
        $property->setAccessible(true);

        $middlewares = $property->getValue($router);

        $this->assertNotSame($callable, $middlewares[0]);
        $this->assertInstanceOf(MiddlewareInterface::class, $middlewares[0]);
    }

    public function testNestedGroupsPreserveParentMiddlewares(): void
    {
        $router = new SwiftRouter();
        $recorder = new CallRecorder();

        $router->group('/parent', function (SwiftRouter $router) use ($recorder) {
            $router->group('/child', function (SwiftRouter $router) use ($recorder) {
                $router->get('/route', function () use ($recorder) {
                    $recorder->record('handler');
                    return 'ok';
                }, [new RecordingMiddleware($recorder, 'route')]);
            }, [new RecordingMiddleware($recorder, 'child')]);
        }, [new RecordingMiddleware($recorder, 'parent')]);

        $response = $router->dispatch('GET', '/parent/child/route');

        $this->assertSame('ok', $response->body);
        $this->assertSame([
            'before:parent',
            'before:child',
            'before:route',
            'handler',
            'after:route',
            'after:child',
            'after:parent',
        ], $recorder->getItems());
    }

    public function testCallableGlobalMiddlewareIsWrapped(): void
    {
        $router = new SwiftRouter();
        $router->use(function (RequestContext $context, callable $next) {
            $context->set('wrapped', 'true');
            return $next($context);
        });

        $router->get('/callable', function (RequestContext $context, Response $response) {
            return $context->get('wrapped');
        });

        $response = $router->dispatch('GET', '/callable');

        $this->assertSame('true', $response->body);
    }

    public function testStatsAndCacheRespectLimit(): void
    {
        $router = new SwiftRouter();

        $router->get('/static', fn (RequestContext $context, Response $response) => 'static data');
        $router->get('/users/:id', fn (RequestContext $context, Response $response) => $context->params['id']);

        $this->assertSame(1, $router->getStats()['static_routes']);

        $router->setCacheLimit(1);

        $router->dispatch('GET', '/users/1');
        $router->dispatch('GET', '/users/2');

        $this->assertLessThanOrEqual(1, $router->getStats()['cached_routes']);

        $router->clearCache();

        $this->assertSame(0, $router->getStats()['cached_routes']);
    }

    public function testStaticBranchFallsBackToDynamicBranchWhenItFailsLater(): void
    {
        $router = new SwiftRouter();
        $router->get('/files/new/details', fn () => 'static');
        $router->get('/files/:id/edit', fn (RequestContext $context) => $context->params['id']);

        $response = $router->dispatch('GET', '/files/new/edit');

        $this->assertSame('new', $response->body);
    }

    public function testAddingRouteInvalidatesPreviouslyCachedDynamicMatch(): void
    {
        $router = new SwiftRouter();
        $router->get('/users/:id', fn () => 'old');
        $this->assertSame('old', $router->dispatch('GET', '/users/42')->body);

        $router->get('/users/:id', fn () => 'new');

        $this->assertSame('new', $router->dispatch('GET', '/users/42')->body);
    }

    public function testAddingGlobalMiddlewareAfterFirstDispatchAffectsLaterDispatch(): void
    {
        $router = new SwiftRouter();
        $router->get('/pipeline', fn (RequestContext $context) => $context->get('late', 'missing'));

        $this->assertSame('missing', $router->dispatch('GET', '/pipeline')->body);
        $router->use(static function (RequestContext $context, callable $next): Response {
            $context->set('late', 'present');
            return $next($context);
        });

        $this->assertSame('present', $router->dispatch('GET', '/pipeline')->body);
    }
}

final class CallRecorder
{
    /**
     * @var array<int, string>
     */
    private array $items = [];

    public function record(string $label): void
    {
        $this->items[] = $label;
    }

    /**
     * @return array<int, string>
     */
    public function getItems(): array
    {
        return $this->items;
    }
}

final class RecordingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CallRecorder $recorder,
        private string $label
    ) {
    }

    public function process(RequestContext $context, callable $next): Response
    {
        $this->recorder->record("before:{$this->label}");
        $response = $next($context);
        $this->recorder->record("after:{$this->label}");

        return $response;
    }
}
