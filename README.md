# SwiftRouter

Router PHP moderno para PHP 8.4+, focado em matching eficiente por árvore, rotas estáticas, parâmetros dinâmicos, grupos e middleware. A árvore evita um scan linear de todas as rotas; rotas estáticas usam um fast-path e URLs dinâmicas repetidas podem usar cache.

O pacote expõe `RequestContext`, `Response` e middleware baseado nesses objetos. Aceita middlewares PSR-15 nas rotas. PSR-7 é uma dependência de interoperabilidade, mas `dispatch()` recebe método/caminho e retorna a `Response` própria do pacote; a adaptação para o servidor HTTP fica a cargo da aplicação.

## Requisitos e instalação

- PHP >= 8.4
- `psr/http-message`, `psr/http-server-handler`, `psr/http-server-middleware`
- `laminas/laminas-diactoros`

```bash
composer require omegaalfa/swiftrouter
```

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Omegaalfa\SwiftRouter\Router\SwiftRouter;

$router = new SwiftRouter();
$router->get('/users/:id', static function ($context, $response) {
    return $response->withBody(['id' => $context->params['id']]);
});

$response = $router->dispatch('GET', '/users/42');
var_dump($response->statusCode, $response->body);
```

## Rotas e grupos

`SwiftRouter` fornece `get()`, `post()`, `put()`, `delete()` e `patch()`. Para outros métodos, use `addRoute()`.

```php
$router->get('/users/:userId/orders/:orderId', $handler);
$router->post('/users', $handler);
$router->addRoute('OPTIONS', '/health', $handler);

$router->group('/api/v1', function (SwiftRouter $router): void {
    $router->get('/users/:id', $handler);
}, [$authMiddleware]);
```

Parâmetros ficam em `$context->params`. Grupos adicionam prefixo e middleware às rotas do callback e podem ser aninhados. Middlewares globais são registrados com `use()`; os da rota são o terceiro argumento dos métodos HTTP.

```php
$router->use($loggingMiddleware);
$router->get('/admin', $handler, [$authorizationMiddleware]);
```

A ordem é: globais, grupos externos para internos, middlewares da rota e handler.

## Dispatch, contexto e resposta

```php
$response = $router->dispatch(
    $_SERVER['REQUEST_METHOD'],
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/',
    ['request_id' => 'abc']
);

http_response_code($response->statusCode);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
echo is_string($response->body)
    ? $response->body
    : json_encode($response->body, JSON_THROW_ON_ERROR);
```

`RequestContext` contém `method`, `path`, `params` e dados (`set()`, `get()`, `has()`). `Response` contém `body`, `statusCode` e `headers`; seus métodos `with*()` retornam uma cópia.

O caminho é normalizado para matching: barras repetidas e trailing slash não alteram a rota. Percent-encoding é decodificado durante a normalização; parâmetros chegam decodificados. Sequências codificadas que resultem em `..` ou barras invertidas são rejeitadas. `findRoute()` consulta sem executar o handler.

`dispatch()` lança `RuntimeException` para rota inexistente e `InvalidArgumentException` para método inválido. `HEAD` usa a rota `GET` quando disponível e remove o corpo; `OPTIONS` pode responder com os métodos permitidos.

## Middleware

```php
use Omegaalfa\SwiftRouter\Interfaces\MiddlewareInterface;
use Omegaalfa\SwiftRouter\Router\RequestContext;
use Omegaalfa\SwiftRouter\Router\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function process(RequestContext $context, callable $next): Response
    {
        if ($context->get('authenticated') !== true) {
            return (new Response())->withStatus(401);
        }
        return $next($context);
    }
}
```

Callable middlewares também são aceitos por `use()`. O middleware nativo usa `RequestContext`/`Response`; ao usar PSR-15, a aplicação deve adaptar mensagens PSR-7 conforme necessário.

## Arquitetura e performance

```text
request -> normalização -> staticMap / routeCache / tree
        -> RequestContext -> middleware -> handler -> Response
```

`staticMap` atende rotas sem parâmetros. A Tree/Trie atende matching dinâmico e `routeCache` acelera URLs repetidas. Há fallback static → dynamic quando o ramo estático não completa o matching. A adição de rotas invalida o cache dinâmico. `dispatch()` possui fast-path para zero middleware, reduz trabalho na composição e mantém pipelines reutilizadas em cache interno limitado. Esses detalhes são implementação, não contrato público.

## Benchmarks

- `benchmarks/router.php`: matching/dispatch em CLI.
- `benchmarks/hot_path.php`: microbenchmark de componentes do hot path.
- `benchmarks/competitors/`: arena isolada de comparação entre routers.
- `benchmarks/experiments/`: experimentos internos de hipóteses de otimização.
- `benchmarks/sanity-http/`: scripts de sanity checks HTTP.
- `benchmarks/http/`: HTTP end-to-end com FrankenPHP classic/worker, isolamento e carga com `oha`.

Microbenchmarks CLI medem operações PHP em processo e não substituem HTTP end-to-end, que inclui servidor, cliente, conexões e lifecycle HTTP. Resultados variam conforme CPU, PHP, OPcache/JIT, sistema operacional, containerização e servidor HTTP. Não há ranking publicado contra outros routers.

## Docker e FrankenPHP

Docker é opcional para usar a biblioteca. O Compose fornece PHP 8.4 e PHP 8.5 opcional:

```bash
docker compose config
docker compose --profile tools run --rm composer install
docker compose --profile tools run --rm php84-cli -v
docker compose up -d frankenphp84-classic frankenphp84-worker
```

Classic inicializa o router conforme o lifecycle clássico. Worker mantém router e rotas residentes entre requests; estado específico de cada request deve ficar no contexto. O script de isolamento verifica que esse estado não vaza.

```bash
php benchmarks/http/isolation.php http://127.0.0.1:8080
php benchmarks/http/isolation.php http://127.0.0.1:8081
php benchmarks/http/benchmark.php http://127.0.0.1:8080 2000 200 /bench/users/42
php benchmarks/http/benchmark.php http://127.0.0.1:8081 2000 200 /bench/users/42
benchmarks/http/load.sh http://127.0.0.1:8081 /bench/users/42 32 30s 5s
```

`benchmark.php` é serial e usa o HTTP stream wrapper. `load.sh` usa `oha` em container separado, com keep-alive e concorrência configurável.

```bash
docker compose --profile php85 up -d frankenphp85-classic frankenphp85-worker
docker compose --profile tools run --rm php85-cli -v
```

## Desenvolvimento e qualidade

```bash
composer install
composer test
composer phpstan
```

PHPUnit cobre matching, parâmetros, métodos HTTP, grupos, middleware, trailing slash, encoding, fallback e invalidação do cache. A suíte inclui regressões específicas de dispatch da pipeline, matching e route cache. PHPStan verifica o código-fonte. Docker/FrankenPHP é usado adicionalmente para HTTP e isolamento do worker.

## Metodologia de benchmarking

Benchmarks competitivos ficam isolados das dependências principais. Workloads são reproduzíveis e alterações de performance devem preservar corretude. Microbenchmarks não equivalem a performance HTTP end-to-end; resultados dependem do ambiente.

## Licença

MIT.
