<?php
declare(strict_types=1);

namespace Conso;

/** Accès à la table metric, avec création automatique d'une mesure inconnue à la réception. */
final class Metrics
{
    /** @var array<string,array<string,mixed>> */
    private static $byCode = [];

    /** @return array<string,mixed>|null */
    public static function find(string $code): ?array
    {
        if (!isset(self::$byCode[$code])) {
            $row = Db::one('SELECT * FROM metric WHERE code = ?', [$code]);
            if ($row === null) {
                return null;
            }
            self::$byCode[$code] = self::normalize($row);
        }
        return self::$byCode[$code];
    }

    /** @return array<string,mixed> */
    public static function findOrCreate(string $code, string $source, string $unit, string $kind): array
    {
        $metric = self::find($code);
        if ($metric !== null) {
            return $metric;
        }
        Db::run(
            'INSERT IGNORE INTO metric (code, source, label, unit, kind, energy_factor, sort, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 1000, UTC_TIMESTAMP())',
            [$code, $source, $code, $unit, $kind, Units::energyFactor($kind, $unit)]
        );
        return self::find($code);
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return array_map([self::class, 'normalize'], Db::all('SELECT * FROM metric ORDER BY sort, code'));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['energy_factor'] = $row['energy_factor'] === null ? null : (float) $row['energy_factor'];
        $row['visible'] = (bool) $row['visible'];
        return $row;
    }
}
