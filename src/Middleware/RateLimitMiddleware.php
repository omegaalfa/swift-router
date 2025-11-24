<?php

namespace Omegaalfa\TreeRouter\Middleware;

use Omegaalfa\TreeRouter\Interfaces\MiddlewareInterface;
use Omegaalfa\TreeRouter\Router\RequestContext;
use Omegaalfa\TreeRouter\Router\Response;

class RateLimitMiddleware implements MiddlewareInterface
{
    private array $requests = [];
    private int $maxRequests = 3;
    private int $window = 10; // segundos

    public function process(RequestContext $context, callable $next): Response
    {
        $ip = $context->get('ip', '127.0.0.1');
        $now = time();

        // Limpa requisições antigas
        $this->requests[$ip] = array_filter(
            $this->requests[$ip] ?? [],
            fn($time) => $time > $now - $this->window
        );

        // Verifica limite
        if (count($this->requests[$ip]) >= $this->maxRequests) {
            echo "🚫 Rate limit exceeded\n\n";
            return (new Response())
                ->withStatus(429)
                ->withBody(['error' => 'Too many requests'])
                ->withHeader('Retry-After', (string)$this->window);
        }

        // Registra requisição
        $this->requests[$ip][] = $now;
        echo "✅ Request allowed ({$ip}): " . count($this->requests[$ip]) . "/{$this->maxRequests}\n";

        return $next($context);
    }
}