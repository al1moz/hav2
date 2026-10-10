<?php
declare(strict_types=1);

namespace Conso\Api;

use Conso\Auth;
use Conso\Db;
use Conso\Http;
use Conso\Metrics;
use Conso\Settings;
use Conso\Time;

/** Routes de lecture utilisées par les pages, la tablette, le chat et le connecteur. */
final class Read
{
    private const RAW_LIMIT = 20000;

    /** GET /api/v1/metrics */
    public static function metrics(): void
    {
        if (!Auth::requireRead()) {
            return;
        }
        $out = [];
        foreach (Metrics::all() as $m) {
            $out[] = [
                'code' => $m['code'], 'source' => $m['source'], 'label' => $m['label'], 'unit' => $m['unit'],
                'kind' => $m['kind'], 'has_energy' => $m['energy_factor'] !== null, 'visible' => $m['visible'],
            ];
        }
        Http::json(200, ['metrics' => $out]);
    }

    /** GET /api/v1/latest : dernière valeur de chaque mesure et état de chaque source. */
    public static function latest(): void
    {
        if (!Auth::requireRead()) {
            return;
        }
        $now = time();
        $staleAfter = Settings::int('stale_after_minutes', 60) * 60;
        $rows = Db::all(
            'SELECT m.code, m.source, m.label, m.unit, l.ts, l.value, l.received_at
               FROM sample_latest l JOIN metric m ON m.id = l.metric_id
              ORDER BY m.sort, m.code'
        );
        $metrics = [];
        $sources = [];
        foreach ($rows as $r) {
            $ts = Time::fromDb($r['ts']);
            $metrics[] = [
                'code' => $r['code'], 'label' => $r['label'], 'unit' => $r['unit'],
                'value' => (float) $r['value'], 'ts' => Time::iso($ts), 'age_s' => $now - $ts,
            ];
            $sources[$r['source']] = max($sources[$r['source']] ?? 0, $ts);
        }
        $sourceList = [];
        foreach ($sources as $source => $ts) {
            $sourceList[] = ['source' => $source, 'last_ts' => Time::iso($ts), 'age_s' => $now - $ts, 'stale' => $now - $ts > $staleAfter];
        }
        $last = $sources ? max($sources) : null;
        Http::json(200, [
            'now' => Time::iso($now),
            'stale_after_s' => $staleAfter,
            'last_ts' => $last === null ? null : Time::iso($last),
            'stale' => $last === null || $now - $last > $staleAfter,
            'sources' => $sourceList,
            'metrics' => $metrics,
        ]);
    }

    /**
     * GET /api/v1/series?metric=code&from=…&to=…&step=raw|hour|day
     * from/to : AAAA-MM-JJ (journée locale) ou date ISO. step par défaut selon la durée.
     * nonzero=1 : ignore les valeurs à 0 (consignes de la PAC, à 0 quand le chauffage est arrêté).
     */
    public static function series(): void
    {
        if (!Auth::requireRead()) {
            return;
        }
        $metric = self::metricParam();
        $range = $metric === null ? null : self::rangeParams(86400);
        if ($range === null) {
            return;
        }
        [$from, $to] = $range;
        $span = $to - $from;
        $step = Http::query('step') ?? ($span <= 2 * 86400 ? 'raw' : ($span <= 62 * 86400 ? 'hour' : 'day'));
        $id = $metric['id'];
        $nonzero = Http::query('nonzero') === '1';

        if ($step === 'raw') {
            $rows = Db::all(
                'SELECT ts, value FROM sample WHERE metric_id = ? AND ts >= ? AND ts < ?' . ($nonzero ? ' AND value <> 0' : '')
                . ' ORDER BY ts LIMIT ' . (self::RAW_LIMIT + 1),
                [$id, Time::toDb($from), Time::toDb($to)]
            );
            if (count($rows) > self::RAW_LIMIT) {
                Http::error(422, 'too_many_points', 'Période trop longue pour step=raw, utiliser hour ou day.');
                return;
            }
            $points = array_map(function (array $r): array {
                return [Time::iso(Time::fromDb($r['ts'])), (float) $r['value']];
            }, $rows);
            Http::json(200, self::seriesHeader($metric, $step, $from, $to) + ['columns' => ['ts', 'value'], 'points' => $points]);
            return;
        }
        if ($nonzero && ($step === 'hour' || $step === 'day')) {
            $fromDay = Time::localDate($from);
            $start = $step === 'hour' ? Time::hourStart($from) : (int) Time::parseBound($fromDay, false);
            $rows = self::nonzeroRows($id, $start, $to, $step === 'day');
            $key = function (string $b) use ($step): string {
                return $step === 'hour' ? Time::iso((int) $b) : $b;
            };
        } elseif ($step === 'hour') {
            $rows = Db::all(
                'SELECT hour AS b, n, v_sum, v_min, v_max, energy_wh FROM sample_hourly
                  WHERE metric_id = ? AND hour >= ? AND hour < ? ORDER BY hour',
                [$id, Time::toDb(Time::hourStart($from)), Time::toDb($to)]
            );
            $key = function (string $b): string {
                return Time::iso(Time::fromDb($b));
            };
        } elseif ($step === 'day') {
            $rows = Db::all(
                'SELECT day AS b, n, v_sum, v_min, v_max, energy_wh FROM sample_daily
                  WHERE metric_id = ? AND day BETWEEN ? AND ? ORDER BY day',
                [$id, Time::localDate($from), Time::localDate($to - 1)]
            );
            $key = function (string $b): string {
                return $b;
            };
        } else {
            Http::error(422, 'invalid_step', 'step : raw, hour ou day.');
            return;
        }
        $points = [];
        foreach ($rows as $r) {
            $n = (int) $r['n'];
            $points[] = [
                $key($r['b']),
                $n > 0 ? round((float) $r['v_sum'] / $n, 3) : null,
                $r['v_min'] === null ? null : (float) $r['v_min'],
                $r['v_max'] === null ? null : (float) $r['v_max'],
                round((float) $r['energy_wh'], 1),
            ];
        }
        Http::json(200, self::seriesHeader($metric, $step, $from, $to) + ['columns' => ['ts', 'avg', 'min', 'max', 'energy_wh'], 'points' => $points]);
    }

    /**
     * GET /api/v1/summary?metric=code&period=day|month|year&from=AAAA-MM-JJ&to=AAAA-MM-JJ
     * Totaux par jour, mois ou année (journées locales), avec le coût au prix de l'époque.
     */
    public static function summary(): void
    {
        if (!Auth::requireRead()) {
            return;
        }
        $metric = self::metricParam();
        $range = $metric === null ? null : self::rangeParams(366 * 86400);
        if ($range === null) {
            return;
        }
        [$from, $to] = $range;
        $period = Http::query('period') ?? 'month';
        $length = ['day' => 10, 'month' => 7, 'year' => 4][$period] ?? null;
        if ($length === null) {
            Http::error(422, 'invalid_period', 'period : day, month ou year.');
            return;
        }
        $fromDay = Time::localDate($from);
        $toDay = Time::localDate($to - 1);
        if (Http::query('nonzero') === '1') {
            $rows = [];
            foreach (self::nonzeroRows($metric['id'], (int) Time::parseBound($fromDay, false), $to, true) as $r) {
                $rows[] = ['day' => $r['b']] + $r;
            }
        } else {
            $rows = Db::all(
                'SELECT day, n, v_sum, v_min, v_max, energy_wh FROM sample_daily
                  WHERE metric_id = ? AND day BETWEEN ? AND ? ORDER BY day',
                [$metric['id'], $fromDay, $toDay]
            );
        }
        $prices = Db::all('SELECT valid_from, kwh_price FROM price ORDER BY valid_from');
        $hasEnergy = $metric['energy_factor'] !== null;
        $groups = [];
        foreach ($rows as $r) {
            $k = substr($r['day'], 0, $length);
            if (!isset($groups[$k])) {
                $groups[$k] = ['period' => $k, 'days' => 0, 'n' => 0, 'sum' => 0.0, 'min' => null, 'max' => null, 'wh' => 0.0, 'cost' => 0.0];
            }
            $g = &$groups[$k];
            $g['days']++;
            $g['n'] += (int) $r['n'];
            $g['sum'] += (float) $r['v_sum'];
            if ($r['v_min'] !== null) {
                $g['min'] = $g['min'] === null ? (float) $r['v_min'] : min($g['min'], (float) $r['v_min']);
            }
            if ($r['v_max'] !== null) {
                $g['max'] = $g['max'] === null ? (float) $r['v_max'] : max($g['max'], (float) $r['v_max']);
            }
            $g['wh'] += (float) $r['energy_wh'];
            $g['cost'] += (float) $r['energy_wh'] / 1000 * self::priceAt($prices, $r['day']);
            unset($g);
        }
        $out = [];
        foreach ($groups as $g) {
            $row = [
                'period' => $g['period'],
                'days' => $g['days'],
                'n' => $g['n'],
                'avg' => $g['n'] > 0 ? round($g['sum'] / $g['n'], 3) : null,
                'min' => $g['min'],
                'max' => $g['max'],
            ];
            if ($hasEnergy) {
                $row['energy_kwh'] = round($g['wh'] / 1000, 3);
                $row['cost_eur'] = round($g['cost'], 2);
            }
            $out[] = $row;
        }
        Http::json(200, [
            'metric' => $metric['code'], 'unit' => $metric['unit'], 'period' => $period,
            'from' => $fromDay, 'to' => $toDay, 'rows' => $out,
        ]);
    }

    /**
     * Agrégats par heure UTC (b = horodatage) ou par journée locale (b = AAAA-MM-JJ), calculés
     * sur les mesures brutes en ignorant les valeurs à 0. Pas d'énergie (grandeurs seulement).
     * @return array<int,array{b:string,n:int,v_sum:float,v_min:float,v_max:float,energy_wh:float}>
     */
    private static function nonzeroRows(int $id, int $from, int $to, bool $daily): array
    {
        $hours = Db::all(
            "SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:00:00') AS h, COUNT(*) AS n, SUM(value) AS s, MIN(value) AS lo, MAX(value) AS hi
               FROM sample WHERE metric_id = ? AND ts >= ? AND ts < ? AND value <> 0 GROUP BY h ORDER BY h",
            [$id, Time::toDb($from), Time::toDb($to)]
        );
        $out = [];
        foreach ($hours as $r) {
            $ts = Time::fromDb($r['h']);
            $b = $daily ? Time::localDate($ts) : (string) $ts;
            if (!isset($out[$b])) {
                $out[$b] = ['b' => $b, 'n' => 0, 'v_sum' => 0.0, 'v_min' => (float) $r['lo'], 'v_max' => (float) $r['hi'], 'energy_wh' => 0.0];
            }
            $out[$b]['n'] += (int) $r['n'];
            $out[$b]['v_sum'] += (float) $r['s'];
            $out[$b]['v_min'] = min($out[$b]['v_min'], (float) $r['lo']);
            $out[$b]['v_max'] = max($out[$b]['v_max'], (float) $r['hi']);
        }
        return array_values($out);
    }

    /** @param array<int,array<string,mixed>> $prices */
    private static function priceAt(array $prices, string $day): float
    {
        $price = 0.0;
        foreach ($prices as $p) {
            if ($p['valid_from'] > $day) {
                break;
            }
            $price = (float) $p['kwh_price'];
        }
        return $price;
    }

    /** @return array<string,mixed>|null */
    private static function metricParam(): ?array
    {
        $code = Http::query('metric');
        $metric = $code === null ? null : Metrics::find($code);
        if ($metric === null) {
            Http::error(422, 'invalid_metric', 'metric : code de mesure inconnu (voir /api/v1/metrics).');
        }
        return $metric;
    }

    /** @return array{0:int,1:int}|null */
    private static function rangeParams(int $defaultSpan): ?array
    {
        $now = time();
        $to = Http::query('to');
        $from = Http::query('from');
        $toTs = $to === null ? $now : Time::parseBound($to, true);
        $fromTs = $from === null ? ($toTs === null ? null : $toTs - $defaultSpan) : Time::parseBound($from, false);
        if ($fromTs === null || $toTs === null || $fromTs >= $toTs) {
            Http::error(422, 'invalid_range', 'from/to : AAAA-MM-JJ ou date ISO avec fuseau, from avant to.');
            return null;
        }
        return [$fromTs, $toTs];
    }

    /** @param array<string,mixed> $metric @return array<string,mixed> */
    private static function seriesHeader(array $metric, string $step, int $from, int $to): array
    {
        return ['metric' => $metric['code'], 'unit' => $metric['unit'], 'step' => $step, 'from' => Time::iso($from), 'to' => Time::iso($to)];
    }
}
