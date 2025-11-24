<?php

namespace Omegaalfa\TreeRouter\Middleware;

use Omegaalfa\TreeRouter\Interfaces\MiddlewareInterface;
use Omegaalfa\TreeRouter\Router\RequestContext;
use Omegaalfa\TreeRouter\Router\Response;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(RequestContext $context, callable $next): Response
    {
        $token = $context->get('token');

        if (!$token || $token !== 'secret-token') {
            echo "❌ Unauthorized\n\n";
            return (new Response())
                ->withStatus(401)
                ->withBody(['error' => 'Unauthorized']);
        }

        echo "✅ Authenticated\n";
        $context->set('user_id', 123);

        return $next($context);
    }
}