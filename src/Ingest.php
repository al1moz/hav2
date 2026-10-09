<?php
declare(strict_types=1);

namespace Conso;

/**
 * Enregistre un lot de mesures : table sample, agrégats et dernière valeur.
 *
 * - Une mesure déjà reçue (même grandeur, même horodatage) est ignorée et comptée
 *   dans « duplicates » : renvoyer un lot ne compte jamais deux fois l'énergie.
 * - Une mesure plus ancienne que la dernière reçue pour sa grandeur est enregistrée
 *   et compte dans les moyennes, mais pas dans l'énergie (« late »).
 * - Une mesure invalide est rejetée seule, avec sa position et la raison.
 */
final class Ingest
{
    public const MAX_ITEMS = 5000;
    private const FUTURE_TOLERANCE = 300;

    /** @param mixed $payload @return array{0:int,1:array<string,mixed>} code HTTP et corps de réponse */
    public static function handle($payload, int $tokenId, ?string $idemKey): array
    {
        if (!is_array($payload) || !isset($payload['measurements']) || !is_array($payload['measurements'])) {
            return [422, ['error' => ['code' => 'invalid_body', 'message' => 'Corps JSON attendu avec un tableau « measurements ».']]];
        }
        $items = $payload['measurements'];
        if (count($items) > self::MAX_ITEMS) {
            return [413, ['error' => ['code' => 'too_many', 'message' => 'Au plus ' . self::MAX_ITEMS . ' mesures par lot.']]];
        }
        if ($idemKey !== null && !preg_match('/^[A-Za-z0-9_\-:.]{8,64}$/', $idemKey)) {
            return [422, ['error' => ['code' => 'invalid_idempotency_key', 'message' => 'Idempotency-Key : 8 à 64 caractères [A-Za-z0-9_-:.].']]];
        }

        $pdo = Db::pdo();
        $lock = (int) Db::one("SELECT GET_LOCK('consov2_ingest', 15) AS l")['l'];
        if ($lock !== 1) {
            return [503, ['error' => ['code' => 'busy', 'message' => 'Réception occupée, réessayer plus tard.']]];
        }
        try {
            if ($idemKey !== null) {
                $previous = Db::one('SELECT response FROM ingest_batch WHERE idem_key = ?', [$idemKey]);
                if ($previous !== null) {
                    $body = json_decode((string) $previous['response'], true);
                    $body['replayed'] = true;
                    return [202, $body];
                }
            }
            $pdo->beginTransaction();
            $body = self::store($pdo, $items);
            if ($idemKey !== null) {
                Db::run(
                    'INSERT INTO ingest_batch (idem_key, token_id, received_at, response) VALUES (?, ?, UTC_TIMESTAMP(), ?)',
                    [$idemKey, $tokenId, json_encode($body, JSON_UNESCAPED_UNICODE)]
                );
            }
            $pdo->commit();
            return [202, $body];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            Db::run("SELECT RELEASE_LOCK('consov2_ingest')");
        }
    }

    /** @param array<int,mixed> $items @return array<string,mixed> */
    private static function store(\PDO $pdo, array $items): array
    {
        $now = time();
        $rejected = [];
        $valid = [];
        foreach ($items as $index => $item) {
            $error = self::validate($item, $now);
            if ($error !== null) {
                $rejected[] = ['index' => $index, 'reason' => $error];
                continue;
            }
            $valid[] = $item + ['_ts' => Time::parse($item['ts']), '_index' => $index];
        }
        usort($valid, function (array $a, array $b): int {
            return $a['_ts'] <=> $b['_ts'] ?: $a['_index'] <=> $b['_index'];
        });

        $gaugeGap = Settings::int('gauge_max_gap_minutes', 15) * 60;
        $counterGap = Settings::int('counter_max_gap_days', 62) * 86400;
        $counterMaxPower = (float) Settings::int('counter_max_power_w', 36000);
        $insert = $pdo->prepare('INSERT IGNORE INTO sample (metric_id, ts, value) VALUES (?, ?, ?)');
        $aggregates = new Aggregates();
        /** @var array<int,array{ts:int,value:float}|null> $latest */
        $latest = [];
        $accepted = 0;
        $duplicates = 0;
        $late = 0;
        $rebased = 0;

        foreach ($valid as $item) {
            $metric = self::resolveMetric($item);
            if (is_string($metric)) {
                $rejected[] = ['index' => $item['_index'], 'reason' => $metric];
                continue;
            }
            $id = $metric['id'];
            $ts = $item['_ts'];
            $value = (float) $item['value'];
            if (isset($item['unit']) && $item['unit'] !== $metric['unit']) {
                $value *= Units::factor((string) $item['unit'], (string) $metric['unit']);
            }

            if (!array_key_exists($id, $latest)) {
                $row = Db::one('SELECT ts, value FROM sample_latest WHERE metric_id = ?', [$id]);
                $latest[$id] = $row === null ? null : ['ts' => Time::fromDb($row['ts']), 'value' => (float) $row['value']];
            }
            $prev = $latest[$id];
            if ($metric['kind'] === 'counter' && $metric['energy_factor'] !== null && $prev !== null && $ts > $prev['ts']
                && !Energy::counterPlausible($prev['ts'], $prev['value'], $ts, $value, $metric['energy_factor'], $counterMaxPower, $counterGap)) {
                $candidate = self::candidate($id);
                if (!Energy::confirmsCandidate($candidate, $ts, $value, $metric['energy_factor'], $counterMaxPower)) {
                    self::setCandidate($id, ['ts' => $ts, 'value' => $value]);
                    $rejected[] = ['index' => $item['_index'], 'reason' => 'index incohérent avec le précédent (baisse ou saut impossible) ; '
                        . 'mis de côté, il deviendra la nouvelle base si le suivant le confirme'];
                    continue;
                }
                // Deux index cohérents entre eux : compteur changé ou remis à zéro, on repart de là.
                $prev = $candidate;
                self::setCandidate($id, null);
                $rebased++;
            }

            $insert->execute([$id, Time::toDb($ts), $value]);
            if ($insert->rowCount() === 0) {
                $duplicates++;
                continue;
            }
            $accepted++;
            $aggregates->addValue($id, $ts, $value);

            if ($prev !== null && $ts <= $prev['ts']) {
                $late++;
                continue;
            }
            if ($prev !== null && $metric['energy_factor'] !== null) {
                $aggregates->addEnergy($id, $metric['kind'] === 'counter'
                    ? Energy::counter($prev['ts'], $prev['value'], $ts, $value, $metric['energy_factor'], $counterGap)
                    : Energy::gauge($prev['ts'], $ts, $value, $metric['energy_factor'], $gaugeGap));
            }
            $latest[$id] = ['ts' => $ts, 'value' => $value];
        }

        $aggregates->flush($pdo);
        $upsert = $pdo->prepare(
            'INSERT INTO sample_latest (metric_id, ts, value, received_at) VALUES (?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               value = IF(VALUES(ts) >= ts, VALUES(value), value),
               received_at = VALUES(received_at),
               ts = GREATEST(ts, VALUES(ts))'
        );
        foreach ($latest as $id => $state) {
            if ($state !== null) {
                $upsert->execute([$id, Time::toDb($state['ts']), $state['value']]);
            }
        }

        usort($rejected, function (array $a, array $b): int {
            return $a['index'] <=> $b['index'];
        });
        return [
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'late' => $late,
            'rebased' => $rebased,
            'rejected' => count($rejected),
            'errors' => array_slice($rejected, 0, 50),
        ];
    }

    /** @return array{ts:int,value:float}|null index mis de côté pour cette grandeur */
    private static function candidate(int $metricId): ?array
    {
        $row = Db::one('SELECT value FROM setting WHERE name = ?', ['counter_candidate_' . $metricId]);
        $data = $row === null ? null : json_decode((string) $row['value'], true);
        return is_array($data) && isset($data['ts'], $data['value']) ? ['ts' => (int) $data['ts'], 'value' => (float) $data['value']] : null;
    }

    /** @param array{ts:int,value:float}|null $candidate */
    private static function setCandidate(int $metricId, ?array $candidate): void
    {
        if ($candidate === null) {
            Db::run('DELETE FROM setting WHERE name = ?', ['counter_candidate_' . $metricId]);
            return;
        }
        Db::run(
            'INSERT INTO setting (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['counter_candidate_' . $metricId, json_encode($candidate)]
        );
    }

    /** @param mixed $item */
    private static function validate($item, int $now): ?string
    {
        if (!is_array($item)) {
            return 'objet attendu';
        }
        if (!isset($item['metric']) || !is_string($item['metric']) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $item['metric'])) {
            return 'metric : minuscules, chiffres et _ (64 caractères au plus)';
        }
        $value = $item['value'] ?? null;
        if (!(is_int($value) || (is_float($value) && is_finite($value)))) {
            return 'value : nombre attendu';
        }
        $ts = Time::parse($item['ts'] ?? null);
        if ($ts === null) {
            return 'ts : date ISO 8601 avec fuseau (ex. 2026-10-09T16:20:00Z) ou secondes Unix';
        }
        if ($ts > $now + self::FUTURE_TOLERANCE || $ts < 946684800) {
            return 'ts : date hors limites';
        }
        if (isset($item['unit']) && (!is_string($item['unit']) || strlen($item['unit']) > 16)) {
            return 'unit : texte de 16 caractères au plus';
        }
        if (isset($item['source']) && (!is_string($item['source']) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $item['source']))) {
            return 'source : minuscules, chiffres et _ (32 caractères au plus)';
        }
        if (isset($item['kind']) && !in_array($item['kind'], ['gauge', 'counter'], true)) {
            return 'kind : gauge ou counter';
        }
        return null;
    }

    /** @param array<string,mixed> $item @return array<string,mixed>|string la mesure, ou la raison du rejet */
    private static function resolveMetric(array $item)
    {
        $metric = Metrics::find($item['metric']);
        if ($metric === null) {
            if (!isset($item['source'], $item['unit'])) {
                return 'mesure inconnue : source et unit sont nécessaires pour la créer';
            }
            $metric = Metrics::findOrCreate($item['metric'], $item['source'], $item['unit'], $item['kind'] ?? 'gauge');
        }
        if (isset($item['unit']) && Units::factor((string) $item['unit'], (string) $metric['unit']) === null) {
            return "unit : « {$item['unit']} » incompatible avec « {$metric['unit']} »";
        }
        return $metric;
    }
}
