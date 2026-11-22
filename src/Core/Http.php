<?php

declare(strict_types=1);

namespace App\Core;

final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message === '' ? 'HTTP ' . $status : $message);
    }
}

final class Request
{
    /** @var array<string,string> */
    private array $params = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,mixed> $server
     * @param array<string,mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private array $query = [],
        private array $body = [],
        private array $server = [],
        private array $files = [],
        private ?string $rawBodyOverride = null,
    ) {
    }

    public static function capture(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?: '/'));
        $path = '/' . trim($path, '/');
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        return new self($method, $path, $_GET, $_POST, $_SERVER, $_FILES);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, null);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /** @return array<mixed> */
    public function array(string $key): array
    {
        $value = $this->input($key, []);

        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$key])) {
            return (string) $this->server[$key];
        }
        if (strtoupper($name) === 'CONTENT_TYPE' && isset($this->server['CONTENT_TYPE'])) {
            return (string) $this->server['CONTENT_TYPE'];
        }

        return null;
    }

    public function ip(): string
    {
        if (Env::get('TRUST_PROXY', false)) {
            $forwarded = $this->header('X-Forwarded-For');
            if ($forwarded !== null && $forwarded !== '') {
                return trim(explode(',', $forwarded)[0]);
            }
        }

        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** Raw request body (webhooks read the exact bytes that were signed). */
    public function rawBody(): string
    {
        if ($this->rawBodyOverride !== null) {
            return $this->rawBodyOverride;
        }
        $this->rawBodyOverride = (string) file_get_contents('php://input');

        return $this->rawBodyOverride;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        return Json::decode($this->rawBody());
    }

    public function userAgent(): string
    {
        return Str::limit((string) $this->header('User-Agent'), 250);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function wantsJson(): bool
    {
        return str_contains((string) $this->header('Accept'), 'application/json')
            || str_starts_with($this->path, '/api/');
    }

    /** @return array<string,mixed> */
    public function file(string $key): array
    {
        return is_array($this->files[$key] ?? null) ? $this->files[$key] : [];
    }

    /** @param array<string,string> $params */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->params[$key] ?? $default;
    }

    public function session(): Session
    {
        return Session::instance();
    }
}

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(public string $body = '', public int $status = 200, public array $headers = [])
    {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(Json::encode($data), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public static function view(string $template, array $data = [], int $status = 200): self
    {
        return self::html(View::render($template, $data), $status);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function send(): void
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}

final class Route
{
    public string $name = '';
    /** @var list<string> */
    public array $middleware = [];
    public bool $stateless = false;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly mixed $handler,
        public readonly string $regex,
    ) {
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /** @param string|list<string> $middleware */
    public function middleware(string|array $middleware): self
    {
        $this->middleware = array_merge($this->middleware, (array) $middleware);

        return $this;
    }

    /** Disables CSRF verification (webhooks, API tokens, health checks). */
    public function stateless(bool $stateless = true): self
    {
        $this->stateless = $stateless;

        return $this;
    }
}

final class Router
{
    /** @var list<Route> */
    private array $routes = [];
    /** @var array<string,callable> */
    public array $middleware = [];

    public function get(string $path, mixed $handler): Route
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): Route
    {
        return $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, mixed $handler): Route
    {
        $normalized = '/' . trim($path, '/');
        $regex = '#^' . preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $normalized) . '$#';
        $route = new Route($method, $normalized, $handler, $regex);
        $this->routes[] = $route;

        return $route;
    }

    public function url(string $name, array $params = []): string
    {
        foreach ($this->routes as $route) {
            if ($route->name === $name) {
                $path = $route->path;
                foreach ($params as $key => $value) {
                    $path = str_replace('{' . $key . '}', rawurlencode((string) $value), $path);
                }

                return url($path);
            }
        }

        return url('/');
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route->regex, $request->path, $matches)) {
                continue;
            }
            $pathMatched = true;
            $allowed = $route->method === $request->method
                || ($route->method === 'GET' && $request->method === 'HEAD');
            if (!$allowed) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            $request->setParams($params);

            if (!$route->stateless && in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                Csrf::verify($request);
            }

            $destination = function (Request $req) use ($route, $params): Response {
                $result = $this->invoke($route->handler, $req, $params);
                if ($result instanceof Response) {
                    return $result;
                }
                if (is_string($result)) {
                    return Response::html($result);
                }
                throw new HttpException(500, 'Route handler returned an invalid response');
            };

            $middleware = [];
            foreach ($route->middleware as $alias) {
                if (!isset($this->middleware[$alias])) {
                    throw new HttpException(500, 'Unknown middleware: ' . $alias);
                }
                $middleware[] = $this->middleware[$alias];
            }

            return Pipeline::run($middleware, $request, $destination);
        }

        throw new HttpException($pathMatched ? 405 : 404);
    }

    /** @param array<string,string> $params */
    private function invoke(mixed $handler, Request $request, array $params): mixed
    {
        if (is_array($handler) && is_string($handler[0] ?? null)) {
            $class = $handler[0];
            $method = $handler[1];
            if (!class_exists($class)) {
                throw new HttpException(500, 'Controller not found');
            }
            $controller = new $class();

            return $controller->{$method}($request, $params);
        }
        if (is_callable($handler)) {
            return $handler($request, $params);
        }

        throw new HttpException(500, 'Invalid route handler');
    }
}

final class Pipeline
{
    /** @param list<callable> $middleware */
    public static function run(array $middleware, Request $request, callable $destination): Response
    {
        $next = $destination;
        foreach (array_reverse($middleware) as $layer) {
            $previous = $next;
            $next = static fn (Request $req): Response => $layer($req, $previous);
        }

        return $next($request);
    }
}
