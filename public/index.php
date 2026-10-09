<?php
// Point d'entrée unique : API et pages.
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Conso\Api;
use Conso\Config;
use Conso\Http;
use Conso\Pages;
use Conso\Router;
use Conso\Tablet;

set_exception_handler(function (\Throwable $e): void {
    error_log((string) $e);
    if (!headers_sent()) {
        Http::error(500, 'server_error', Config::debug() ? $e->getMessage() : 'Erreur interne.');
    }
});

$router = new Router();
$router->add('GET', '/api/v1/health', [Api\Health::class, 'get']);
$router->add('POST', '/api/v1/measurements', [Api\Measurements::class, 'post']);
$router->add('GET', '/api/v1/metrics', [Api\Read::class, 'metrics']);
$router->add('GET', '/api/v1/latest', [Api\Read::class, 'latest']);
$router->add('GET', '/api/v1/series', [Api\Read::class, 'series']);
$router->add('GET', '/api/v1/summary', [Api\Read::class, 'summary']);

$router->add('GET', '/api/v1/dashboard', [Api\Views::class, 'dashboard']);
$router->add('GET', '/api/v1/breakdown', [Api\Views::class, 'breakdown']);
$router->add('GET', '/api/v1/profile', [Api\Views::class, 'profile']);
$router->add('GET', '/api/v1/ecs', [Api\Views::class, 'ecs']);
$router->add('GET', '/api/v1/compare', [Api\Views::class, 'compare']);

// Pages du site (connexion obligatoire)
$router->add('GET', '/connexion', [Pages::class, 'login']);
$router->add('POST', '/connexion', [Pages::class, 'login']);
$router->add('POST', '/deconnexion', [Pages::class, 'logout']);
$router->add('GET', '/', [Pages::class, 'home']);
$router->add('GET', '/electricite', [Pages::class, 'electricity']);
$router->add('GET', '/chauffage', [Pages::class, 'heating']);
$router->add('GET', '/temperatures', [Pages::class, 'temperatures']);
$router->add('GET', '/humidite', [Pages::class, 'humidity']);
$router->add('GET', '/comparer', [Pages::class, 'compare']);
$router->add('GET', '/admin', [Pages::class, 'admin']);

// Page tablette (session du site ou jeton « tablet »)
foreach (Tablet::paths() as $path) {
    $router->add('GET', $path, [Tablet::class, 'page']);
}
$router->add('POST', '/admin', [Pages::class, 'admin']);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
