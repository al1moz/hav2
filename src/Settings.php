<?php
declare(strict_types=1);

namespace Conso;

/** Réglages modifiables dans l'administration (table setting). */
final class Settings
{
    /** @var array<string,string>|null */
    private static $cache;

    public static function get(string $name, string $default = ''): string
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::all('SELECT name, value FROM setting') as $row) {
                self::$cache[$row['name']] = (string) $row['value'];
            }
        }
        return self::$cache[$name] ?? $default;
    }

    public static function int(string $name, int $default): int
    {
        $value = self::get($name, (string) $default);
        return ctype_digit($value) ? (int) $value : $default;
    }
}
