<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\VerifyCsrf;

/**
 * Small router: maps "GET /tasks/{number}" to [Controller::class, 'method'].
 * Every POST passes the CSRF check. Route groups can add middleware (e.g. role checks).
 */
final class Router
{
    /** @var list<array{method:string, regex:string, handler:array, middleware:list<class-string>}> */
    private array $routes = [];
    private string $prefix = '';
    /** @var list<class-string> */
    private array $middleware = [];

    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /**
     * @param bool $csrf false ONLY for server-to-server or cross-site gateway callbacks,
     *                   whose data is always re-verified with the gateway's API.
     */
    public function post(string $path, array $handler, bool $csrf = true): void
    {
        $this->add('POST', $path, $handler, $csrf);
    }

    /** @param array{prefix?:string, middleware?:list<class-string>} $options */
    public function group(array $options, callable $routes): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->middleware;
        $this->prefix .= $options['prefix'] ?? '';
        $this->middleware = array_merge($this->middleware, $options['middleware'] ?? []);
        $routes($this);
        $this->prefix = $previousPrefix;
        $this->middleware = $previousMiddleware;
    }

    private function add(string $method, string $path, array $handler, bool $csrf = true): void
    {
        $full = '/' . trim($this->prefix . $path, '/');
        $regex = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $full);
        $this->routes[] = [
            'method'     => $method,
            'regex'      => '#^' . $regex . '$#',
            'handler'    => $handler,
            'middleware' => $this->middleware,
            'csrf'       => $csrf,
        ];
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            if ($method === 'POST' && $route['csrf']) {
                (new VerifyCsrf())->handle($request);
            }
            foreach ($route['middleware'] as $middlewareClass) {
                (new $middlewareClass())->handle($request);
            }

            [$class, $action] = $route['handler'];
            $controller = new $class();
            $controller->$action($request, ...array_values($params));
            return;
        }

        throw new HttpException($pathMatched ? 405 : 404);
    }
}
