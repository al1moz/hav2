<?php
declare(strict_types=1);

namespace Conso;

/** Tarif en vigueur à une date locale (table price) : prix du kWh et abonnement mensuel, TTC. */
final class Prices
{
    /** @var array<int,array{0:string,1:float,2:float}>|null [début, € par kWh, € par mois], du plus ancien au plus récent */
    private static $rows;
    /** @var array<string,array{0:string,1:float,2:float}> */
    private static $byDay = [];

    /** Prix du kWh (€) le jour donné (AAAA-MM-JJ). */
    public static function kwh(string $day): float
    {
        return self::at($day)[1];
    }

    /** Abonnement le jour donné, en € par jour (montant mensuel × 12 / 365), 0 s'il n'est pas saisi. */
    public static function subscriptionPerDay(string $day): float
    {
        return self::at($day)[2] * 12 / 365;
    }

    /** @return array{0:string,1:float,2:float} tarif en vigueur (début, € par kWh, € par mois), des zéros avant le premier */
    public static function at(string $day): array
    {
        if (isset(self::$byDay[$day])) {
            return self::$byDay[$day];
        }
        if (self::$rows === null) {
            self::$rows = [];
            foreach (Db::all('SELECT valid_from, kwh_price, subscription_month FROM price ORDER BY valid_from') as $r) {
                self::$rows[] = [(string) $r['valid_from'], (float) $r['kwh_price'], (float) ($r['subscription_month'] ?? 0)];
            }
        }
        $found = ['', 0.0, 0.0];
        foreach (self::$rows as $r) {
            if ($r[0] > $day) {
                break;
            }
            $found = $r;
        }
        return self::$byDay[$day] = $found;
    }
}
