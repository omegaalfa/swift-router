<?php

declare(strict_types=1);

namespace Omegaalfa\TreeRouter\Router;

class WTreeRouter extends TreeRouter
{
    /**
     * Métodos de conveniência HTTP
     */
    public function get(string $path, callable $handler, array $middlewares = []): self
    {
        $this->addRoute('GET', $path, $handler, $middlewares);
        return $this;
    }

    public function post(string $path, callable $handler, array $middlewares = []): self
    {
        $this->addRoute('POST', $path, $handler, $middlewares);
        return $this;
    }

    public function put(string $path, callable $handler, array $middlewares = []): self
    {
        $this->addRoute('PUT', $path, $handler, $middlewares);
        return $this;
    }

    public function delete(string $path, callable $handler, array $middlewares = []): self
    {
        $this->addRoute('DELETE', $path, $handler, $middlewares);
        return $this;
    }

    public function patch(string $path, callable $handler, array $middlewares = []): self
    {
        $this->addRoute('PATCH', $path, $handler, $middlewares);
        return $this;
    }

}