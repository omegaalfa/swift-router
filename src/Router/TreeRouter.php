<?php

declare(strict_types=1);


namespace Omegaalfa\SwiftRouter\Router;


use Omegaalfa\SwiftRouter\Interfaces\MiddlewareInterface;
use RuntimeException;

class TreeRouter
{
    /**
     * @var TreeNode
     */
    protected TreeNode $root;

    /** @var array<string, array{handler:callable,middlewares:array<int,MiddlewareInterface>,params:array<string,string>}> */
    private array $staticMap = [];

    /** @var array<string, array{handler:callable,middlewares:array<int,MiddlewareInterface>,params:array<string,string>}> */
    private array $routeCache = [];

    /** @var string Prefixo atual do grupo */
    private string $groupPrefix = '';

    /** @var array<MiddlewareInterface> Middlewares do grupo atual */
    private array $groupMiddlewares = [];

    /**
     * @var int
     */
    private int $cacheLimit = 2048;

    /** @var array<MiddlewareInterface> Middlewares globais */
    private array $globalMiddlewares = [];

    public function __construct()
    {
        $this->root = new TreeNode();
    }


    /**
     * @param callable|MiddlewareInterface $middleware
     * @return $this
     */
    public function use(callable|MiddlewareInterface $middleware): self
    {
        if ($middleware instanceof MiddlewareInterface) {
            $this->globalMiddlewares[] = $middleware;
            return $this;
        }

        $this->globalMiddlewares[] = new class ($middleware) implements MiddlewareInterface {
            /**
             * @var callable(RequestContext, callable): Response
             */
            private $callable;

            public function __construct(callable $callable)
            {
                $this->callable = $callable;
            }

            public function process(RequestContext $context, callable $next): Response
            {
                return ($this->callable)($context, $next);
            }
        };

        return $this;
    }

    /**
     * Cria um grupo de rotas com prefixo e middlewares compartilhados
     *
     * @param string $prefix Prefixo do grupo (/api, /admin, etc)
     * @param callable $callback Callback que recebe o router
     * @param array<int, MiddlewareInterface> $middlewares Middlewares aplicados a todas as rotas do grupo
     *
     * @example
     * $router->group('/api/v1', function($router) {
     *     $router->get('/users', $handler);      // /api/v1/users
     *     $router->post('/users', $handler);     // /api/v1/users
     * }, [new AuthMiddleware()]);
     */
    public function group(string $prefix, callable $callback, array $middlewares = []): void
    {
        // Salva estado anterior
        $previousPrefix = $this->groupPrefix;
        $previousMiddlewares = $this->groupMiddlewares;

        // Aplica novo estado
        $this->groupPrefix = $this->buildGroupPath($prefix);
        $this->groupMiddlewares = array_merge($this->groupMiddlewares, $middlewares);

        // Executa callback com o router
        $callback($this);

        // Restaura estado anterior (permite grupos aninhados)
        $this->groupPrefix = $previousPrefix;
        $this->groupMiddlewares = $previousMiddlewares;
    }

    /**
     * Constrói o caminho completo considerando o prefixo do grupo
     *
     * @param string $path Caminho da rota
     * @return string Caminho completo com prefixo
     */
    private function buildGroupPath(string $path): string
    {
        $prefix = trim($this->groupPrefix, '/');
        $path = trim($path, '/');

        if ($prefix === '') {
            return '/' . $path;
        }

        if ($path === '') {
            return '/' . $prefix;
        }

        return '/' . $prefix . '/' . $path;
    }

    /**
     * Adiciona uma rota
     *
     * @param string $method Método HTTP (GET, POST, PUT, DELETE, etc)
     * @param string $path Padrão da rota (/users/:id)
     * @param callable|array<string, string> $handler Handler da rota
     * @param array<int, MiddlewareInterface> $middlewares Middlewares opcionais
     */
    public function addRoute(string $method, string $path, callable|array $handler, array $middlewares = []): void
    {
        // Normaliza o método
        $method = strtoupper($method);

        // Aplica prefixo do grupo se existir
        $fullPath = $this->groupPrefix ? $this->buildGroupPath($path) : $path;

        // Combina middlewares do grupo com os da rota
        $allMiddlewares = array_merge($this->groupMiddlewares, $middlewares);

        // Adiciona método ao caminho para evitar conflitos
        $routePath = $method . '::' . $fullPath;

        // normalize without excessive trimming
        $p = ltrim($routePath, '/');

        if (is_array($handler)) {
            $handler = $this->normalizeArrayHandler($handler);
        }

        // register static fast-path (only quando o caminho não tem parâmetros)
        if (!str_contains($fullPath, ':')) {
            $this->staticMap['/' . $p] = [
                'handler' => $handler,
                'middlewares' => $allMiddlewares,
                'params' => [],
            ];
        }

        $currentNode = $this->root;
        $parts = $p === '' ? [] : explode('/', $p);

        foreach ($parts as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment[0] === ':') {
                $name = substr($segment, 1);
                if ($currentNode->paramChild === null) {
                    $currentNode->paramChild = new TreeNode();
                    $currentNode->paramName = $name;
                }
                $currentNode = $currentNode->paramChild;
                continue;
            }

            if (!isset($currentNode->children[$segment])) {
                $currentNode->children[$segment] = new TreeNode();
            }

            $currentNode = $currentNode->children[$segment];
        }

        $currentNode->isEndOfRoute = true;
        $currentNode->handler = $handler;
        $currentNode->middlewares = $allMiddlewares;
    }

    /**
     * @param array<string, string> $handler
     * @return callable
     */
    protected function normalizeArrayHandler(array $handler): callable
    {
        if (count($handler) !== 2) {
            throw new \InvalidArgumentException(
                'Array handler must be [ControllerClass, method]'
            );
        }

        [$controller, $method] = $handler;

        if (!is_string($controller) || !is_string($method)) {
            throw new \InvalidArgumentException(
                'Invalid array handler format'
            );
        }

        return function (...$args) use ($controller, $method) {
            $instance = new $controller();

            if (!method_exists($instance, $method)) {
                throw new \RuntimeException(
                    "Method {$method} does not exist on {$controller}"
                );
            }

            return $instance->$method(...$args);
        };
    }

    /**
     * Busca e executa uma rota com middlewares
     *
     * @param string $method Método HTTP
     * @param string $path Caminho da requisição
     * @param array<string, mixed> $initialData Dados iniciais para o contexto
     * @return Response Resposta processada
     * @throws RuntimeException Se rota não for encontrada
     */
    public function dispatch(string $method, string $path, array $initialData = []): Response
    {
        $route = $this->findRoute($method, $path);

        if ($route === null) {
            throw new RuntimeException("Route not found: {$method} {$path}");
        }

        // Cria contexto da requisição
        $context = new RequestContext($method, $path, $route['params'], $initialData);

        // Combina middlewares: globais + específicos da rota
        $allMiddlewares = array_merge($this->globalMiddlewares, $route['middlewares']);

        // Cria a cadeia de execução (middlewares + handler)
        $handler = $route['handler'];


        // Constrói a cadeia de middlewares (de trás para frente)
        $chain = static function (RequestContext $ctx) use ($handler): Response {
            $result = $handler($ctx, new Response());

            // Se handler retornar Response, usa ela
            if ($result instanceof Response) {
                return $result;
            }

            // Caso contrário, cria Response com o resultado
            return new Response($result);
        };

        // Encadeia middlewares em ordem reversa
        foreach (array_reverse($allMiddlewares) as $middleware) {
            $chain = $this->wrapMiddleware($middleware, $chain);
        }

        // Executa a cadeia
        return $chain($context);
    }

    /**
     * Busca uma rota sem executar
     *
     * @param string $method Método HTTP
     * @param string $path Caminho da requisição
     * @return array{handler:callable,middlewares:array<int,MiddlewareInterface>,params:array<string,string>}|null
     */
    public function findRoute(string $method, string $path): ?array
    {
        // Normaliza o método
        $method = strtoupper($method);

        // Adiciona método ao caminho
        $fullPath = $method . '::' . $path;

        // normalize incoming path
        $normalized = '/' . preg_replace('#/+#', '/', ltrim($fullPath, '/'));

        // static fast map
        if (isset($this->staticMap[$normalized])) {
            return $this->staticMap[$normalized];
        }

        // cache
        if (isset($this->routeCache[$normalized])) {
            $entry = $this->routeCache[$normalized];
            unset($this->routeCache[$normalized]);
            $this->routeCache[$normalized] = $entry;
            return $entry;
        }

        $currentNode = $this->root;
        $p = ltrim($normalized, '/');
        $parts = $p === '' ? [] : explode('/', $p);
        $params = [];

        foreach ($parts as $segment) {
            if ($segment === '') {
                continue;
            }

            // 1 — exact child
            if (isset($currentNode->children[$segment])) {
                $currentNode = $currentNode->children[$segment];
                continue;
            }

            // 2 — param child
            if ($currentNode->paramChild !== null) {
                $params[$currentNode->paramName ?? 'param'] = $segment;
                $currentNode = $currentNode->paramChild;
                continue;
            }

            // 3 — no match
            return null;
        }

        if ($currentNode->isEndOfRoute && $currentNode->handler !== null) {
            $result = [
                'handler' => $currentNode->handler,
                'middlewares' => $currentNode->middlewares,
                'params' => $params,
            ];

            // store in cache
            $this->routeCache[$normalized] = $result;
            if (count($this->routeCache) > $this->cacheLimit) {
                reset($this->routeCache);
                /** @var string|null $k */
                $k = key($this->routeCache);
                if ($k !== null) {
                    unset($this->routeCache[$k]);
                }
            }

            return $result;
        }

        return null;
    }

    /**
     * Encapsula um middleware na cadeia
     *
     * @param callable|MiddlewareInterface $middleware
     * @param callable $next
     * @return callable
     */
    private function wrapMiddleware(callable|MiddlewareInterface $middleware, callable $next): callable
    {
        return static function (RequestContext $context) use ($middleware, $next): Response {
            if ($middleware instanceof MiddlewareInterface) {
                return $middleware->process($context, $next);
            }

            $result = $middleware($context, $next);
            if ($result instanceof Response) {
                return $result;
            }

            return new Response($result);
        };
    }

    /**
     * Retorna estatísticas do router
     *
     * @return array{static_routes:int,cached_routes:int,cache_limit:int,global_middlewares:int}
     */
    public function getStats(): array
    {
        return [
            'static_routes' => count($this->staticMap),
            'cached_routes' => count($this->routeCache),
            'cache_limit' => $this->cacheLimit,
            'global_middlewares' => count($this->globalMiddlewares),
        ];
    }

    /**
     * Limpa o cache de rotas
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->routeCache = [];
    }

    /**
     * Define o limite do cache
     *
     * @param int $limit
     * @return void
     */
    public function setCacheLimit(int $limit): void
    {
        $this->cacheLimit = $limit;
    }
}
