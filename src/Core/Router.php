<?php
namespace App\Core;
class Router {
    private array $routes = [];
    public function get(string $path, array $handler): void { $this->routes['GET'][$path] = $handler; }
    public function post(string $path, array $handler): void { $this->routes['POST'][$path] = $handler; }
    public function dispatch(string $method, string $path, array $config): void {
        $handler = $this->routes[$method][$path] ?? null;
        if (!$handler) { http_response_code(404); echo 'Ruta no encontrada'; return; }
        [$class, $action] = $handler;
        $controller = new $class($config);
        $controller->$action();
    }
}
