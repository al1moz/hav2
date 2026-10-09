<?php
// Chargement commun au site (public/index.php) et aux scripts (bin/*.php).
declare(strict_types=1);

define('APP_ROOT', __DIR__);

spl_autoload_register(function (string $class): void {
    $prefix = 'Conso\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Conso\Config::load(APP_ROOT . '/.env');
date_default_timezone_set('UTC');
