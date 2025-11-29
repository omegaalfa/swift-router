<?php

declare(strict_types=1);


namespace Omegaalfa\TreeRouter\Router;

class RequestContext
{
    public function __construct(
        public string $method,
        public string $path,
        public array  $params = [],
        public array  $data = []
    )
    {
    }

    /**
     * Define um valor no contexto
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * Obtém um valor do contexto
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Verifica se chave existe
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }
}