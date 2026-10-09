<?php
declare(strict_types=1);

namespace Conso;

/** Routeur minimal : méthode + chemin exact -> fonction. */
final class Router
{
    /** @var array<string,array<string,callable>> */
    private $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[$path][$method] = $handler;
    }

    public function dispatch(string $method, string $path): void
    {
        $path = '/' . trim($path, '/');
        if (!isset($this->routes[$path])) {
            $this->notFound($path);
            return;
        }
        $handlers = $this->routes[$path];
        if ($method === 'HEAD' && !isset($handlers['HEAD']) && isset($handlers['GET'])) {
            $method = 'GET';
        }
        if (!isset($handlers[$method])) {
            header('Allow: ' . implode(', ', array_keys($handlers)));
            Http::error(405, 'method_not_allowed', 'Méthode non autorisée.');
            return;
        }
        $handlers[$method]();
    }

    private function notFound(string $path): void
    {
        if (strncmp($path, '/api/', 5) === 0) {
            Http::error(404, 'not_found', 'Route inconnue.');
            return;
        }
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Page introuvable</title><p>Page introuvable.</p>';
    }
}
