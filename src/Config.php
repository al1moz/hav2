<?php
declare(strict_types=1);

namespace Conso;

/**
 * Lit la configuration dans les variables d'environnement, avec un fichier .env
 * facultatif (hors dépôt). Une variable déjà définie dans l'environnement
 * n'est jamais écrasée par le fichier.
 */
final class Config
{
    /** @var array<string,string> */
    private static $values = [];

    public static function load(string $envFile): void
    {
        if (!is_file($envFile)) {
            return;
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return self::$values[$key] ?? $default;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Variable de configuration manquante : $key");
        }
        return $value;
    }

    public static function debug(): bool
    {
        return self::get('APP_DEBUG', '0') === '1';
    }

    public static function timezone(): \DateTimeZone
    {
        static $tz = null;
        if ($tz === null) {
            $tz = new \DateTimeZone(self::get('APP_TIMEZONE', 'Europe/Paris'));
        }
        return $tz;
    }
}
