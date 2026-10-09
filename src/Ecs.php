<?php
declare(strict_types=1);

namespace Conso;

/**
 * Activations de la résistance d'appoint du ballon ECS : périodes où le Shelly
 * mesure au moins ecs_threshold_w (au repos il indique environ 4 W).
 * Deux relevés au-dessus du seuil séparés de 30 min au plus font une seule activation.
 */
final class Ecs
{
    public const METRIC = 'circuit_appoint_ecs';
    private const MERGE_GAP = 1800;
    private const STEP = 300;

    /** @return array<int,array<string,mixed>> les activations, de la plus récente à la plus ancienne */
    public static function episodes(?int $since = null): array
    {
        $metric = Metrics::find(self::METRIC);
        if ($metric === null) {
            return [];
        }
        $threshold = (float) Settings::int('ecs_threshold_w', 500);
        $rows = Db::all(
            'SELECT ts, value FROM sample WHERE metric_id = ? AND value >= ? AND ts >= ? ORDER BY ts',
            [$metric['id'], $threshold, Time::toDb($since ?? 0)]
        );
        $episodes = [];
        $cur = null;
        foreach ($rows as $r) {
            $ts = Time::fromDb($r['ts']);
            $w = (float) $r['value'];
            if ($cur !== null && $ts - $cur['end'] <= self::MERGE_GAP) {
                $cur['end'] = $ts;
                $cur['wh'] += $w * self::STEP / 3600;
                $cur['max_w'] = max($cur['max_w'], $w);
                continue;
            }
            if ($cur !== null) {
                $episodes[] = $cur;
            }
            $cur = ['start' => $ts - self::STEP, 'end' => $ts, 'wh' => $w * self::STEP / 3600, 'max_w' => $w];
        }
        if ($cur !== null) {
            $episodes[] = $cur;
        }

        $outdoor = Metrics::find('temp_outdoor');
        $out = [];
        foreach (array_reverse($episodes) as $e) {
            $temp = null;
            if ($outdoor !== null) {
                $t = Db::one(
                    'SELECT AVG(value) AS t FROM sample WHERE metric_id = ? AND ts BETWEEN ? AND ?',
                    [$outdoor['id'], Time::toDb($e['start'] - 900), Time::toDb($e['end'] + 900)]
                );
                $temp = $t['t'] === null ? null : round((float) $t['t'], 1);
            }
            $out[] = [
                'start' => Time::iso($e['start']),
                'end' => Time::iso($e['end']),
                'minutes' => (int) round(($e['end'] - $e['start']) / 60),
                'kwh' => round($e['wh'] / 1000, 2),
                'max_w' => (int) round($e['max_w']),
                'outdoor_c' => $temp,
            ];
        }
        return $out;
    }
}
