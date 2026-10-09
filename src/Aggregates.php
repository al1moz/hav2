<?php
declare(strict_types=1);

namespace Conso;

/**
 * Accumule des contributions aux agrégats horaires et journaliers, puis les écrit
 * en quelques requêtes. Les écritures ADDITIONNENT aux lignes existantes
 * (INSERT ... ON DUPLICATE KEY UPDATE), on peut donc vider le tampon à tout moment.
 */
final class Aggregates
{
    /** @var array<string,array{0:int,1:string,2:int,3:float,4:?float,5:?float,6:float}> */
    private $hourly = [];
    /** @var array<string,array{0:int,1:string,2:int,3:float,4:?float,5:?float,6:float}> */
    private $daily = [];

    public function addValue(int $metricId, int $ts, float $value): void
    {
        $this->merge($this->hourly, $metricId, Time::toDb(Time::hourStart($ts)), 1, $value, $value, $value, 0.0);
        $this->merge($this->daily, $metricId, Time::localDate($ts), 1, $value, $value, $value, 0.0);
    }

    /** @param array<int,float> $byHour début d'heure UTC => Wh */
    public function addEnergy(int $metricId, array $byHour): void
    {
        foreach ($byHour as $hour => $wh) {
            $this->merge($this->hourly, $metricId, Time::toDb($hour), 0, 0.0, null, null, $wh);
            $this->merge($this->daily, $metricId, Time::localDate($hour), 0, 0.0, null, null, $wh);
        }
    }

    public function count(): int
    {
        return count($this->hourly) + count($this->daily);
    }

    public function flush(\PDO $pdo): void
    {
        $this->write($pdo, 'sample_hourly', 'hour', $this->hourly);
        $this->write($pdo, 'sample_daily', 'day', $this->daily);
        $this->hourly = [];
        $this->daily = [];
    }

    /** @param array<string,array> $rows */
    private function merge(array &$rows, int $metricId, string $bucket, int $n, float $sum, ?float $min, ?float $max, float $wh): void
    {
        $key = $metricId . '|' . $bucket;
        if (!isset($rows[$key])) {
            $rows[$key] = [$metricId, $bucket, $n, $sum, $min, $max, $wh];
            return;
        }
        $r = &$rows[$key];
        $r[2] += $n;
        $r[3] += $sum;
        $r[4] = $min === null ? $r[4] : ($r[4] === null ? $min : min($r[4], $min));
        $r[5] = $max === null ? $r[5] : ($r[5] === null ? $max : max($r[5], $max));
        $r[6] += $wh;
    }

    /** @param array<string,array> $rows */
    private function write(\PDO $pdo, string $table, string $bucketColumn, array $rows): void
    {
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?)'));
            $sql = "INSERT INTO $table (metric_id, $bucketColumn, n, v_sum, v_min, v_max, energy_wh) VALUES $placeholders
                    ON DUPLICATE KEY UPDATE
                      n = n + VALUES(n),
                      v_sum = v_sum + VALUES(v_sum),
                      v_min = CASE WHEN v_min IS NULL THEN VALUES(v_min)
                                   WHEN VALUES(v_min) IS NULL THEN v_min
                                   ELSE LEAST(v_min, VALUES(v_min)) END,
                      v_max = CASE WHEN v_max IS NULL THEN VALUES(v_max)
                                   WHEN VALUES(v_max) IS NULL THEN v_max
                                   ELSE GREATEST(v_max, VALUES(v_max)) END,
                      energy_wh = energy_wh + VALUES(energy_wh)";
            $params = [];
            foreach ($chunk as $row) {
                array_push($params, ...$row);
            }
            $pdo->prepare($sql)->execute($params);
        }
    }
}
