<?php
declare(strict_types=1);

namespace Conso;

use Conso\Api\Read;
use Conso\Api\Views;

/**
 * Outils de lecture proposés à Claude dans le chat. Chacun appelle une route GET /api/v1/…
 * (Http::capture) et met le résultat en forme compacte, en heure locale.
 */
final class ChatTools
{
    /** Lignes au plus par résultat : au-delà, Claude doit réduire la période ou regrouper. */
    private const MAX_ROWS = 400;
    private const MAX_HOURS_DAYS = 16;

    private const DATE = ['type' => 'string', 'description' => 'Date locale AAAA-MM-JJ (incluse).'];

    /** Libellés des étapes affichées pendant que Claude travaille. */
    private const LABELS = [
        'get_dashboard' => 'État actuel',
        'get_series' => 'Évolution',
        'get_summary' => 'Totaux',
        'get_breakdown' => 'Répartition par circuit',
        'get_daily_profile' => 'Profil de la journée',
        'get_ecs_activations' => 'Appoint ECS',
        'get_monthly_comparison' => 'Comparaison par mois',
        'get_temperature_days' => 'Consommation selon la température',
    ];

    /** @return array<int,array<string,mixed>> définitions envoyées à l'API (schémas stricts) */
    public static function definitions(): array
    {
        $none = ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
        $range = function (array $extra = []): array {
            $props = $extra + ['from' => self::DATE, 'to' => self::DATE];
            return ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];
        };
        $metric = ['type' => 'string', 'description' => 'Code de la mesure (voir la liste des mesures).'];
        $tools = [
            ['get_dashboard', 'État actuel : puissance électrique, kWh depuis minuit, hier et hier à la même heure, prix du kWh et coût du jour, '
                . 'dernière valeur de chaque mesure avec son heure, sources en retard, activations récentes de l\'appoint ECS.', $none],
            ['get_series', 'Évolution d\'une mesure jour par jour (step=day, ' . self::MAX_ROWS . ' jours au plus) ou heure par heure '
                . '(step=hour, ' . self::MAX_HOURS_DAYS . ' jours au plus). Pour l\'énergie : kWh de chaque période ; '
                . 'pour les autres mesures : moyenne, minimum et maximum.',
                $range(['metric' => $metric, 'step' => ['type' => 'string', 'enum' => ['hour', 'day']]])],
            ['get_summary', 'Totaux d\'une mesure par jour, mois ou année : jours couverts, moyenne, minimum, maximum et, pour l\'énergie, '
                . 'kWh et coût en euros au prix du kWh de l\'époque.',
                $range(['metric' => $metric, 'period' => ['type' => 'string', 'enum' => ['day', 'month', 'year']]])],
            ['get_breakdown', 'kWh du compteur Linky (total de la maison) et de chaque circuit mesuré par jour, mois ou année. '
                . 'rest = total moins circuits. coverage = part du temps où les Shelly répondaient : sous 0,9, rest n\'est pas fiable.',
                $range(['period' => ['type' => 'string', 'enum' => ['day', 'month', 'year']]])],
            ['get_daily_profile', 'Puissance moyenne de la maison (kW) pour chaque heure de la journée (0 à 23 h, heure locale) sur la période.', $range()],
            ['get_ecs_activations', 'Toutes les activations de la résistance d\'appoint du ballon d\'eau chaude : début, durée, kWh, par année.', $none],
            ['get_monthly_comparison', 'Pour chaque mois de l\'historique : kWh, jours mesurés et température extérieure moyenne. '
                . 'Pour chaque hiver (octobre à avril) : degrés-jours (base 18 °C), kWh et kWh par degré-jour.', $none],
            ['get_temperature_days', 'Un point par jour complet : température extérieure moyenne (°C) et kWh du jour, pour étudier le lien entre froid et consommation.', $range()],
        ];
        $out = [];
        foreach ($tools as [$name, $description, $schema]) {
            $out[] = ['name' => $name, 'description' => $description, 'input_schema' => $schema, 'strict' => true];
        }
        return $out;
    }

    /** Libellé de l'étape pour l'affichage. @param array<string,mixed> $input */
    public static function label(string $name, array $input): string
    {
        $label = self::LABELS[$name] ?? $name;
        if (isset($input['metric']) && is_string($input['metric'])) {
            $m = Metrics::find($input['metric']);
            $label .= ' : ' . ($m === null ? $input['metric'] : $m['label']);
        }
        if (isset($input['from'], $input['to']) && is_string($input['from']) && is_string($input['to'])) {
            $label .= ', ' . $input['from'] . ' → ' . $input['to'];
        }
        return $label;
    }

    /**
     * Exécute un outil. Renvoie [données, erreur] : une erreur est un message pour Claude.
     * @param array<string,mixed> $input
     * @return array{0:mixed,1:?string}
     */
    public static function run(string $name, array $input): array
    {
        switch ($name) {
            case 'get_dashboard':
                [$data, $error] = self::call([Views::class, 'dashboard'], []);
                if ($error === null) {
                    unset($data['site_name']);
                    $data = self::localTimes($data);
                }
                return [$data, $error];

            case 'get_series':
                $metric = self::metric($input);
                $range = self::range($input, ($input['step'] ?? '') === 'hour' ? self::MAX_HOURS_DAYS : self::MAX_ROWS);
                if (is_string($metric) || is_string($range)) {
                    return [null, is_string($metric) ? $metric : $range];
                }
                $step = ($input['step'] ?? '') === 'hour' ? 'hour' : 'day';
                [$data, $error] = self::call([Read::class, 'series'], ['metric' => $metric['code'], 'from' => $range[0], 'to' => $range[1], 'step' => $step]);
                if ($error !== null) {
                    return [null, $error];
                }
                $rows = [];
                foreach ($data['points'] as [$ts, $avg, $min, $max, $wh]) {
                    $when = $step === 'hour' ? self::localTime((string) $ts) : $ts;
                    if ($metric['kind'] === 'counter') {
                        $rows[] = [$when, round($wh / 1000, 3)];
                    } elseif ($metric['energy_factor'] !== null) {
                        $rows[] = [$when, $avg, $max, round($wh / 1000, 3)];
                    } else {
                        $rows[] = [$when, $avg, $min, $max];
                    }
                }
                $columns = $metric['kind'] === 'counter' ? ['période', 'kWh']
                    : ($metric['energy_factor'] !== null ? ['période', 'moyenne', 'max', 'kWh'] : ['période', 'moyenne', 'min', 'max']);
                return [['metric' => $metric['code'], 'unit' => $metric['unit'], 'step' => $step, 'columns' => $columns, 'rows' => $rows], null];

            case 'get_summary':
                $metric = self::metric($input);
                $period = in_array($input['period'] ?? '', ['day', 'month', 'year'], true) ? (string) $input['period'] : 'month';
                $range = self::range($input, $period === 'day' ? self::MAX_ROWS : 100000);
                if (is_string($metric) || is_string($range)) {
                    return [null, is_string($metric) ? $metric : $range];
                }
                [$data, $error] = self::call([Read::class, 'summary'], ['metric' => $metric['code'], 'period' => $period, 'from' => $range[0], 'to' => $range[1]]);
                if ($error === null) {
                    foreach ($data['rows'] as &$row) {
                        unset($row['n']);
                        if ($metric['kind'] === 'counter') {
                            unset($row['avg'], $row['min'], $row['max']); // valeurs de l'index, sans intérêt ici
                        }
                    }
                    unset($row);
                }
                return [$data, $error];

            case 'get_breakdown':
                $period = in_array($input['period'] ?? '', ['day', 'month', 'year'], true) ? (string) $input['period'] : 'day';
                $range = self::range($input, $period === 'day' ? self::MAX_ROWS : 100000);
                if (is_string($range)) {
                    return [null, $range];
                }
                return self::call([Views::class, 'breakdown'], ['period' => $period, 'from' => $range[0], 'to' => $range[1]]);

            case 'get_daily_profile':
                $range = self::range($input, 100000);
                if (is_string($range)) {
                    return [null, $range];
                }
                [$data, $error] = self::call([Views::class, 'profile'], ['from' => $range[0], 'to' => $range[1]]);
                if ($error === null) {
                    $data['kw_par_heure'] = $data['kw'];
                    unset($data['kw']);
                }
                return [$data, $error];

            case 'get_ecs_activations':
                [$data, $error] = self::call([Views::class, 'ecs'], []);
                return [$error === null ? self::localTimes($data) : null, $error];

            case 'get_monthly_comparison':
                [$data, $error] = self::call([Views::class, 'compare'], []);
                if ($error === null) {
                    unset($data['days']);
                }
                return [$data, $error];

            case 'get_temperature_days':
                $range = self::range($input, self::MAX_ROWS);
                if (is_string($range)) {
                    return [null, $range];
                }
                [$data, $error] = self::call([Views::class, 'compare'], []);
                if ($error !== null) {
                    return [null, $error];
                }
                $days = array_values(array_filter($data['days'], function (array $d) use ($range): bool {
                    return $d[0] >= $range[0] && $d[0] <= $range[1];
                }));
                return [['columns' => ['jour', 'température extérieure moyenne', 'kWh'], 'rows' => $days], null];
        }
        return [null, 'Outil inconnu : ' . $name];
    }

    /**
     * Dates from/to vérifiées : [from, to], ou un message d'erreur.
     * @param array<string,mixed> $input
     * @return array{0:string,1:string}|string
     */
    public static function range(array $input, int $maxDays)
    {
        $from = (string) ($input['from'] ?? '');
        $to = (string) ($input['to'] ?? '');
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($a === false || $b === false || $a->format('Y-m-d') !== $from || $b->format('Y-m-d') !== $to) {
            return 'from et to : dates AAAA-MM-JJ.';
        }
        if ($from > $to) {
            return 'from doit précéder to.';
        }
        $days = (int) $a->diff($b)->days + 1;
        if ($days > $maxDays) {
            return "Période trop longue ($days jours, $maxDays au plus) : réduis-la ou regroupe par mois.";
        }
        return [$from, $to];
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|string */
    private static function metric(array $input)
    {
        $code = (string) ($input['metric'] ?? '');
        $metric = $code === '' ? null : Metrics::find($code);
        return $metric ?? 'Mesure inconnue : « ' . $code . ' ». Les codes sont dans la liste des mesures.';
    }

    /** @param array<string,string> $query @return array{0:mixed,1:?string} */
    private static function call(callable $route, array $query): array
    {
        [$status, $data] = Http::capture($route, $query);
        if ($status !== 200) {
            return [null, is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : 'Erreur ' . $status . '.'];
        }
        return [$data, null];
    }

    /** Remplace récursivement les dates ISO UTC par l'heure locale « AAAA-MM-JJ HH:MM ». @param mixed $value @return mixed */
    private static function localTimes($value)
    {
        if (is_array($value)) {
            return array_map([self::class, 'localTimes'], $value);
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value)) {
            return self::localTime($value);
        }
        return $value;
    }

    private static function localTime(string $iso): string
    {
        return (new \DateTimeImmutable($iso))->setTimezone(Config::timezone())->format('Y-m-d H:i');
    }
}
