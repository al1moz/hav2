<?php
declare(strict_types=1);

namespace Conso;

/** Conversions de dates. En interne : secondes Unix (UTC). En base : DATETIME UTC. */
final class Time
{
    /** ISO 8601 avec fuseau (Z ou +02:00) ou nombre de secondes Unix. Null si illisible. @param mixed $value */
    public static function parse($value): ?int
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return (int) $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+\-]\d{2}:?\d{2})$/', $value)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }

    /** Date locale « AAAA-MM-JJ » ou date-heure ISO, pour les paramètres from/to des routes de lecture. */
    public static function parseBound(string $value, bool $endOfDay): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, Config::timezone());
            if ($d === false) {
                return null;
            }
            return $endOfDay ? $d->modify('+1 day')->getTimestamp() : $d->getTimestamp();
        }
        return self::parse($value);
    }

    public static function toDb(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    public static function fromDb(string $value): int
    {
        return (int) strtotime($value . ' UTC');
    }

    public static function iso(int $ts): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    public static function hourStart(int $ts): int
    {
        return $ts - ($ts % 3600);
    }

    /** Date locale (APP_TIMEZONE) d'un instant. */
    public static function localDate(int $ts): string
    {
        // Les décalages horaires sont des heures entières : une heure UTC tombe dans une seule date locale.
        static $cache = [];
        $hour = self::hourStart($ts);
        if (!isset($cache[$hour])) {
            if (count($cache) > 10000) {
                $cache = [];
            }
            $cache[$hour] = (new \DateTimeImmutable('@' . $hour))->setTimezone(Config::timezone())->format('Y-m-d');
        }
        return $cache[$hour];
    }
}
