<?php

namespace Omegaalfa\TreeRouter\Middleware;

use Omegaalfa\TreeRouter\Interfaces\MiddlewareInterface;
use Omegaalfa\TreeRouter\Router\RequestContext;
use Omegaalfa\TreeRouter\Router\Response;

class TimerMiddleware implements MiddlewareInterface
{
    public function process(RequestContext $context, callable $next): Response
    {
        $start = microtime(true);
        $response = $next($context);
        $duration = microtime(true) - $start;

        return $response->withHeader('X-Response-Time', sprintf('%.3fms', $duration * 1000));
    }
}