<?php

declare(strict_types=1);


namespace Omegaalfa\SwiftRouter\Router;


use InvalidArgumentException;
use Omegaalfa\SwiftRouter\Interfaces\MiddlewareInterface;
use RuntimeException;
use Throwable;

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

    private int $cacheEvictions = 0;

    private int $cacheCompactionInterval = 1024;

    /** @var bool Temporary benchmark variant; disabled by default. */
    private bool $clockPolicy = false;
    /** @var array<string, bool> */
    private array $clockReferences = [];
    /** @var array<int, string|null> */
    private array $clockRing = [];
    private int $clockFill = 0;
    private int $clockHand = 0;

    /** @var array<MiddlewareInterface> Middlewares globais */
    private array $globalMiddlewares = [];
    /** @var array<string, callable> Temporary route-only compiled pipelines. */
    private array $compiledRoutePipelines = [];
    /** @var array<string,int> */
    private array $pipelineAdmission = [];
    /** @var array<string,bool> */
    private array $pipelineReferences = [];
    /** @var array<int,string|null> */
    private array $pipelineRing = [];
    private int $pipelineFill = 0;
    private int $pipelineHand = 0;
    private int $pipelineCapacity = 256;
    /** @var array<int,string|null> */
    private array $admissionRing = [];
    /** @var array<string,bool> */
    private array $admissionReferences = [];
    private int $admissionHand = 0;
    private int $admissionFill = 0;
    private int $admissionCapacity = 256;


    /** @var int Tamanho máximo de parâmetro de rota */
    private int $maxParamLength = 255;

    /** @var array<string> Namespaces permitidos para controllers */
    private array $allowedNamespaces = [];

    /** @param array<int, string> $allowedNamespaces */
    public function __construct(array $allowedNamespaces = [])
    {
        $this->root = new TreeNode();
        $this->allowedNamespaces = $allowedNamespaces;
        $this->clockPolicy = true;
        $this->clockRing = array_fill(0, $this->cacheLimit, null);
        $this->resetAdmissionState($this->admissionCapacity);
    }

    private function resetAdmissionState(int $capacity): void
    {
        $this->admissionCapacity = max(1, $capacity);
        $this->pipelineAdmission = [];
        $this->admissionRing = array_fill(0, $this->admissionCapacity, null);
        $this->admissionReferences = [];
        $this->admissionHand = 0;
        $this->admissionFill = 0;
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
        // Registration can change both precedence and the selected handler.
        // Previously cached dynamic matches must not outlive the route table.
        $this->routeCache = [];
        $this->clockReferences = [];
        $this->clockRing = array_fill(0, $this->cacheLimit, null);
        $this->clockFill = 0;
        $this->clockHand = 0;
        $this->compiledRoutePipelines = [];
        $this->resetAdmissionState($this->admissionCapacity);
        $this->pipelineReferences = [];
        $this->pipelineRing = array_fill(0, $this->pipelineCapacity, null);
        $this->pipelineFill = 0;
        $this->pipelineHand = 0;

        // Valida e normaliza o método
        $method = HttpMethod::fromString($method)->value;

        // Valida e normaliza o handler
        $handler = $this->validateAndNormalizeHandler($handler);

        // Aplica prefixo do grupo se existir e normaliza o path
        $fullPath = $this->normalizePath(
            $this->groupPrefix ? $this->buildGroupPath($path) : $path
        );

        // Combina middlewares do grupo com os da rota
        $allMiddlewares = array_merge($this->groupMiddlewares, $middlewares);

        // Adiciona método ao caminho para evitar conflitos
        $routePath = $method . '::' . $fullPath;

        // normalize without excessive trimming
        $p = ltrim($routePath, '/');

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

            $currentNode->children[$segment] ??= new TreeNode(); 
            $currentNode = $currentNode->children[$segment];
        }

        $currentNode->isEndOfRoute = true;
        $currentNode->handler = $handler;
        $currentNode->middlewares = $allMiddlewares;
    }

    /**
     * Valida e normaliza o handler
     *
     * @param callable|array<string, string> $handler
     * @return callable
     * @throws InvalidArgumentException
     */
    private function validateAndNormalizeHandler(callable|array $handler): callable
    {
        if (is_array($handler)) {
            return $this->normalizeArrayHandler($handler);
        }

        if (is_string($handler)) {
            // Bloquear funções perigosas
            $dangerous = [
                'system', 'exec', 'passthru', 'shell_exec', 'eval',
                'assert', 'create_function', 'call_user_func', 'call_user_func_array'
            ];

            if (in_array(strtolower($handler), $dangerous, true)) {
                throw new InvalidArgumentException("Dangerous callable not allowed: {$handler}");
            }
        }

        return $handler;
    }

    /**
     * @param array<int|string, mixed> $handler
     * @return callable
     */
    protected function normalizeArrayHandler(array $handler): callable
    {
        if (count($handler) !== 2) {
            throw new InvalidArgumentException(
                'Array handler must be [ControllerClass, method]'
            );
        }

        $values = array_values($handler);
        [$controller, $method] = $values;

        if (!is_string($controller) || !is_string($method)) {
            throw new InvalidArgumentException(
                'Invalid array handler format'
            );
        }

        // Valida que a classe existe
        if (!class_exists($controller)) {
            throw new InvalidArgumentException(
                "Controller class does not exist: {$controller}"
            );
        }

        // Valida namespace se configurado
        if (!empty($this->allowedNamespaces)) {
            $isAllowed = false;
            foreach ($this->allowedNamespaces as $namespace) {
                if (str_starts_with($controller, $namespace)) {
                    $isAllowed = true;
                    break;
                }
            }

            if (!$isAllowed) {
                throw new InvalidArgumentException(
                    "Controller must be in allowed namespace: {$controller}. " .
                    "Allowed: " . implode(', ', $this->allowedNamespaces)
                );
            }
        }

        return static function (...$args) use ($controller, $method) {
            $instance = new $controller();

            if (!method_exists($instance, $method)) {
                throw new RuntimeException(
                    "Method {$method} does not exist on {$controller}"
                );
            }

            return $instance->$method(...$args);
        };
    }

    /**
     * Normaliza e valida o path da rota
     *
     * @param string $path
     * @return string
     * @throws InvalidArgumentException
     */
    private function normalizePath(string $path): string
    {
        // Remove slashes duplicados
        if (str_contains($path, '//')) {
            $path = preg_replace('#/+#', '/', $path);
        }

        // Remove trailing slash (exceto root)
        $path = $path !== '/' ? rtrim($path, '/') : '/';

        // Detecta path traversal
        // Without percent escapes, decoding cannot introduce traversal tokens.
        $decoded = str_contains($path, '%') ? urldecode($path) : $path;
        if (str_contains($decoded, '..') || str_contains($decoded, '\\')) {
            throw new InvalidArgumentException('Path traversal detected in route: ' . $path);
        }

        return $path;
    }

    /**
     * Busca e executa uma rota com middlewares
     *
     * @param string $method Método HTTP
     * @param string $path Caminho da requisição
     * @param array<string, mixed> $initialData Dados iniciais para o contexto
     * @return Response Resposta processada
     * @throws RuntimeException Se rota não for encontrada
     * @throws InvalidArgumentException Se método HTTP for inválido
     */
    public function dispatch(string $method, string $path, array $initialData = []): Response
    {
        // Valida o método HTTP
        $validatedMethod = HttpMethod::fromString($method);
        $originalMethod = $validatedMethod->value;

        // Tratar HEAD como GET
        $searchMethod = $originalMethod === 'HEAD' ? 'GET' : $originalMethod;

        $route = $this->findRouteInternal($searchMethod, $path);

        // Se não encontrou e é OPTIONS, retorna lista de métodos permitidos
        if ($route === null && $originalMethod === 'OPTIONS') {
            return $this->handleOptions($path);
        }

        if ($route === null) {
            throw new RuntimeException("Route not found: {$originalMethod} {$path}");
        }

        // Cria contexto da requisição
        $context = new RequestContext($originalMethod, $path, $route['params'], $initialData);
        $handler = $route['handler'];

        // Combina middlewares: globais + específicos da rota
        if ($this->globalMiddlewares === [] && $route['middlewares'] === []) {
            $result = $handler($context, new Response());
            $response = $result instanceof Response ? $result : new Response($result);
            return $originalMethod === 'HEAD' ? $response->withBody(null) : $response;
        }

        if ($this->globalMiddlewares === [] && $route['middlewares'] !== []) {
            $pipelineKey = $searchMethod . '::' . $this->normalizePath($path);
            $chain = $this->compiledRoutePipelines[$pipelineKey] ?? null;
            if ($chain !== null) {
                $this->pipelineReferences[$pipelineKey] = true;
            } else {
                if (!isset($this->pipelineAdmission[$pipelineKey])) {
                    if (count($this->pipelineAdmission) >= $this->admissionCapacity) {
                        while (true) {
                            $victim = $this->admissionRing[$this->admissionHand % $this->admissionCapacity];
                            $this->admissionHand = ($this->admissionHand + 1) % $this->admissionCapacity;
                            if (!empty($this->admissionReferences[$victim])) { $this->admissionReferences[$victim] = false; continue; }
                            unset($this->pipelineAdmission[$victim], $this->admissionReferences[$victim]); break;
                        }
                    }
                    $slot = $this->admissionFill < $this->admissionCapacity ? $this->admissionFill++ : $this->admissionHand;
                    $this->admissionRing[$slot] = $pipelineKey;
                    $this->admissionReferences[$pipelineKey] = false;
                }
                if (($this->pipelineAdmission[$pipelineKey] = ($this->pipelineAdmission[$pipelineKey] ?? 0) + 1) < 3) {
                    $chain = $this->buildMiddlewarePipeline($handler, $route['middlewares']);
                } else {
                $chain = $this->buildMiddlewarePipeline($handler, $route['middlewares']);
                if ($this->pipelineFill < $this->pipelineCapacity) {
                    $slot = $this->pipelineFill++;
                    $this->pipelineRing[$slot] = $pipelineKey;
                } else {
                    while (true) {
                        $slot = $this->pipelineHand;
                        $victim = $this->pipelineRing[$slot];
                        $this->pipelineHand = ($slot + 1) % $this->pipelineCapacity;
                        if ($victim !== null && !empty($this->pipelineReferences[$victim])) { $this->pipelineReferences[$victim] = false; continue; }
                        if ($victim !== null) { unset($this->compiledRoutePipelines[$victim], $this->pipelineReferences[$victim]); }
                        $this->pipelineRing[$slot] = $pipelineKey; break;
                    }
                }
                $this->compiledRoutePipelines[$pipelineKey] = $chain;
                $this->pipelineReferences[$pipelineKey] = false;
                }
            }
            $response = $chain($context);
            return $originalMethod === 'HEAD' ? $response->withBody(null) : $response;
        }

        if ($this->globalMiddlewares === []) {
            $allMiddlewares = $route['middlewares'];
        } elseif ($route['middlewares'] === []) {
            $allMiddlewares = $this->globalMiddlewares;
        } else {
        $allMiddlewares = array_merge($this->globalMiddlewares, $route['middlewares']);

        }

        // Cria a cadeia de execução (middlewares + handler)

        // Constrói a cadeia de middlewares (de trás para frente)
        $chain = $this->buildMiddlewarePipeline($handler, $allMiddlewares);

        $response = $chain($context);

        // Se era HEAD, remove o body
        if ($originalMethod === 'HEAD') {
            $response = $response->withBody(null);
        }

        return $response;
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
        // Valida e normaliza o método
        $method = HttpMethod::fromString($method)->value;

        return $this->findRouteInternal($method, $path);
    }

    /**
     * Internal matcher for dispatch, which already has a normalized method.
     *
     * @param string $method
     * @param string $path
     * @return array{handler:callable,middlewares:array<int,MiddlewareInterface>,params:array<string,string>}|null
     */
    private function findRouteInternal(string $method, string $path): ?array
    {

        $fullPath = $method . '::' . $path;
        $normalized = '/' . $fullPath;

        // An exact registered path has already passed normalization and validation.
        if (isset($this->staticMap[$normalized])) {
            return $this->staticMap[$normalized];
        }

        $normalizedPath = $this->normalizePath($path);
        if ($normalizedPath !== $path) {
            $fullPath = $method . '::' . $normalizedPath;
            $normalized = '/' . $fullPath;
            if (isset($this->staticMap[$normalized])) {
                return $this->staticMap[$normalized];
            }
        }

        // cache
        if (isset($this->routeCache[$normalized])) {
            $entry = $this->routeCache[$normalized];
            if ($this->clockPolicy) {
                $this->clockReferences[$normalized] = true;
                return $entry;
            }
            unset($this->routeCache[$normalized]);
            $this->routeCache[$normalized] = $entry;
            return $entry;
        }

        $parts = explode('/', $fullPath);
        $result = $this->matchNode($this->root, $parts, 0, []);

        if ($result !== null) {

            // store in cache
            if ($this->clockPolicy) {
                $this->clockInsert($normalized, $result);
            } else {
                $this->routeCache[$normalized] = $result;
            }
            if (!$this->clockPolicy && count($this->routeCache) > $this->cacheLimit) {
                reset($this->routeCache);
                /** @var string|null $k */
                $k = key($this->routeCache);
                if ($k !== null) {
                    unset($this->routeCache[$k]);
                }

                // Rebuild occasionally to discard deleted array slots without
                // changing the entries or their least-recently-used order.
                if ($this->cacheLimit >= 256 && ++$this->cacheEvictions >= $this->cacheCompactionInterval) {
                    $this->routeCache = array_slice($this->routeCache, 0, null, true);
                    $this->cacheEvictions = 0;
                }
            }

            return $result;
        }

        return null;
    }

    /** @param array{handler:callable,middlewares:array<int,MiddlewareInterface>,params:array<string,string>} $entry */
    private function clockInsert(string $key, array $entry): void
    {
        if ($this->cacheLimit < 1) {
            return;
        }
        if ($this->clockFill < $this->cacheLimit) {
            $slot = $this->clockFill++;
            $this->clockRing[$slot] = $key;
            $this->clockReferences[$key] = false;
            $this->routeCache[$key] = $entry;
            return;
        }

        while (true) {
            $slot = $this->clockHand;
            $victim = $this->clockRing[$slot];
            $this->clockHand = ($slot + 1) % $this->cacheLimit;
            if ($victim !== null && !empty($this->clockReferences[$victim])) {
                $this->clockReferences[$victim] = false;
                continue;
            }
            if ($victim !== null) {
                unset($this->routeCache[$victim], $this->clockReferences[$victim]);
            }
            $this->clockRing[$slot] = $key;
            $this->clockReferences[$key] = false;
            $this->routeCache[$key] = $entry;
            return;
        }
    }

    /**
     * Match exact children before parameter children, falling back when an
     * exact branch does not lead to a complete route.
     *
     * @param list<string> $parts
     * @param array<string, string> $params
     * @return array{handler:callable,middlewares:array<int,MiddlewareInterface>,params:array<string,string>}|null
     */
    private function matchNode(TreeNode $node, array $parts, int $index, array $params): ?array
    {
        $count = count($parts);
        while ($index < $count) {
            $segment = $parts[$index];
            if (isset($node->children[$segment])) {
                // Only a competing parameter branch needs a fallback frame.
                if ($node->paramChild === null) {
                    $node = $node->children[$segment];
                    ++$index;
                    continue;
                }

                $result = $this->matchNode($node->children[$segment], $parts, $index + 1, $params);
                if ($result !== null) {
                    return $result;
                }
            }

            if ($node->paramChild === null) {
                return null;
            }
            if (strlen($segment) > $this->maxParamLength) {
                throw new RuntimeException(
                    "Route parameter exceeds maximum length of {$this->maxParamLength} characters",
                );
            }

            $params[$node->paramName ?? 'param'] = $segment;
            $node = $node->paramChild;
            ++$index;
        }

        if ($node->isEndOfRoute && $node->handler !== null) {
            return [
                'handler' => $node->handler,
                'middlewares' => $node->middlewares,
                'params' => $params,
            ];
        }

        return null;
    }

    /**
     * Trata requisições OPTIONS retornando métodos permitidos
     *
     * @param string $path
     * @return Response
     */
    private function handleOptions(string $path): Response
    {
        $methods = [];

        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            if ($this->findRoute($method, $path) !== null) {
                $methods[] = $method;
            }
        }

        if (in_array('GET', $methods, true)) {
            $methods[] = 'HEAD';
        }

        $methods[] = 'OPTIONS';

        return (new Response())
            ->withHeader('Allow', implode(', ', array_unique($methods)))
            ->withStatus(204);
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
            try {
                if ($middleware instanceof MiddlewareInterface) {
                    return $middleware->process($context, $next);
                }

                $result = $middleware($context, $next);
                if ($result instanceof Response) {
                    return $result;
                }

                return new Response($result);
            } catch (Throwable $e) {
                // Retorna erro 500
                // Nota: Em produção, considere usar um logger para registrar erros
                return (new Response())
                    ->withStatus(500)
                    ->withBody(['error' => 'Internal server error', 'message' => $e->getMessage()]);
            }
        };
    }

    /** @param array<int, MiddlewareInterface> $middlewares */
    private function buildMiddlewarePipeline(callable $handler, array $middlewares): callable
    {
        $chain = static function (RequestContext $ctx) use ($handler): Response {
            $result = $handler($ctx, new Response());
            return $result instanceof Response ? $result : new Response($result);
        };
        foreach (array_reverse($middlewares) as $middleware) {
            $chain = $this->wrapMiddleware($middleware, $chain);
        }
        return $chain;
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
        $this->clockReferences = [];
        $this->clockRing = array_fill(0, $this->cacheLimit, null);
        $this->clockFill = 0;
        $this->clockHand = 0;
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
        if ($this->clockPolicy) {
            $this->routeCache = [];
            $this->clockReferences = [];
            $this->clockRing = array_fill(0, max(1, $limit), null);
            $this->clockFill = 0;
            $this->clockHand = 0;
        }
        $this->cacheCompactionInterval = max(256, $limit >> 1);
    }

    /**
     * Define o tamanho máximo de parâmetro de rota
     *
     * @param int $length
     * @return void
     */
    public function setMaxParamLength(int $length): void
    {
        $this->maxParamLength = $length;
    }

    /**
     * Define os namespaces permitidos para controllers
     *
     * @param array<string> $namespaces
     * @return void
     */
    public function setAllowedNamespaces(array $namespaces): void
    {
        $this->allowedNamespaces = $namespaces;
    }
}
