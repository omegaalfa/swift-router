<?php

namespace Omegaalfa\TreeRouter\Middleware;

use Omegaalfa\TreeRouter\Interfaces\MiddlewareInterface;
use Omegaalfa\TreeRouter\Router\RequestContext;
use Omegaalfa\TreeRouter\Router\Response;

class JsonMiddleware implements MiddlewareInterface
{
    public function process(RequestContext $context, callable $next): Response
    {
        $response = $next($context);

        // Se body for array, converte para JSON
        if (is_array($response->body)) {
            return $response
                ->withBody(json_encode($response->body))
                ->withHeader('Content-Type', 'application/json');
        }

        return $response;
    }
}
