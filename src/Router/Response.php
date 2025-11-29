<?php

declare(strict_types=1);


namespace Omegaalfa\TreeRouter\Router;

class Response
{
    public function __construct(
        public mixed $body = null,
        public int   $statusCode = 200,
        public array $headers = []
    )
    {
    }

    /**
     * Define o corpo da resposta
     */
    public function withBody(mixed $body): self
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    /**
     * Define o status code
     */
    public function withStatus(int $code): self
    {
        $clone = clone $this;
        $clone->statusCode = $code;
        return $clone;
    }

    /**
     * Adiciona um header
     */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }
}