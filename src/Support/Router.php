<?php
namespace App\Support;

use App\Middleware\AuthMiddleware;

class Router
{
    private array $routes = [];
    private string $jwtSecret;

    public function __construct(string $jwtSecret)
    {
        $this->jwtSecret = $jwtSecret;
    }

    public function get(string $path, callable $handler, array $permissions = [], bool $authRequired = true): void
    {
        $this->add('GET', $path, $handler, $permissions, $authRequired);
    }

    public function post(string $path, callable $handler, array $permissions = [], bool $authRequired = true): void
    {
        $this->add('POST', $path, $handler, $permissions, $authRequired);
    }

    public function put(string $path, callable $handler, array $permissions = [], bool $authRequired = true): void
    {
        $this->add('PUT', $path, $handler, $permissions, $authRequired);
    }

    public function delete(string $path, callable $handler, array $permissions = [], bool $authRequired = true): void
    {
        $this->add('DELETE', $path, $handler, $permissions, $authRequired);
    }

    public function patch(string $path, callable $handler, array $permissions = [], bool $authRequired = true): void
    {
        $this->add('PATCH', $path, $handler, $permissions, $authRequired);
    }

    private function add(string $method, string $path, callable $handler, 
						 array $permissions, bool $authRequired = true): void
    {
        $this->routes[] = [
            'method'      => $method,
            'path'        => $path,
            'regex'       => $this->pathToRegex($path),
            'handler'     => $handler,
            'permissions' => $permissions,
        	'auth'        => $authRequired
        ];
    }

    public function dispatch(): void {
        $method = $_SERVER['REQUEST_METHOD'];

        if ($method === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = preg_replace('#^/api#', '', $uri);
        $uri = rtrim($uri, '/');
        $uri = $uri === '' ? '/' : $uri;

        foreach ($this->routes as $route) {

            if ($route['method'] !== $method) {
                continue;
            }

            if (!preg_match($route['regex'], $uri, $matches)) {
                continue;
            }

            array_shift($matches);

            if ($route['auth']) {
				$middleware = new AuthMiddleware($this->jwtSecret);

				if (!empty($route['permissions'])) {
					$middleware->handle($route['permissions']);
				} else {
					$middleware->handle();
				}
			}

            call_user_func_array($route['handler'], $matches);
            return;
        }

        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Route not found'
        ]);
    }

    private function pathToRegex(string $path): string
    {
        $regex = preg_replace('#\{id\}#', '(\d+)', $path);
        return '#^' . $regex . '$#';
    }
}
