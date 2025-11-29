<?php

declare(strict_types=1);

namespace Omegaalfa\TreeRouter\Router;

use Psr\Http\Server\MiddlewareInterface;

class TreeNode
{
    /** @var array<string, TreeNode> */
    public array $children = [];

    /** @var array<int, MiddlewareInterface> */
    public array $middlewares = [];

    /**
     * @var TreeNode|null
     */
    public ?TreeNode $paramChild = null;

    /**
     * @var string|null
     */
    public ?string $paramName = null;

    /** @var bool */
    public bool $isEndOfRoute = false;

    /**@var mixed|null */
    public mixed $handler = null;


    public ?string $routeName = null;

    public array $constraints = [];
}