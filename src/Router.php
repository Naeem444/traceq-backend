<?php
// src/Router.php
class Router {
    private $routes = [];

    public function add($method, $path, $handler) {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler
        ];
    }

    public function dispatch($method, $uri, $basePath = '') {
        // Remove base path and query string
        $path = parse_url($uri, PHP_URL_PATH);
        if ($basePath) {
            $path = str_replace($basePath, '', $path);
        }
        $path = '/' . ltrim($path, '/');

        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            // Check for exact match first
            if ($route['path'] === $path) {
                call_user_func($route['handler']);
                return;
            }

            // Check for dynamic routes with parameters
            $pattern = preg_replace('/\{([^}]+)\}/', '([^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $path, $matches)) {
                array_shift($matches); 
                
                // Extract parameter names
                preg_match_all('/\{([^}]+)\}/', $route['path'], $paramNames);
                $params = [];
                
                if (isset($paramNames[1])) {
                    foreach ($paramNames[1] as $index => $name) {
                        $params[$name] = $matches[$index] ?? null;
                    }
                }

                call_user_func($route['handler'], $params);
                return;
            }
        }

        // Route not found
        http_response_code(404);
        echo json_encode(['error' => 'Route not found']);
    }
}