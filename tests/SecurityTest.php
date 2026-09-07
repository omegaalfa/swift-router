<?php

declare(strict_types=1);

namespace Omegaalfa\SwiftRouter\Tests;

use Omegaalfa\SwiftRouter\Router\HttpMethod;
use Omegaalfa\SwiftRouter\Router\SwiftRouter;
use Omegaalfa\SwiftRouter\Router\RequestContext;
use Omegaalfa\SwiftRouter\Router\Response;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    /**
     * Testa validação de método HTTP
     */
    public function testHttpMethodValidation(): void
    {
        $router = new SwiftRouter();

        // Métodos válidos devem funcionar
        $router->get('/test', fn() => 'ok');

        $this->expectNotToPerformAssertions();
        $router->dispatch('GET', '/test');
    }

    /**
     * Testa rejeição de método HTTP inválido
     */
    public function testInvalidHttpMethodThrowsException(): void
    {
        $router = new SwiftRouter();
        $router->get('/test', fn() => 'ok');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP method');

        $router->dispatch('INVALID', '/test');
    }

    /**
     * Testa normalização case-insensitive de método
     */
    public function testHttpMethodCaseInsensitive(): void
    {
        $router = new SwiftRouter();
        $router->get('/test', fn() => 'ok');

        $response1 = $router->dispatch('GET', '/test');
        $response2 = $router->dispatch('get', '/test');
        $response3 = $router->dispatch('Get', '/test');

        $this->assertEquals('ok', $response1->body);
        $this->assertEquals('ok', $response2->body);
        $this->assertEquals('ok', $response3->body);
    }

    /**
     * Testa suporte automático para HEAD
     */
    public function testHeadMethodSupport(): void
    {
        $router = new SwiftRouter();
        $router->get('/test', fn() => ['data' => 'test']);

        $response = $router->dispatch('HEAD', '/test');

        $this->assertEquals(200, $response->statusCode);
        $this->assertNull($response->body); // HEAD não deve ter body
    }

    /**
     * Testa suporte automático para OPTIONS
     */
    public function testOptionsMethodSupport(): void
    {
        $router = new SwiftRouter();
        $router->get('/test', fn() => 'ok');
        $router->post('/test', fn() => 'ok');

        $response = $router->dispatch('OPTIONS', '/test');

        $this->assertEquals(204, $response->statusCode);
        $this->assertArrayHasKey('Allow', $response->headers);
        $this->assertStringContainsString('GET', $response->headers['Allow']);
        $this->assertStringContainsString('POST', $response->headers['Allow']);
        $this->assertStringContainsString('HEAD', $response->headers['Allow']);
        $this->assertStringContainsString('OPTIONS', $response->headers['Allow']);
    }

    /**
     * Testa detecção de path traversal
     */
    public function testPathTraversalDetection(): void
    {
        $router = new SwiftRouter();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path traversal detected');

        $router->get('/test/../admin', fn() => 'ok');
    }

    /**
     * Testa normalização de slashes duplicados
     */
    public function testDoubleSlashNormalization(): void
    {
        $router = new SwiftRouter();
        $router->get('/api/users', fn() => 'users');

        // Todas estas devem encontrar a mesma rota
        $response1 = $router->dispatch('GET', '/api/users');
        $response2 = $router->dispatch('GET', '//api//users');
        $response3 = $router->dispatch('GET', '/api///users/');

        $this->assertEquals('users', $response1->body);
        $this->assertEquals('users', $response2->body);
        $this->assertEquals('users', $response3->body);
    }

    public function testNormalizePathContractCases(): void
    {
        $router = new SwiftRouter();
        $method = new \ReflectionMethod($router, 'normalizePath');
        $method->setAccessible(true);

        foreach ([
            '/' => '/', '/users' => '/users', '/users/' => '/users',
            '//users' => '/users', '/users//123' => '/users/123',
            '/users/%2F' => '/users/%2F', '/users/%2E' => '/users/%2E',
            '/users/%252e%252e' => '/users/%252e%252e', '' => '',
        ] as $input => $expected) {
            $this->assertSame($expected, $method->invoke($router, $input), $input);
        }

        foreach (['/../admin', '/users/%2e%2e/admin', '/users/%2E%2E/admin', '/users/..%2Fadmin', '/users/\\admin'] as $input) {
            try {
                $method->invoke($router, $input);
                $this->fail('Expected traversal rejection for '.$input);
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('Path traversal detected', $exception->getMessage());
            }
        }
    }

    public function testEncodedParameterIsMatchedAndEncodedTraversalIsRejected(): void
    {
        $router = new SwiftRouter();
        $router->get('/users/:id', fn (RequestContext $context) => $context->params['id']);

        $this->assertSame('a%20b', $router->dispatch('GET', '/users/a%20b')->body);

        $this->expectException(\InvalidArgumentException::class);
        $router->dispatch('GET', '/users/%2e%2e/admin');
    }

    /**
     * Testa limite de tamanho de parâmetro
     */
    public function testParameterLengthLimit(): void
    {
        $router = new SwiftRouter();
        $router->setMaxParamLength(10);
        $router->get('/user/:id', fn($ctx) => $ctx->params['id']);

        // Parâmetro pequeno deve funcionar
        $response1 = $router->dispatch('GET', '/user/123');
        $this->assertEquals('123', $response1->body);

        // Parâmetro muito grande deve falhar
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds maximum length');

        $router->dispatch('GET', '/user/' . str_repeat('A', 11));
    }

    /**
     * Testa validação de controller namespace
     */
    public function testControllerNamespaceValidation(): void
    {
        $router = new SwiftRouter(['App\\Controllers\\']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be in allowed namespace');

        // Namespace não permitido
        $router->get('/test', [\stdClass::class, 'method']);
    }

    /**
     * Testa bloqueio de callables perigosos
     */
    public function testDangerousCallablesBlocked(): void
    {
        $router = new SwiftRouter();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dangerous callable');

        $router->get('/exec', 'system');
    }

    /**
     * Testa tratamento de exceção em middleware
     */
    public function testMiddlewareExceptionHandling(): void
    {
        $router = new SwiftRouter();

        // Middleware que lança exceção
        $router->use(function($ctx, $next) {
            throw new \Exception('Middleware error');
        });

        $router->get('/test', fn() => 'never reached');

        $response = $router->dispatch('GET', '/test');

        $this->assertEquals(500, $response->statusCode);
        $this->assertIsArray($response->body);
        $this->assertArrayHasKey('error', $response->body);
    }

    /**
     * Testa enum HttpMethod
     */
    public function testHttpMethodEnum(): void
    {
        // Validação de método válido
        $method = HttpMethod::fromString('GET');
        $this->assertEquals('GET', $method->value);

        // Case insensitive
        $method = HttpMethod::fromString('post');
        $this->assertEquals('POST', $method->value);

        // Método inválido
        $this->expectException(\InvalidArgumentException::class);
        HttpMethod::fromString('INVALID');
    }

    /**
     * Testa método isValid do enum
     */
    public function testHttpMethodIsValid(): void
    {
        $this->assertTrue(HttpMethod::isValid('GET'));
        $this->assertTrue(HttpMethod::isValid('post'));
        $this->assertTrue(HttpMethod::isValid('DeLeTe'));
        $this->assertFalse(HttpMethod::isValid('INVALID'));
        $this->assertFalse(HttpMethod::isValid('TRACE'));
    }

    /**
     * Testa listagem de todos os métodos
     */
    public function testHttpMethodValues(): void
    {
        $methods = HttpMethod::values();

        $this->assertIsArray($methods);
        $this->assertContains('GET', $methods);
        $this->assertContains('POST', $methods);
        $this->assertContains('PUT', $methods);
        $this->assertContains('DELETE', $methods);
        $this->assertContains('PATCH', $methods);
        $this->assertContains('OPTIONS', $methods);
        $this->assertContains('HEAD', $methods);
        $this->assertCount(7, $methods);
    }
}
