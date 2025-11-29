<?php

declare(strict_types=1);


namespace Omegaalfa\TreeRouter\Interfaces;

use Omegaalfa\TreeRouter\Router\RequestContext;
use Omegaalfa\TreeRouter\Router\Response;

interface MiddlewareInterface
{
    /**
     * Processa a requisição
     *
     * @param RequestContext $context Contexto da requisição
     * @param callable $next Próximo middleware/handler na cadeia
     * @return Response
     */
    public function process(RequestContext $context, callable $next): Response;
}