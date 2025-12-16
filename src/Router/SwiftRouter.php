<?php

declare(strict_types=1);

namespace Omegaalfa\SwiftRouter\Router;

use Omegaalfa\SwiftRouter\Interfaces\MiddlewareInterface;

class SwiftRouter extends TreeRouter
{
    /**
     * @param string $path
     * @param callable|array<string, string> $handler
     * @param array<int, MiddlewareInterface|\Psr\Http\Server\MiddlewareInterface> $middlewares
     * @return $this
     */
    public function get(string $path, callable|array $handler, array $middlewares = []): self
    {
        $this->addRoute('GET', $path, $handler, $middlewares);
        return $this;
    }

    /**
     * @param string $path
     * @param callable|array<string, string> $handler
     * @param array<int, MiddlewareInterface|\Psr\Http\Server\MiddlewareInterface> $middlewares
     * @return $this
     */
    public function post(string $path, callable|array $handler, array $middlewares = []): self
    {
        $this->addRoute('POST', $path, $handler, $middlewares);
        return $this;
    }

    /**
     * @param string $path
     * @param callable|array<string, string> $handler
     * @param array<int, MiddlewareInterface|\Psr\Http\Server\MiddlewareInterface> $middlewares
     * @return $this
     */
    public function put(string $path, callable|array $handler, array $middlewares = []): self
    {
        $this->addRoute('PUT', $path, $handler, $middlewares);
        return $this;
    }

    /**
     * @param string $path
     * @param callable|array<string, string> $handler
     * @param array<int, MiddlewareInterface|\Psr\Http\Server\MiddlewareInterface> $middlewares
     * @return $this
     */
    public function delete(string $path, callable|array $handler, array $middlewares = []): self
    {
        $this->addRoute('DELETE', $path, $handler, $middlewares);
        return $this;
    }

    /**
     * @param string $path
     * @param callable|array<string, string> $handler
     * @param array<int, MiddlewareInterface|\Psr\Http\Server\MiddlewareInterface> $middlewares
     * @return $this
     */
    public function patch(string $path, callable|array $handler, array $middlewares = []): self
    {
        $this->addRoute('PATCH', $path, $handler, $middlewares);
        return $this;
    }
}
