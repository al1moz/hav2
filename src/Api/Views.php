<?php
declare(strict_types=1);

namespace Conso\Api;

use Conso\Auth;
use Conso\Db;
use Conso\Ecs;
use Conso\Http;
use Conso\Metrics;
use Conso\Prices;
use Conso\Settings;
use Conso\Time;
use Conso\Weather;
use Conso\Config;

/** Données préparées pour les pages du site (une route par page, lecture seule). */
final class Views
{
    private const TOTAL = 'elec_index';

    /** GET /api/v1/dashboard : page Aujourd'hui. */
    public static function dashboard(): void
    {
        if (!Auth::requireRead()) {
            return;
        }
        $now = time();
        $tz = Config::timezone();
        $midnight = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0)->getTimestamp();
        $yMidnight = (new \DateTimeImmutable('@' . $midnight))->setTimezone($tz)->modify('-1 day')->getTimestamp();

        $total = Metrics::find(self::TOTAL);
        $today = $yesterdaySameTime = $yesterday = null;
        $power = null;
        if ($total !== null) {
            $today = self::energyBetween($total['id'], $midnight, $now);
            $yesterdaySameTime = self::energyBetween($total['id'], $yMidnight, $yMidnight + ($now - $midnight));
            $yesterday = self::energyBetween($total['id'], $yMidnight, $midnight);
            $last = Db::all('SELECT ts, value FROM sample WHERE metric_id = ? ORDER BY ts DESC LIMIT 2', [$total['id']]);
            if (count($last) === 2) {
                $dt = Time::fromDb($last[0]['ts']) - Time::fromDb($last[1]['ts']);
                if ($dt > 0 && $dt <= 900) {
                    $power = [
                        'value' => round(((float) $last[0]['value'] - (float) $last[1]['value']) * $total['energy_factor'] * 3600 / $dt),
                        'unit' => 'W',
                        'ts' => Time::iso(Time::fromDb($last[0]['ts'])),
                    ];
                }
            }
        }

        $latest = [];
        foreach (Db::all('SELECT m.code, m.source, m.unit, l.ts, l.value FROM sample_latest l JOIN metric m ON m.id = l.metric_id') as $r) {
            $latest[$r['code']] = ['value' => (float) $r['value'], 'unit' => $r['unit'], 'ts' => Time::iso(Time::fromDb($r['ts'])), 'source' => $r['source']];
        }
        if (isset($latest['elec_power'])) {
            $power = $latest['elec_power'];
        }

        $staleAfter = Settings::int('stale_after_minutes', 60) * 60;
        $sources = [];
        foreach ($latest as $m) {
            $ts = (int) Time::parse($m['ts']);
            $sources[$m['source']] = max($sources[$m['source']] ?? 0, $ts);
        }
        $sourceList = [];
        foreach ($sources as $name => $ts) {
            $sourceList[] = ['source' => $name, 'last_ts' => Time::iso($ts), 'stale' => $now - $ts > $staleAfter];
        }
        $lastTs = $sources ? max($sources) : null;

        $alertDays = Settings::int('ecs_alert_days', 7);
        $ecsRecent = Ecs::episodes($now - $alertDays * 86400);
        $price = Prices::kwh(Time::localDate($now));

        Http::json(200, [
            'now' => Time::iso($now),
            'site_name' => Settings::get('site_name', 'Maison'),
            'stale_after_s' => $staleAfter,
            'last_ts' => $lastTs === null ? null : Time::iso($lastTs),
            'stale' => $lastTs === null || $now - $lastTs > $staleAfter,
            'sources' => $sourceList,
            'power' => $power,
            'today_kwh' => $today,
            'yesterday_same_time_kwh' => $yesterdaySameTime,
            'yesterday_kwh' => $yesterday,
            'kwh_price' => $price,
            'today_cost_eur' => $today === null ? null : round($today * $price, 2),
            'latest' => $latest,
            'ecs_alert' => ['days' => $alertDays, 'episodes' => $ecsRecent],
        ]);
    }

    /**
     * GET /api/v1/breakdown?from=AAAA-MM-JJ&to=AAAA-MM-JJ&period=day|month|year
     * ou period=hour avec from/to en date ISO (24 dernières heures par défaut).
     * kWh du compteur Linky et de chaque circuit mesuré, « reste » = Linky moins les circuits.
     * Coûts en euros au tarif de chaque jour : cost (Linky), circuits_cost, rest_cost, et subscription,
     * la part de l'abonnement (jours ou heures où le Linky a des données, la journée ou l'heure en cours au prorata).
     */
    public static function breakdown(): void
    {
        if (!Auth::requireRead()) {
            return;
        }
        $now = time();
        $today = Time::localDate($now);
        $todayShare = self::elapsedToday($now);
        $hourly = Http::query('period') === 'hour';
        if ($hourly) {
            // Heure par heure : from/to en date ISO (par défaut les 24 dernières heures), 8 jours au plus.
            $toTs = Http::query('to') === null ? time() : Time::parseBound((string) Http::query('to'), true);
            $fromTs = Http::query('from') === null ? ($toTs === null ? null : $toTs - 86400) : Time::parseBound((string) Http::query('from'), false);
            if ($fromTs === null || $toTs === null || $fromTs >= $toTs || $toTs - $fromTs > 8 * 86400) {
                Http::error(422, 'invalid_range', 'period=hour : from/to en date ISO, 8 jours au plus.');
                return;
            }
            $from = Time::iso($fromTs);
            $to = Time::iso($toTs);
        } else {
            [$from, $to] = self::dayRange(30);
        }
        $length = ['day' => 10, 'month' => 7, 'year' => 4][Http::query('period') ?? 'day'] ?? 10;
        $circuits = array_values(array_filter(Metrics::all(), function (array $m): bool {
            return $m['source'] === 'shelly' && $m['energy_factor'] !== null && $m['visible'];
        }));
        $total = Metrics::find(self::TOTAL);
        $ids = array_map(function (array $m): int {
            return $m['id'];
        }, $circuits);
        if ($total !== null) {
            $ids[] = $total['id'];
        }
        $codeById = [];
        foreach ($circuits as $m) {
            $codeById[$m['id']] = $m['code'];
        }
        $rows = [];
        if ($ids) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            if ($hourly) {
                $data = Db::all(
                    "SELECT metric_id, hour AS p, energy_wh AS wh, n FROM sample_hourly
                      WHERE metric_id IN ($place) AND hour >= ? AND hour < ? ORDER BY hour",
                    array_merge($ids, [Time::toDb(Time::hourStart((int) $fromTs)), Time::toDb((int) $toTs)])
                );
            } else {
                // Jour par jour (chaque jour à son prix), regroupé ensuite par mois ou par année.
                $data = Db::all(
                    "SELECT metric_id, day AS p, energy_wh AS wh, n FROM sample_daily
                      WHERE metric_id IN ($place) AND day BETWEEN ? AND ? ORDER BY day",
                    array_merge($ids, [$from, $to])
                );
            }
            $currentHour = Time::hourStart($now);
            foreach ($data as $r) {
                if ($hourly) {
                    $ts = Time::fromDb($r['p']);
                    $p = Time::iso($ts);
                    $day = Time::localDate($ts);
                    $days = ($ts === $currentHour ? ($now - $ts) / 3600 : 1) / 24;
                } else {
                    $day = $r['p'];
                    $p = substr($day, 0, $length);
                    $days = $day === $today ? $todayShare : 1;
                }
                if (!isset($rows[$p])) {
                    $rows[$p] = ['period' => $p, 'total' => null, 'circuits' => [], 'cost' => null, 'circuits_cost' => [],
                        'subscription' => 0.0, 'n_total' => 0, 'n_circuits' => []];
                }
                $row = &$rows[$p];
                $wh = (float) $r['wh'];
                $eur = $wh / 1000 * Prices::kwh($day);
                if ($total !== null && (int) $r['metric_id'] === $total['id']) {
                    $row['total'] = ($row['total'] ?? 0) + $wh;
                    $row['cost'] = ($row['cost'] ?? 0) + $eur;
                    $row['subscription'] += $days * Prices::subscriptionPerDay($day);
                    $row['n_total'] += (int) $r['n'];
                } else {
                    $code = $codeById[(int) $r['metric_id']];
                    $row['circuits'][$code] = ($row['circuits'][$code] ?? 0) + $wh;
                    $row['circuits_cost'][$code] = ($row['circuits_cost'][$code] ?? 0) + $eur;
                    $row['n_circuits'][$code] = ($row['n_circuits'][$code] ?? 0) + (int) $r['n'];
                }
                unset($row);
            }
        }
        $kwh = function (float $wh): float {
            return round($wh / 1000, 3);
        };
        $euros = function (float $eur): float {
            return round($eur, 4);
        };
        foreach ($rows as &$row) {
            $rest = $row['total'] === null ? null : max(0, $row['total'] - array_sum($row['circuits']));
            $restCost = $row['cost'] === null ? null : max(0, $row['cost'] - array_sum($row['circuits_cost']));
            // Part de la période où les Shelly répondaient (relevés circuits / relevés Linky).
            $nCircuits = $row['n_circuits'] ? max($row['n_circuits']) : 0;
            $row = [
                'period' => $row['period'],
                'total' => $row['total'] === null ? null : $kwh($row['total']),
                'circuits' => array_map($kwh, $row['circuits']),
                'rest' => $rest === null ? null : $kwh($rest),
                'coverage' => $row['n_total'] > 0 ? round(min(1, $nCircuits / $row['n_total']), 2) : null,
                'cost' => $row['cost'] === null ? null : $euros($row['cost']),
                'circuits_cost' => array_map($euros, $row['circuits_cost']),
                'rest_cost' => $restCost === null ? null : $euros($restCost),
                'subscription' => $euros($row['subscription']),
            ];
        }
        unset($row);
        Http::json(200, [
            'from' => $from, 'to' => $to,
            'circuits' => array_map(function (array $m): array {
                return ['code' => $m['code'], 'label' => $m['label']];
            }, $circuits),
            'rows' => array_values($rows),
        ]);
    }

    /**
     * GET /api/v1/cost?period=24h|7|30|12m|y : kWh et coût du compteur Linky sur la période qui se termine
     * maintenant (24 dernières heures, 7 ou 30 derniers jours, 12 derniers mois, année en cours), comme les
     * graphiques de la page Électricité, et sur la même durée juste avant (un an avant pour 12m et y).
     */
    public static function cost(): void
    {
        if (!Auth::requireRead()) {
            return;
        }
        $now = time();
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(Config::timezone());
        $midnight = $local->setTime(0, 0);
        $period = Http::query('period') ?? '30';
        switch ($period) {
            case '24h':
                $start = Time::hourStart($now) - 23 * 3600;
                $previous = [$start - 86400, $now - 86400];
                break;
            case '7':
            case '30':
                $first = $midnight->modify('-' . ((int) $period - 1) . ' days');
                $start = $first->getTimestamp();
                $previous = [$first->modify('-' . $period . ' days')->getTimestamp(), $local->modify('-' . $period . ' days')->getTimestamp()];
                break;
            case '12m':
            case 'y':
                $first = $period === 'y' ? $midnight->setDate((int) $local->format('Y'), 1, 1) : $midnight->modify('first day of this month')->modify('-11 months');
                $start = $first->getTimestamp();
                $previous = [$first->modify('-1 year')->getTimestamp(), $local->modify('-1 year')->getTimestamp()];
                break;
            default:
                Http::error(422, 'invalid_period', 'period : 24h, 7, 30, 12m ou y.');
                return;
        }
        $total = Metrics::find(self::TOTAL);
        [$since, $price, $month] = Prices::at(Time::localDate($now));
        Http::json(200, [
            'period' => $period,
            'current' => self::costBetween($total, $start, $now, $now),
            'previous' => self::costBetween($total, $previous[0], $previous[1], $now),
            'kwh_price' => $price,
            'price_since' => $since === '' ? null : $since,
            'subscription_month' => $month,
        ]);
    }

    /** GET /api/v1/profile?from&to : puissance moyenne (kW) par heure locale de la journée. */
    public static function profile(): void
    {
        if (!Auth::requireRead()) {
            return;
        }
        [$from, $to] = self::dayRange(30);
        $total = Metrics::find(self::TOTAL);
        $sum = array_fill(0, 24, 0.0);
        $count = array_fill(0, 24, 0);
        if ($total !== null) {
            $start = (int) Time::parseBound($from, false);
            $end = (int) Time::parseBound($to, true);
            $tz = Config::timezone();
            foreach (Db::all('SELECT hour, energy_wh FROM sample_hourly WHERE metric_id = ? AND hour >= ? AND hour < ? AND energy_wh > 0',
                [$total['id'], Time::toDb($start), Time::toDb($end)]) as $r) {
                $h = (int) (new \DateTimeImmutable('@' . Time::fromDb($r['hour'])))->setTimezone($tz)->format('G');
                $sum[$h] += (float) $r['energy_wh'];
                $count[$h]++;
            }
        }
        $kw = [];
        for ($h = 0; $h < 24; $h++) {
            $kw[] = $count[$h] ? round($sum[$h] / $count[$h] / 1000, 3) : null;
        }
        Http::json(200, ['from' => $from, 'to' => $to, 'kw' => $kw]);
    }

    /** GET /api/v1/ecs : activations de la résistance d'appoint ECS. */
    public static function ecs(): void
    {
        if (!Auth::requireRead()) {
            return;
        }
        $episodes = Ecs::episodes();
        $byYear = [];
        $kwh = 0.0;
        foreach ($episodes as $e) {
            $y = substr(Time::localDate((int) Time::parse($e['start'])), 0, 4);
            $byYear[$y] = ($byYear[$y] ?? 0) + 1;
            $kwh += $e['kwh'];
        }
        ksort($byYear);
        $first = Db::one('SELECT MIN(day) AS d FROM sample_daily WHERE metric_id = (SELECT id FROM metric WHERE code = ?)', [Ecs::METRIC]);
        Http::json(200, [
            'threshold_w' => Settings::int('ecs_threshold_w', 500),
            'since' => $first['d'] ?? null,
            'count' => count($episodes),
            'kwh' => round($kwh, 2),
            'cost_eur' => round($kwh * Prices::kwh(Time::localDate(time())), 2),
            'by_year' => $byYear,
            'episodes' => $episodes,
        ]);
    }

    /**
     * GET /api/v1/weather : page Météo (vent observé autour de l'aéroclub, prévisions d'aujourd'hui et de demain,
     * METAR et TAF). Peut appeler Météo Concept quand le cache est périmé (src/Weather.php).
     */
    public static function weather(): void
    {
        if (!Auth::requireRead()) {
            return;
        }
        Http::json(200, Weather::view(time()));
    }

    /**
     * GET /api/v1/compare : comparaisons entre années.
     * - kWh et température extérieure moyenne par mois ;
     * - un point par jour complet (température moyenne, kWh) pour la corrélation ;
     * - degrés-jours unifiés et kWh par DJU, par saison de chauffe (octobre à avril).
     */
    public static function compare(): void
    {
        if (!Auth::requireRead()) {
            return;
        }
        $total = Metrics::find(self::TOTAL);
        $outdoor = Metrics::find('temp_outdoor');
        $months = [];
        $days = [];
        if ($total !== null) {
            foreach (Db::all('SELECT LEFT(day, 7) AS m, SUM(energy_wh) AS wh, COUNT(*) AS d FROM sample_daily WHERE metric_id = ? GROUP BY m ORDER BY m', [$total['id']]) as $r) {
                $months[$r['m']]['kwh'] = round((float) $r['wh'] / 1000, 1);
                $months[$r['m']]['days'] = (int) $r['d'];
            }
        }
        if ($outdoor !== null) {
            foreach (Db::all('SELECT LEFT(day, 7) AS m, SUM(v_sum) / SUM(n) AS t FROM sample_daily WHERE metric_id = ? AND n > 0 GROUP BY m ORDER BY m', [$outdoor['id']]) as $r) {
                $months[$r['m']]['temp'] = round((float) $r['t'], 1);
            }
        }
        if ($total !== null && $outdoor !== null) {
            // Jour complet : au moins 250 relevés d'index sur 288 et 200 relevés de température.
            $rows = Db::all(
                'SELECT e.day, e.energy_wh, t.v_sum / t.n AS t
                   FROM sample_daily e JOIN sample_daily t ON t.day = e.day AND t.metric_id = ?
                  WHERE e.metric_id = ? AND e.n >= 250 AND t.n >= 200 AND e.energy_wh > 0
                  ORDER BY e.day',
                [$outdoor['id'], $total['id']]
            );
            foreach ($rows as $r) {
                $days[] = [$r['day'], round((float) $r['t'], 2), round((float) $r['energy_wh'] / 1000, 2)];
            }
        }
        $base = (float) Settings::get('heating_base_temp', '18');
        $seasons = [];
        foreach ($days as [$day, $t, $kwh]) {
            $y = (int) substr($day, 0, 4);
            $m = (int) substr($day, 5, 2);
            if ($m >= 5 && $m <= 9) {
                continue;
            }
            $key = $m >= 10 ? $y . '-' . ($y + 1) : ($y - 1) . '-' . $y;
            if (!isset($seasons[$key])) {
                $seasons[$key] = ['season' => $key, 'days' => 0, 'dju' => 0.0, 'kwh' => 0.0];
            }
            $seasons[$key]['days']++;
            $seasons[$key]['dju'] += max(0, $base - $t);
            $seasons[$key]['kwh'] += $kwh;
        }
        foreach ($seasons as &$s) {
            $s['kwh_per_dju'] = $s['dju'] > 0 ? round($s['kwh'] / $s['dju'], 2) : null;
            $s['dju'] = round($s['dju']);
            $s['kwh'] = round($s['kwh']);
        }
        unset($s);
        ksort($months);
        $monthList = [];
        foreach ($months as $m => $v) {
            $monthList[] = ['month' => $m, 'kwh' => $v['kwh'] ?? null, 'days' => $v['days'] ?? 0, 'temp' => $v['temp'] ?? null];
        }
        Http::json(200, [
            'base_temp' => $base,
            'months' => $monthList,
            'days' => $days,
            'seasons' => array_values($seasons),
        ]);
    }

    private static function energyBetween(int $metricId, int $from, int $to): ?float
    {
        // Heures entières, plus la fraction écoulée de l'heure entamée.
        $lastHour = Time::hourStart($to);
        $full = Db::one(
            'SELECT SUM(energy_wh) AS wh, COUNT(*) AS c FROM sample_hourly WHERE metric_id = ? AND hour >= ? AND hour < ?',
            [$metricId, Time::toDb($from), Time::toDb($lastHour)]
        );
        $partial = Db::one('SELECT energy_wh FROM sample_hourly WHERE metric_id = ? AND hour = ?', [$metricId, Time::toDb($lastHour)]);
        if ((int) $full['c'] === 0 && $partial === null) {
            return null;
        }
        $wh = (float) $full['wh'];
        if ($partial !== null && $lastHour >= $from) {
            // L'heure en cours s'arrête déjà au dernier relevé : elle compte entière.
            $wh += (float) $partial['energy_wh'] * ($lastHour + 3600 > time() ? 1 : min(1, ($to - $lastHour) / 3600));
        }
        return round($wh / 1000, 2);
    }

    /**
     * kWh, coût de l'énergie et part de l'abonnement entre deux instants, d'après les agrégats horaires.
     * Une heure entamée compte au prorata, sauf l'heure en cours : son énergie s'arrête déjà au dernier relevé.
     * hours : heures où le Linky a des données (pour les moyennes).
     * @param array<string,mixed>|null $metric
     * @return array<string,mixed>
     */
    private static function costBetween(?array $metric, int $from, int $to, int $now): array
    {
        $wh = $eur = $subscription = $hours = 0.0;
        $rows = $metric === null ? [] : Db::all(
            'SELECT hour, energy_wh FROM sample_hourly WHERE metric_id = ? AND hour >= ? AND hour < ?',
            [$metric['id'], Time::toDb(Time::hourStart($from)), Time::toDb($to)]
        );
        foreach ($rows as $r) {
            $h = Time::fromDb($r['hour']);
            $share = (min($to, $h + 3600) - max($from, $h)) / 3600;
            if ($share <= 0) {
                continue;
            }
            $day = Time::localDate($h);
            $e = (float) $r['energy_wh'] * ($h + 3600 > $now ? 1 : $share);
            $wh += $e;
            $eur += $e / 1000 * Prices::kwh($day);
            $subscription += $share / 24 * Prices::subscriptionPerDay($day);
            $hours += $share;
        }
        $found = $hours > 0;
        return [
            'from' => Time::iso($from),
            'to' => Time::iso($to),
            'kwh' => $found ? round($wh / 1000, 2) : null,
            'energy_eur' => $found ? round($eur, 2) : null,
            'subscription_eur' => round($subscription, 2),
            'eur' => $found ? round($eur + $subscription, 2) : null,
            'kwh_price' => $wh > 0 ? round($eur / $wh * 1000, 5) : null,
            'hours' => round($hours, 2),
        ];
    }

    /** Part écoulée de la journée locale en cours (0 à 1), journées de 23 ou 25 h comprises. */
    private static function elapsedToday(int $now): float
    {
        $midnight = (new \DateTimeImmutable('@' . $now))->setTimezone(Config::timezone())->setTime(0, 0);
        $length = $midnight->modify('+1 day')->getTimestamp() - $midnight->getTimestamp();
        return ($now - $midnight->getTimestamp()) / $length;
    }

    /** @return array{0:string,1:string} dates locales incluses */
    private static function dayRange(int $defaultDays): array
    {
        $to = Http::query('to');
        $from = Http::query('from');
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        $to = $to !== null && preg_match($re, $to) ? $to : Time::localDate(time());
        $from = $from !== null && preg_match($re, $from) ? $from
            : (new \DateTimeImmutable($to))->modify('-' . ($defaultDays - 1) . ' days')->format('Y-m-d');
        return [$from, $to];
    }
}
