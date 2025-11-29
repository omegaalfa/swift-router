# 🔗 Guia Completo - SwiftRouter

## 📚 Índice

1. [O que são Middlewares](#o-que-são-middlewares)
2. [Como Funcionam](#como-funcionam)
3. [Tipos de Middlewares](#tipos-de-middlewares)
4. [Criando Middlewares](#criando-middlewares)
5. [Exemplos Práticos](#exemplos-práticos)
6. [Route Groups](#route-groups)
7. [Boas Práticas](#boas-práticas)

---

## Route Groups

### O que são Route Groups?

Route Groups permitem **agrupar rotas com prefixos e middlewares compartilhados**, evitando repetição e facilitando organização.

---

### Sintaxe Básica

```php
$router->group('/prefixo', function($router) {
    // Rotas dentro do grupo
    $router->get('/users', $handler);
}, [$middleware1, $middleware2]);
```

---

### 1. Grupo Simples com Prefixo

```php
$router->group('/api', function($router) {
    $router->get('/users', $handler);    // GET /api/users
    $router->get('/posts', $handler);    // GET /api/posts
    $router->post('/users', $handler);   // POST /api/users
});
```

**Resultado:**
- `/api/users` (GET e POST)
- `/api/posts` (GET)

---

### 2. Grupo com Middlewares

```php
$router->group('/admin', function($router) {
    $router->get('/dashboard', $dashboardHandler);
    $router->get('/users', $usersHandler);
    $router->post('/settings', $settingsHandler);
}, [new AuthMiddleware(), new AdminMiddleware()]);
```

**Comportamento:**
- Todos os handlers dentro do grupo passam pelos middlewares `AuthMiddleware` e `AdminMiddleware`
- Ideal para proteger áreas administrativas

---

### 3. Grupos Aninhados (Versionamento de API)

```php
$router->group('/api', function($router) {
    
    // API v1
    $router->group('/v1', function($router) {
        $router->get('/users', $v1UsersHandler);      // /api/v1/users
        $router->get('/posts', $v1PostsHandler);      // /api/v1/posts
    });
    
    // API v2
    $router->group('/v2', function($router) {
        $router->get('/users', $v2UsersHandler);      // /api/v2/users
        $router->get('/posts', $v2PostsHandler);      // /api/v2/posts
    });
});
```

**Uso comum:** Versionamento de APIs, multi-idioma, multi-tenancy

---

### 4. Grupos com Parâmetros Dinâmicos

```php
$router->group('/users/:userId', function($router) {
    
    $router->get('/profile', function($ctx, $res) {
        $userId = $ctx->params['userId'];
        return $res->withBody("Profile of user {$userId}");
    });
    
    $router->get('/posts', function($ctx, $res) {
        $userId = $ctx->params['userId'];
        return $res->withBody("Posts of user {$userId}");
    });
    
    $router->get('/posts/:postId', function($ctx, $res) {
        $userId = $ctx->params['userId'];
        $postId = $ctx->params['postId'];
        return $res->withBody("User {$userId}, Post {$postId}");
    });
});
```

**Resultado:**
- `/users/123/profile` → `userId = 123`
- `/users/123/posts` → `userId = 123`
- `/users/123/posts/456` → `userId = 123`, `postId = 456`

---

### 5. API REST Completa com Grupos

```php
$router = new SwiftRouter();

// Middlewares globais
$router->use(new JsonMiddleware());

$router->group('/api/v1', function($router) {
    
    // Public endpoints
    $router->get('/status', function($ctx, $res) {
        return $res->withBody(['status' => 'online']);
    });
    
    // Protected endpoints
    $router->group('/users', function($router) {
        $router->get('/', $listUsersHandler);           // GET /api/v1/users
        $router->post('/', $createUserHandler);         // POST /api/v1/users
        $router->get('/:id', $showUserHandler);         // GET /api/v1/users/123
        $router->put('/:id', $updateUserHandler);       // PUT /api/v1/users/123
        $router->delete('/:id', $deleteUserHandler);    // DELETE /api/v1/users/123
    }, [new AuthMiddleware()]);
    
    $router->group('/posts', function($router) {
        $router->get('/', $listPostsHandler);           // GET /api/v1/posts
        $router->get('/:id', $showPostHandler);         // GET /api/v1/posts/456
    }, [new AuthMiddleware()]);
});
```

---

### 6. Hierarquia de Middlewares em Grupos

Os middlewares são executados na seguinte ordem:

1. **Middlewares Globais** (`$router->use()`)
2. **Middlewares do Grupo Pai**
3. **Middlewares do Grupo Filho**
4. **Middlewares da Rota**

**Exemplo:**

```php
$router->use(new GlobalMiddleware());

$router->group('/api', function($router) {
    
    $router->group('/admin', function($router) {
        
        $router->get('/users', $handler, [new RouteMiddleware()]);
        
    }, [new AdminMiddleware()]);
    
}, [new ApiAuthMiddleware()]);
```

**Ordem de execução para `GET /api/admin/users`:**

```
Request
  ↓
GlobalMiddleware
  ↓
ApiAuthMiddleware (grupo /api)
  ↓
AdminMiddleware (grupo /admin)
  ↓
RouteMiddleware (rota específica)
  ↓
Handler
  ↓
Response
```

---

### 7. Organização por Domínio/Área

```php
// Website público
$router->group('/web', function($router) {
    $router->get('/', $homeHandler);
    $router->get('/about', $aboutHandler);
    $router->get('/contact', $contactHandler);
});

// Painel administrativo
$router->group('/admin', function($router) {
    $router->get('/dashboard', $dashboardHandler);
    
    $router->group('/users', function($router) {
        $router->get('/', $listUsersHandler);
        $router->post('/', $createUserHandler);
    });
    
    $router->group('/settings', function($router) {
        $router->get('/general', $generalHandler);
        $router->get('/security', $securityHandler);
    });
}, [new AuthMiddleware(), new AdminMiddleware()]);

// API
$router->group('/api', function($router) {
    $router->get('/health', $healthHandler);
    $router->get('/metrics', $metricsHandler);
}, [new ApiAuthMiddleware()]);
```

---

### Boas Práticas com Grupos

#### ✅ DO

1. **Use grupos para organizar rotas relacionadas**
   ```php
   $router->group('/blog', function($router) {
       $router->get('/', $listPostsHandler);
       $router->get('/:slug', $showPostHandler);
   });
   ```

2. **Aplique middlewares comuns no grupo**
   ```php
   $router->group('/admin', function($router) {
       // Todas exigem autenticação
   }, [new AuthMiddleware(), new AdminMiddleware()]);
   ```

3. **Use para versionamento de API**
   ```php
   $router->group('/api/v1', function($router) { /* ... */ });
   $router->group('/api/v2', function($router) { /* ... */ });
   ```

4. **Grupos aninhados para hierarquia clara**
   ```php
   $router->group('/api', function($router) {
       $router->group('/v1', function($router) {
           $router->group('/users', function($router) {
               // /api/v1/users/*
           });
       });
   });
   ```

#### ❌ DON'T

1. **Não crie grupos muito profundos**
   ```php
   // ❌ Difícil de manter (mais de 3 níveis)
   $router->group('/a', fn($r) =>
       $r->group('/b', fn($r) =>
           $r->group('/c', fn($r) =>
               $r->group('/d', fn($r) => /* ... */)
           )
       )
   );
   ```

2. **Não repita prefixos manualmente**
   ```php
   // ❌ Redundante
   $router->group('/api', function($router) {
       $router->get('/api/users', $handler);  // /api/api/users
   });
   
   // ✅ Correto
   $router->group('/api', function($router) {
       $router->get('/users', $handler);      // /api/users
   });
   ```

3. **Não abuse de middlewares**
   ```php
   // ❌ Muitos middlewares = performance ruim
   $router->group('/api', function($router) {
       // ...
   }, [$mw1, $mw2, $mw3, $mw4, $mw5, $mw6]);
   ```

---

### Casos de Uso Reais

#### Multi-tenancy

```php
$router->group('/tenant/:tenantId', function($router) {
    $router->get('/dashboard', $dashboardHandler);
    $router->get('/users', $usersHandler);
    $router->get('/reports', $reportsHandler);
}, [new TenantMiddleware()]);
```

#### Internacionalização

```php
$router->group('/:locale', function($router) {
    $router->get('/', $homeHandler);
    $router->get('/about', $aboutHandler);
    $router->get('/products', $productsHandler);
}, [new LocaleMiddleware()]);

// /en/, /pt/, /es/, etc
```

#### Subdomain-like Routing

```php
$router->group('/api', function($router) {
    $router->get('/users', $usersHandler);
}, [new ApiMiddleware()]);

$router->group('/app', function($router) {
    $router->get('/dashboard', $dashboardHandler);
}, [new AppMiddleware()]);
```

---

## O que são Middlewares

Middlewares são **funções intermediárias** que processam requisições antes de chegarem ao handler final. Eles podem:

- ✅ Modificar o contexto da requisição
- ✅ Interceptar e modificar a resposta
- ✅ Interromper a execução (autenticação, validação)
- ✅ Adicionar funcionalidades transversais (logging, cache, CORS)

---

## Como Funcionam

### Fluxo de Execução

```
Requisição
    ↓
[Middleware Global 1]
    ↓
[Middleware Global 2]
    ↓
[Middleware da Rota 1]
    ↓
[Middleware da Rota 2]
    ↓
[Handler Final]
    ↓
Response
```

### Estrutura Básica

```php
class MeuMiddleware implements MiddlewareInterface
{
    public function process(RequestContext $context, callable $next): Response
    {
        // 1️⃣ Código ANTES do handler (requisição)
        
        $response = $next($context); // ⚡ Chama próximo middleware/handler
        
        // 2️⃣ Código DEPOIS do handler (resposta)
        
        return $response;
    }
}
```

---

## Tipos de Middlewares

### 1. **Middleware Global**

Executado em **todas as rotas**:

```php
$router->use(new LoggerMiddleware());
$router->use(new CorsMiddleware());
```

### 2. **Middleware de Rota**

Executado apenas em **rotas específicas**:

```php
$router->get('/admin', $handler, [
    new AuthMiddleware(),
    new AdminMiddleware()
]);
```

### 3. **Middleware Callable**

Sem necessidade de criar classe:

```php
$router->use(function(RequestContext $ctx, callable $next): Response {
    echo "Before\n";
    $response = $next($ctx);
    echo "After\n";
    return $response;
});
```

---

## Criando Middlewares

### Interface MiddlewareInterface

```php
interface MiddlewareInterface
{
    public function process(RequestContext $context, callable $next): Response;
}
```

### RequestContext

Objeto que carrega dados da requisição:

```php
class RequestContext
{
    public string $method;      // GET, POST, etc
    public string $path;        // /users/123
    public array $params;       // ['id' => '123']
    public array $data;         // Dados compartilhados
    
    // Métodos úteis
    public function set(string $key, mixed $value): void;
    public function get(string $key, mixed $default = null): mixed;
    public function has(string $key): bool;
}
```

### Response

Objeto imutável de resposta:

```php
class Response
{
    public mixed $body;
    public int $statusCode;
    public array $headers;
    
    public function withBody(mixed $body): self;
    public function withStatus(int $code): self;
    public function withHeader(string $name, string $value): self;
}
```

---

## Exemplos Práticos

### 1. Logger Middleware

```php
class LoggerMiddleware implements MiddlewareInterface
{
    public function process(RequestContext $context, callable $next): Response
    {
        $start = microtime(true);
        
        error_log("[{$context->method}] {$context->path}");
        
        $response = $next($context);
        
        $duration = microtime(true) - $start;
        error_log("Response: {$response->statusCode} ({$duration}s)");
        
        return $response;
    }
}
```

**Uso:**
```php
$router->use(new LoggerMiddleware());
```

---

### 2. Authentication Middleware

```php
class AuthMiddleware implements MiddlewareInterface
{
    public function process(RequestContext $context, callable $next): Response
    {
        $token = $context->get('token');
        
        if (!$this->validateToken($token)) {
            return (new Response())
                ->withStatus(401)
                ->withBody(['error' => 'Unauthorized']);
        }
        
        // Adiciona user_id ao contexto
        $context->set('user_id', $this->getUserId($token));
        
        return $next($context);
    }
    
    private function validateToken(?string $token): bool
    {
        return $token === 'secret-token';
    }
    
    private function getUserId(string $token): int
    {
        return 123; // Busca do banco
    }
}
```

**Uso:**
```php
// Rota protegida
$router->get('/profile', function($ctx, $res) {
    $userId = $ctx->get('user_id');
    return $res->withBody(['user_id' => $userId]);
}, [new AuthMiddleware()]);

// Dispatch com token
$response = $router->dispatch('GET', '/profile', ['token' => 'secret-token']);
```

---

### 3. CORS Middleware

```php
class CorsMiddleware implements MiddlewareInterface
{
    public function process(RequestContext $context, callable $next): Response
    {
        $response = $next($context);
        
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }
}
```

**Uso:**
```php
$router->use(new CorsMiddleware());
```

---

### 4. Cache Middleware

```php
class CacheMiddleware implements MiddlewareInterface
{
    private array $cache = [];
    private int $ttl = 60; // segundos
    
    public function process(RequestContext $context, callable $next): Response
    {
        $key = "{$context->method}:{$context->path}";
        
        // Cache hit
        if (isset($this->cache[$key])) {
            [$response, $time] = $this->cache[$key];
            
            if (time() - $time < $this->ttl) {
                return $response->withHeader('X-Cache', 'HIT');
            }
        }
        
        // Cache miss
        $response = $next($context);
        $this->cache[$key] = [$response, time()];
        
        return $response->withHeader('X-Cache', 'MISS');
    }
}
```

---

### 5. Validation Middleware

```php
class ValidationMiddleware implements MiddlewareInterface
{
    public function __construct(private array $rules) {}
    
    public function process(RequestContext $context, callable $next): Response
    {
        $errors = [];
        
        foreach ($this->rules as $param => $rule) {
            $value = $context->params[$param] ?? null;
            
            if ($rule === 'required' && empty($value)) {
                $errors[] = "'{$param}' is required";
            }
            
            if ($rule === 'numeric' && !is_numeric($value)) {
                $errors[] = "'{$param}' must be numeric";
            }
            
            if ($rule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "'{$param}' must be valid email";
            }
        }
        
        if (!empty($errors)) {
            return (new Response())
                ->withStatus(400)
                ->withBody(['errors' => $errors]);
        }
        
        return $next($context);
    }
}
```

**Uso:**
```php
$router->post('/users', $handler, [
    new ValidationMiddleware([
        'email' => 'email',
        'age' => 'numeric'
    ])
]);
```

---

### 6. Rate Limiting Middleware

```php
class RateLimitMiddleware implements MiddlewareInterface
{
    private array $requests = [];
    
    public function __construct(
        private int $maxRequests = 100,
        private int $window = 60
    ) {}
    
    public function process(RequestContext $context, callable $next): Response
    {
        $ip = $context->get('ip', '0.0.0.0');
        $now = time();
        
        // Limpa requisições antigas
        $this->requests[$ip] = array_filter(
            $this->requests[$ip] ?? [],
            fn($time) => $time > $now - $this->window
        );
        
        // Verifica limite
        if (count($this->requests[$ip]) >= $this->maxRequests) {
            return (new Response())
                ->withStatus(429)
                ->withBody(['error' => 'Too many requests'])
                ->withHeader('Retry-After', (string)$this->window);
        }
        
        // Registra requisição
        $this->requests[$ip][] = $now;
        
        return $next($context);
    }
}
```

---

### 7. JSON Response Middleware

```php
class JsonMiddleware implements MiddlewareInterface
{
    public function process(RequestContext $context, callable $next): Response
    {
        $response = $next($context);
        
        // Converte array para JSON
        if (is_array($response->body)) {
            return $response
                ->withBody(json_encode($response->body))
                ->withHeader('Content-Type', 'application/json');
        }
        
        return $response;
    }
}
```

---

## Boas Práticas

### ✅ DO

1. **Use middlewares para preocupações transversais**
   - Logging, auth, CORS, cache, validação

2. **Mantenha middlewares simples e focados**
   - Um middleware = uma responsabilidade

3. **Use o contexto para compartilhar dados**
   ```php
   $context->set('user', $user);
   $context->set('db', $connection);
   ```

4. **Retorne sempre uma Response**
   ```php
   return $next($context);
   return $response->withStatus(401);
   ```

5. **Use middlewares globais para funcionalidades comuns**
   ```php
   $router->use(new LoggerMiddleware());
   $router->use(new CorsMiddleware());
   ```

### ❌ DON'T

1. **Não modifique estado global**
   - Use o contexto ao invés de variáveis globais

2. **Não faça operações pesadas em middlewares**
   - Mantenha-os rápidos

3. **Não esqueça de chamar $next()**
   ```php
   // ❌ Errado
   public function process($ctx, $next) {
       return new Response(); // Cadeia quebrada!
   }
   
   // ✅ Correto
   public function process($ctx, $next) {
       $response = $next($ctx);
       return $response;
   }
   ```

4. **Não acople middlewares entre si**
   - Cada um deve ser independente

---

## Ordem de Execução

Middlewares são executados na ordem em que são registrados:

```php
$router
    ->use(new Middleware1())  // 1º
    ->use(new Middleware2())  // 2º
    ->use(new Middleware3()); // 3º

$router->get('/test', $handler, [
    new Middleware4(),        // 4º
    new Middleware5()         // 5º
]);
```

**Fluxo:**
```
Request → MW1 → MW2 → MW3 → MW4 → MW5 → Handler → MW5 → MW4 → MW3 → MW2 → MW1 → Response
```

---

## Exemplos Completos

### API com Autenticação

```php
$router = new SwiftRouter();

// Middlewares globais
$router
    ->use(new LoggerMiddleware())
    ->use(new CorsMiddleware())
    ->use(new JsonMiddleware());

// Rotas públicas
$router->post('/auth/login', $loginHandler);

// Rotas protegidas
$auth = new AuthMiddleware();

$router->get('/profile', $profileHandler, [$auth]);
$router->get('/users', $usersHandler, [$auth]);
$router->post('/posts', $createPostHandler, [$auth, new ValidationMiddleware(['title' => 'required'])]);
```


🎉 **Middlewares e Route Groups totalmente funcionais!**