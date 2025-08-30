<?php
class Router {
    private array $routes = [];

    public function add(string $method, string $path, callable $handler) {
        $this->routes[] = compact('method', 'path', 'handler');
    }

    public function dispatch(string $method, string $uri, string $basePath = '') {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        if ($basePath && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
            if ($path === '') $path = '/';
        }
        foreach ($this->routes as $r) {
            if (strtoupper($method) === strtoupper($r['method']) && $r['path'] === $path) {
                return $r['handler']();
            }
        }
        Response::json(['error' => 'Not Found', 'path' => $path], 404);
    }
}
