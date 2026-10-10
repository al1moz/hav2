<?php
declare(strict_types=1);

namespace Conso;

/**
 * Page Météo, tournée vers le vol à l'aéroclub : vent observé aux stations voisines et prévisions
 * d'aujourd'hui et de demain, lus sur l'API de Météo Concept (jeton METEO_CONCEPT_TOKEN du .env).
 * Les prévisions de plusieurs points (l'aéroclub et les stations retenues) sont moyennées pour
 * estimer le temps à l'aéroclub. Le forfait gratuit permet 500 appels par jour : les réponses,
 * réduites à ce que la page affiche, sont gardées dans weather_cache ; le nombre d'appels du jour est celui que
 * Météo Concept renvoie dans l'en-tête X-Api-Calls (il compte aussi les appels faits ailleurs avec le même jeton).
 */
final class Weather
{
    private const BASE = 'https://api.meteo-concept.com/api/';
    /** Quota par défaut (forfait gratuit), remplacé par l'en-tête X-Api-Limit ; le site s'arrête à 90 % du quota. */
    private const QUOTA = 500;
    private const STOP_RATIO = 0.9;
    private const OBS_TTL = 600;
    private const FORECAST_TTL = 3600;
    private const RETRY = 300;
    public const RADIUS_KM = 30;
    /** Observation plus ancienne : listée, mais pas comptée dans la moyenne (certaines stations publient avec une heure de retard). */
    private const OBS_MAX_AGE = 5400;
    /** Sans choix dans l'administration : les stations les plus proches qui mesurent le vent. */
    private const DEFAULT_STATIONS = 4;
    public const MAX_STATIONS = 6;
    /** Distance minimale pour la pondération (km) : le point de l'aéroclub ne doit pas tout écraser. */
    private const MIN_WEIGHT_KM = 2.0;

    /** Périodes de Météo Concept et heure locale de leur début (la nuit va de 1 h à 7 h). */
    public const PERIODS = ['Nuit', 'Matin', 'Après-midi', 'Soir'];
    private const PERIOD_START = [1, 7, 13, 19];

    /** Champs d'une prévision : moyennés, ou pris au plus élevé des points. */
    private const MEAN = ['wind', 'gust', 'temp', 'rh', 'rain', 'probarain', 'iso0'];
    private const MAX = ['rain_max', 'gustx', 'probafog', 'probafrost', 'probawind70', 'probawind100'];

    /** Codes du temps sensible (table de la documentation de Météo Concept). */
    public const CODES = [
        0 => 'Soleil', 1 => 'Peu nuageux', 2 => 'Ciel voilé', 3 => 'Nuageux', 4 => 'Très nuageux', 5 => 'Couvert',
        6 => 'Brouillard', 7 => 'Brouillard givrant',
        10 => 'Pluie faible', 11 => 'Pluie modérée', 12 => 'Pluie forte',
        13 => 'Pluie faible verglaçante', 14 => 'Pluie modérée verglaçante', 15 => 'Pluie forte verglaçante', 16 => 'Bruine',
        20 => 'Neige faible', 21 => 'Neige modérée', 22 => 'Neige forte',
        30 => 'Pluie et neige mêlées faibles', 31 => 'Pluie et neige mêlées modérées', 32 => 'Pluie et neige mêlées fortes',
        40 => 'Averses locales et faibles', 41 => 'Averses locales', 42 => 'Averses locales et fortes',
        43 => 'Averses faibles', 44 => 'Averses', 45 => 'Averses fortes',
        46 => 'Averses faibles et fréquentes', 47 => 'Averses fréquentes', 48 => 'Averses fortes et fréquentes',
        60 => 'Averses de neige localisées et faibles', 61 => 'Averses de neige localisées', 62 => 'Averses de neige localisées et fortes',
        63 => 'Averses de neige faibles', 64 => 'Averses de neige', 65 => 'Averses de neige fortes',
        66 => 'Averses de neige faibles et fréquentes', 67 => 'Averses de neige fréquentes', 68 => 'Averses de neige fortes et fréquentes',
        70 => 'Averses de pluie et neige localisées et faibles', 71 => 'Averses de pluie et neige localisées', 72 => 'Averses de pluie et neige localisées et fortes',
        73 => 'Averses de pluie et neige faibles', 74 => 'Averses de pluie et neige', 75 => 'Averses de pluie et neige fortes',
        76 => 'Averses de pluie et neige faibles et nombreuses', 77 => 'Averses de pluie et neige fréquentes', 78 => 'Averses de pluie et neige fortes et fréquentes',
        100 => 'Orages faibles et locaux', 101 => 'Orages locaux', 102 => 'Orages forts et locaux',
        103 => 'Orages faibles', 104 => 'Orages', 105 => 'Orages forts',
        106 => 'Orages faibles et fréquents', 107 => 'Orages fréquents', 108 => 'Orages forts et fréquents',
        120 => 'Orages faibles et locaux de neige ou grésil', 121 => 'Orages locaux de neige ou grésil', 122 => 'Orages locaux de neige ou grésil',
        123 => 'Orages faibles de neige ou grésil', 124 => 'Orages de neige ou grésil', 125 => 'Orages de neige ou grésil',
        126 => 'Orages faibles et fréquents de neige ou grésil', 127 => 'Orages fréquents de neige ou grésil', 128 => 'Orages fréquents de neige ou grésil',
        130 => 'Orages faibles et locaux de pluie et neige ou grésil', 131 => 'Orages locaux de pluie et neige ou grésil', 132 => 'Orages forts et locaux de pluie et neige ou grésil',
        133 => 'Orages faibles de pluie et neige ou grésil', 134 => 'Orages de pluie et neige ou grésil', 135 => 'Orages forts de pluie et neige ou grésil',
        136 => 'Orages faibles et fréquents de pluie et neige ou grésil', 137 => 'Orages fréquents de pluie et neige ou grésil', 138 => 'Orages forts et fréquents de pluie et neige ou grésil',
        140 => 'Pluies orageuses', 141 => 'Pluie et neige à caractère orageux', 142 => 'Neige à caractère orageux',
        210 => 'Pluie faible intermittente', 211 => 'Pluie modérée intermittente', 212 => 'Pluie forte intermittente',
        220 => 'Neige faible intermittente', 221 => 'Neige modérée intermittente', 222 => 'Neige forte intermittente',
        230 => 'Pluie et neige mêlées', 231 => 'Pluie et neige mêlées', 232 => 'Pluie et neige mêlées', 235 => 'Averses de grêle',
    ];

    public static function token(): string
    {
        return trim((string) Config::get('METEO_CONCEPT_TOKEN', ''));
    }

    /** Position de l'aéroclub (réglage weather_center), ou null. @return array{0:float,1:float}|null */
    public static function center(): ?array
    {
        return self::parsePoint(Settings::get('weather_center'));
    }

    /** « 46.5, 2.5 » (virgule ou espace entre les deux) en [latitude, longitude], ou null. @return array{0:float,1:float}|null */
    public static function parsePoint(string $value): ?array
    {
        if (!preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,; ]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $value, $m)) {
            return null;
        }
        $lat = (float) $m[1];
        $lon = (float) $m[2];
        return abs($lat) <= 90 && abs($lon) <= 180 ? [$lat, $lon] : null;
    }

    /** Seuils de vent en km/h (administration). @return array{cross_warn:int,cross_max:int,gust_warn:int,gust_max:int} */
    public static function limits(): array
    {
        return [
            'cross_warn' => Settings::int('weather_cross_warn', 18),
            'cross_max' => Settings::int('weather_cross_max', 25),
            'gust_warn' => Settings::int('weather_gust_warn', 37),
            'gust_max' => Settings::int('weather_gust_max', 46),
        ];
    }

    /** Identifiants des stations cochées dans l'administration. @return string[] */
    public static function chosenIds(): array
    {
        return array_values(array_filter(explode(',', Settings::get('weather_stations')), function (string $id): bool {
            return (bool) preg_match('/^[0-9a-f-]{36}$/', $id);
        }));
    }

    public static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;
        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    // ---- Lecture de l'API avec cache ----

    /**
     * Compteur de Météo Concept (en-têtes X-Api-Calls et X-Api-Limit de la dernière réponse), remis à zéro
     * au changement de jour local : sans nouvel appel, l'ancien compteur bloquerait le site.
     * @return array{day:string,calls:int,limit:int}
     */
    public static function quota(): array
    {
        $q = self::load('quota')['data'];
        $limit = is_array($q) && (int) ($q['limit'] ?? 0) > 0 ? (int) $q['limit'] : self::QUOTA;
        if (!is_array($q) || ($q['day'] ?? '') !== self::today()) {
            return ['day' => self::today(), 'calls' => 0, 'limit' => $limit];
        }
        return ['day' => $q['day'], 'calls' => (int) $q['calls'], 'limit' => $limit];
    }

    /** Appels comptés aujourd'hui par Météo Concept. */
    public static function callsToday(): int
    {
        return self::quota()['calls'];
    }

    /** Seuil où le site arrête d'appeler : 90 % du quota (marge pour les essais à la main). */
    public static function stopAt(): int
    {
        return (int) floor(self::quota()['limit'] * self::STOP_RATIO);
    }

    private static function today(): string
    {
        return (new \DateTimeImmutable('now', Config::timezone()))->format('Y-m-d');
    }

    /** Stations autour de l'aéroclub (dernières observations gardées), pour l'administration. @return array<int,array<string,mixed>> */
    public static function stationsAround(): array
    {
        $center = self::center();
        if ($center === null) {
            return [];
        }
        self::refresh($center, time());
        $obs = self::load('obs');
        return is_array($obs['data']) && ($obs['data']['center'] ?? '') === self::latlng($center) ? $obs['data']['stations'] : [];
    }

    /**
     * Rafraîchit ce qui est périmé : observations toutes les 10 min (un appel pour toutes les stations),
     * prévisions toutes les heures (deux appels par point). Rien au-delà de stopAt() appels dans la journée ;
     * après un échec, nouvel essai 5 min plus tard. Un seul rafraîchissement à la fois (verrou MySQL).
     * @param array{0:float,1:float} $center
     */
    private static function refresh(array $center, int $now): void
    {
        if (self::token() === '') {
            return;
        }
        $lock = Db::one("SELECT GET_LOCK('consov2_meteo', 0) AS l");
        if ($lock === null || (int) $lock['l'] !== 1) {
            return;
        }
        try {
            $obs = self::load('obs');
            $here = self::latlng($center);
            $stale = $now - $obs['fetched_at'] >= self::OBS_TTL || ($obs['data']['center'] ?? '') !== $here;
            if ($stale && !self::failedRecently($obs, $now) && self::budget(1)) {
                $obs['tried_at'] = $now;
                $path = 'observations/around?radius=' . self::RADIUS_KM . '&latlng=' . $here;
                $list = self::getMany([$path])[$path];
                if ($list !== null && self::isList($list)) {
                    $obs['data'] = ['center' => $here, 'stations' => self::parseStations($list, $center)];
                    $obs['fetched_at'] = $now;
                }
                self::save('obs', $obs);
            }
            $stations = ($obs['data']['center'] ?? '') === $here ? $obs['data']['stations'] : [];

            $points = self::points($center, self::selected($stations));
            $key = implode('|', array_column($points, 'key'));
            $fc = self::load('forecast');
            $stale = $now - $fc['fetched_at'] >= self::FORECAST_TTL || ($fc['data']['key'] ?? '') !== $key;
            if ($stale && !self::failedRecently($fc, $now)) {
                $data = self::fetchForecast($points, is_array($fc['data']) ? $fc['data'] : null, $now);
                if ($data !== false) {
                    $fc['tried_at'] = $now;
                    if ($data !== null) {
                        $fc['data'] = ['key' => $key] + $data;
                        $fc['fetched_at'] = $now;
                    }
                    self::save('forecast', $fc);
                }
            }
        } finally {
            Db::one("SELECT RELEASE_LOCK('consov2_meteo')");
        }
    }

    /**
     * Points de prévision : l'aéroclub puis les stations retenues. Deux points qui retombent sur le même
     * point de grille de Météo Concept (vu au premier appel) ne sont plus redemandés.
     * @param array{0:float,1:float} $center
     * @param array<int,array<string,mixed>> $stations
     * @return array<int,array{key:string,label:string,lat:float,lon:float}>
     */
    private static function points(array $center, array $stations): array
    {
        $out = [self::latlng($center) => ['key' => self::latlng($center), 'label' => 'Aéroclub', 'lat' => $center[0], 'lon' => $center[1]]];
        foreach ($stations as $s) {
            $k = self::latlng([(float) $s['lat'], (float) $s['lon']]);
            if (!isset($out[$k])) {
                $out[$k] = ['key' => $k, 'label' => (string) $s['name'], 'lat' => (float) $s['lat'], 'lon' => (float) $s['lon']];
            }
        }
        return array_values($out);
    }

    /**
     * Prévisions par période (14 jours, on garde aujourd'hui et demain) et prochaines heures, pour chaque point.
     * @param array<int,array{key:string,label:string,lat:float,lon:float}> $points
     * @param array<string,mixed>|null $previous
     * @return array<string,mixed>|null|false false : quota atteint, rien demandé ; null : tout a échoué
     */
    private static function fetchForecast(array $points, ?array $previous, int $now)
    {
        $old = [];
        foreach ((array) ($previous['points'] ?? []) as $p) {
            $old[$p['key']] = $p;
        }
        $paths = [];
        foreach ($points as $p) {
            if (!empty($old[$p['key']]['dup'])) {
                continue;
            }
            $paths[$p['key']] = ['forecast/daily/periods?latlng=' . $p['key'], 'forecast/nextHours?hourly=true&latlng=' . $p['key']];
        }
        if (!$paths) {
            return null;
        }
        if (!self::budget(2 * count($paths))) {
            return false;
        }
        $res = self::getMany(array_merge(...array_values($paths)));

        $lastDay = (new \DateTimeImmutable('@' . $now))->setTimezone(Config::timezone())->modify('+2 days')->format('Y-m-d');
        $out = [];
        $grids = [];
        $ok = false;
        foreach ($points as $p) {
            $prev = $old[$p['key']] ?? null;
            if (!isset($paths[$p['key']])) {
                $out[] = $prev;
                continue;
            }
            [$pPath, $hPath] = $paths[$p['key']];
            $periods = self::parsePeriods($res[$pPath], $lastDay);
            $hours = self::parseHours($res[$hPath], $now);
            if ($periods === null) {
                if ($prev !== null) {
                    $out[] = $prev;
                }
                continue;
            }
            $ok = true;
            $point = $p + ['grid' => $periods['grid'], 'periods' => $periods['items'], 'hours' => $hours ?? ($prev['hours'] ?? [])];
            $point['dup'] = in_array($point['grid'], $grids, true);
            $grids[] = $point['grid'];
            $out[] = $point;
        }
        return $ok ? ['points' => array_values($out)] : null;
    }

    /** Dernier essai raté il y a moins de RETRY secondes (une réussite ne bloque pas un nouvel appel après un changement de réglage). @param array{fetched_at:int,tried_at:int,data:mixed} $row */
    private static function failedRecently(array $row, int $now): bool
    {
        return $row['tried_at'] > $row['fetched_at'] && $now - $row['tried_at'] < self::RETRY;
    }

    private static function budget(int $calls): bool
    {
        return self::callsToday() + $calls <= self::stopAt();
    }

    /**
     * Appels en parallèle. Le jeton passe dans l'en-tête Authorization (pas dans l'adresse, qui finit dans les journaux).
     * @param string[] $paths
     * @return array<string,array<mixed>|null> réponse JSON décodée par chemin (null si échec)
     */
    private static function getMany(array $paths): array
    {
        $mh = curl_multi_init();
        $handles = [];
        $counters = ['calls' => null, 'limit' => null];
        foreach ($paths as $path) {
            $ch = curl_init(self::BASE . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'ConsoV2',
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . self::token()],
                // Compteur de Météo Concept : on garde la plus grande valeur des réponses (appels en parallèle).
                CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$counters): int {
                    if (preg_match('/^x-api-(calls|limit):\s*(\d+)/i', $line, $m)) {
                        $k = strtolower($m[1]);
                        $counters[$k] = max((int) $counters[$k], (int) $m[2]);
                    }
                    return strlen($line);
                },
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$path] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $path => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $body = curl_multi_getcontent($ch);
            $data = $code === 200 && is_string($body) ? json_decode($body, true) : null;
            if (!is_array($data)) {
                error_log('Météo Concept ' . strtok($path, '?') . ' : ' . ($code === 0 ? curl_error($ch) : 'HTTP ' . $code));
            }
            $out[$path] = is_array($data) ? $data : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        $q = self::quota();
        $q['calls'] = $counters['calls'] ?? $q['calls'] + count($paths);
        if ($counters['limit'] !== null) {
            $q['limit'] = $counters['limit'];
        }
        self::save('quota', ['fetched_at' => time(), 'tried_at' => time(), 'data' => $q]);
        return $out;
    }

    /** @return array{fetched_at:int,tried_at:int,data:mixed} */
    private static function load(string $name): array
    {
        $row = Db::one('SELECT fetched_at, tried_at, data FROM weather_cache WHERE name = ?', [$name]);
        return [
            'fetched_at' => $row === null ? 0 : (int) $row['fetched_at'],
            'tried_at' => $row === null ? 0 : (int) $row['tried_at'],
            'data' => $row === null || $row['data'] === null ? null : json_decode((string) $row['data'], true),
        ];
    }

    /** @param array{fetched_at:int,tried_at:int,data:mixed} $row */
    private static function save(string $name, array $row): void
    {
        Db::run(
            'INSERT INTO weather_cache (name, fetched_at, tried_at, data) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE fetched_at = VALUES(fetched_at), tried_at = VALUES(tried_at), data = VALUES(data)',
            [$name, $row['fetched_at'], $row['tried_at'], $row['data'] === null ? null : json_encode($row['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }

    /** @param array{0:float,1:float} $p */
    private static function latlng(array $p): string
    {
        return rtrim(rtrim(sprintf('%.4F', $p[0]), '0'), '.') . ',' . rtrim(rtrim(sprintf('%.4F', $p[1]), '0'), '.');
    }

    /** @param array<mixed> $a */
    private static function isList(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    // ---- Lecture des réponses ----

    /**
     * Stations de la réponse observations/around, de la plus proche à la plus lointaine.
     * Une station sans relevé récent a une observation vide (liste vide dans la réponse).
     * @param array<int,mixed> $list
     * @param array{0:float,1:float} $center
     * @return array<int,array<string,mixed>>
     */
    public static function parseStations(array $list, array $center): array
    {
        $out = [];
        foreach ($list as $item) {
            $s = is_array($item) ? ($item['station'] ?? null) : null;
            if (!is_array($s) || !isset($s['uuid'], $s['latitude'], $s['longitude'])) {
                continue;
            }
            $o = $item['observation'] ?? null;
            $o = is_array($o) && !self::isList($o) ? $o : [];
            $val = function (string ...$keys) use ($o): ?float {
                foreach ($keys as $k) {
                    if (isset($o[$k]['value']) && is_numeric($o[$k]['value'])) {
                        return (float) $o[$k]['value'];
                    }
                }
                return null;
            };
            $lat = (float) $s['latitude'];
            $lon = (float) $s['longitude'];
            $out[] = [
                'uuid' => (string) $s['uuid'],
                'name' => trim((string) ($s['name'] ?? '')),
                'lat' => $lat,
                'lon' => $lon,
                'dist' => round(self::distanceKm($center[0], $center[1], $lat, $lon), 1),
                'ts' => isset($o['time']) ? Time::parse((string) $o['time']) : null,
                'wind' => $val('wind_10m', 'wind_s'),
                'gust' => $val('windgust_10m', 'windgust_s'),
                'dir' => $val('wind_direction', 'wind_direction_s'),
                'temp' => $val('temperature', 'outside_temperature'),
                'rh' => $val('humidity', 'outside_humidity'),
                'qnh' => $val('atmospheric_pressure', 'barometer'),
            ];
        }
        usort($out, function (array $a, array $b): int {
            return $a['dist'] <=> $b['dist'];
        });
        return $out;
    }

    /**
     * Prévisions par période (réponse de forecast/daily/periods : une liste de jours de 4 périodes),
     * jusqu'au jour $lastDay compris. Le premier jour de la réponse peut être la veille (juste après minuit) :
     * chaque période est rangée par sa date réelle, pas par son rang.
     * @param array<mixed>|null $data
     * @return array{grid:string,items:array<int,array<string,mixed>>}|null
     */
    public static function parsePeriods(?array $data, string $lastDay): ?array
    {
        if (!is_array($data['forecast'] ?? null)) {
            return null;
        }
        $items = [];
        $grid = null;
        foreach ($data['forecast'] as $day) {
            foreach (is_array($day) ? $day : [] as $f) {
                $item = is_array($f) ? self::item($f) : null;
                if ($item === null || !isset($f['period']) || Time::localDate($item['ts']) > $lastDay) {
                    continue;
                }
                $item['period'] = (int) $f['period'];
                $items[] = $item;
                if ($grid === null && isset($f['latitude'], $f['longitude'])) {
                    $grid = sprintf('%.4F,%.4F', (float) $f['latitude'], (float) $f['longitude']);
                }
            }
        }
        return $items ? ['grid' => (string) $grid, 'items' => $items] : null;
    }

    /**
     * Prévisions horaires (forecast/nextHours), sans les heures déjà passées.
     * @param array<mixed>|null $data
     * @return array<int,array<string,mixed>>|null
     */
    public static function parseHours(?array $data, int $now): ?array
    {
        if (!is_array($data['forecast'] ?? null)) {
            return null;
        }
        $out = [];
        foreach ($data['forecast'] as $f) {
            $item = is_array($f) ? self::item($f) : null;
            if ($item !== null && $item['ts'] >= $now - 3600) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $f @return array<string,mixed>|null */
    private static function item(array $f): ?array
    {
        $ts = Time::parse((string) ($f['datetime'] ?? ''));
        if ($ts === null) {
            return null;
        }
        $num = function (string $key) use ($f): ?float {
            return isset($f[$key]) && is_numeric($f[$key]) ? (float) $f[$key] : null;
        };
        return [
            'ts' => $ts,
            'lat' => $num('latitude'),
            'lon' => $num('longitude'),
            'weather' => isset($f['weather']) && is_numeric($f['weather']) ? (int) $f['weather'] : null,
            'wind' => $num('wind10m'),
            'gust' => $num('gust10m'),
            'dir' => $num('dirwind10m'),
            'temp' => $num('temp2m') ?? (($num('tmin') !== null && $num('tmax') !== null) ? ($num('tmin') + $num('tmax')) / 2 : null),
            'rh' => $num('rh2m'),
            'rain' => $num('rr10'),
            'rain_max' => $num('rr1'),
            'probarain' => $num('probarain'),
            'probafog' => $num('probafog'),
            'probafrost' => $num('probafrost'),
            'probawind70' => $num('probawind70'),
            'probawind100' => $num('probawind100'),
            'gustx' => $num('gustx'),
            'iso0' => $num('iso0'),
        ];
    }

    // ---- Calculs ----

    /**
     * Moyenne de plusieurs points pondérée par le champ w. Vent, rafales, température, pluie : moyennes ;
     * direction : moyenne des vecteurs vent (350° et 10° donnent 0°, pas 180°) ; risques de brouillard, gel
     * et vent fort, pluie maximale : le plus élevé des points, pour ne pas diluer une alerte ;
     * temps : le code le plus fréquent (poids cumulés), le plus mauvais en cas d'égalité.
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    public static function blend(array $items): array
    {
        $out = ['n' => count($items)];
        foreach (self::MEAN as $k) {
            $sum = $weights = 0.0;
            foreach ($items as $it) {
                if (isset($it[$k])) {
                    $sum += $it['w'] * $it[$k];
                    $weights += $it['w'];
                }
            }
            $out[$k] = $weights > 0 ? round($sum / $weights, 1) : null;
        }
        foreach (self::MAX as $k) {
            $vals = array_filter(array_column($items, $k), 'is_numeric');
            $out[$k] = $vals ? max($vals) : null;
        }
        foreach (['wind', 'gust'] as $k) {
            $vals = array_filter(array_column($items, $k), 'is_numeric');
            $out[$k . '_min'] = $vals ? min($vals) : null;
            $out[$k . '_max'] = $vals ? max($vals) : null;
        }

        $x = $y = 0.0;
        foreach ($items as $it) {
            if (isset($it['dir'])) {
                $len = $it['w'] * max((float) ($it['wind'] ?? 1), 0.1);
                $x += $len * sin(deg2rad((float) $it['dir']));
                $y += $len * cos(deg2rad((float) $it['dir']));
            }
        }
        $out['dir'] = hypot($x, $y) > 1e-6 ? ((int) round(rad2deg(atan2($x, $y))) + 360) % 360 : null;

        $votes = [];
        foreach ($items as $it) {
            if (isset($it['weather'])) {
                $votes[$it['weather']] = ($votes[$it['weather']] ?? 0) + $it['w'];
            }
        }
        $best = null;
        foreach ($votes as $code => $v) {
            $tie = abs($v - ($best === null ? 0 : $votes[$best])) <= 1e-9;
            $worse = $best !== null && [self::severity($code), $code] > [self::severity($best), $best];
            if ($best === null || $v > $votes[$best] + 1e-9 || ($tie && $worse)) {
                $best = $code;
            }
        }
        $out['weather'] = $best;
        $out['label'] = $best === null ? null : (self::CODES[$best] ?? 'Temps ' . $best);
        return $out;
    }

    /** Gravité d'un code de temps pour le vol, de 0 (soleil) à 6 (orage, grêle). */
    public static function severity(int $code): int
    {
        if (($code >= 100 && $code <= 142) || $code === 235) {
            return 6;
        }
        if (in_array($code, [7, 13, 14, 15], true)) {
            return 5;
        }
        if ($code === 6 || ($code >= 20 && $code <= 32) || ($code >= 60 && $code <= 78) || ($code >= 220 && $code <= 232)) {
            return 4;
        }
        if ($code >= 10) {
            return in_array($code, [12, 42, 45, 48, 212], true) ? 3 : 2;
        }
        return $code >= 4 ? 1 : 0;
    }

    /** Orientations des pistes, avec les sens opposés s'ils manquent (07 donne aussi 25). @param int[] $runways @return int[] */
    public static function bothWays(array $runways): array
    {
        $out = $runways;
        foreach ($runways as $r) {
            $out[] = ($r + 180) % 360;
        }
        return array_values(array_unique($out));
    }

    /**
     * Vent sur la piste la plus favorable (plus fort vent de face), composantes en km/h arrondies.
     * cross > 0 : vent venant de la droite. Sans direction (vent variable), tout le vent compte en travers.
     * @param int[] $runways orientations en degrés
     * @return array{runway:int,head:int,cross:int,gust_cross:?int}|null
     */
    public static function runwayWind(?float $dir, float $speed, ?float $gust, array $runways): ?array
    {
        $best = null;
        foreach ($runways as $r) {
            if ($dir === null) {
                $head = 0.0;
                $cross = $speed;
                $gc = $gust;
            } else {
                $a = deg2rad($dir - $r);
                $head = $speed * cos($a);
                $cross = $speed * sin($a);
                $gc = $gust === null ? null : $gust * sin($a);
            }
            if ($best === null || $head > $best['head'] + 1e-9) {
                $best = ['runway' => $r, 'head' => $head, 'cross' => $cross, 'gust_cross' => $gc];
            }
        }
        if ($best === null) {
            return null;
        }
        return [
            'runway' => $best['runway'],
            'head' => (int) round($best['head']),
            'cross' => (int) round($best['cross']),
            'gust_cross' => $best['gust_cross'] === null ? null : (int) round($best['gust_cross']),
        ];
    }

    /**
     * Pastille d'un moment : vert, orange ou rouge, avec les raisons. Vent de travers (rafales comprises)
     * sur la piste la plus favorable et rafales selon les seuils de l'administration ; pour une prévision,
     * aussi le temps : orage ou brouillard en rouge, risque de brouillard, neige, forte pluie ou coup de vent en orange.
     * @param array<string,mixed> $s moyenne de blend()
     * @param int[] $runways
     * @param array{cross_warn:int,cross_max:int,gust_warn:int,gust_max:int} $limits
     * @return array{level:string,reasons:string[],runway:?array<string,int|null>}
     */
    public static function assess(array $s, array $runways, array $limits, bool $forecast): array
    {
        $level = 0;
        $reasons = [];
        $raise = function (int $to, string $why) use (&$level, &$reasons): void {
            $level = max($level, $to);
            $reasons[] = $why;
        };
        $rw = isset($s['wind']) && $runways
            ? self::runwayWind(isset($s['dir']) ? (float) $s['dir'] : null, (float) $s['wind'], isset($s['gust']) ? (float) $s['gust'] : null, $runways)
            : null;
        if ($rw !== null) {
            $cross = max(abs($rw['cross']), abs((int) $rw['gust_cross']));
            if ($cross > $limits['cross_max']) {
                $raise(2, 'travers ' . $cross . ' km/h');
            } elseif ($cross >= $limits['cross_warn']) {
                $raise(1, 'travers ' . $cross . ' km/h');
            }
        }
        $gust = isset($s['gust']) ? (int) round((float) $s['gust']) : null;
        if ($gust !== null && $gust >= $limits['gust_max']) {
            $raise(2, 'rafales ' . $gust . ' km/h');
        } elseif ($gust !== null && $gust >= $limits['gust_warn']) {
            $raise(1, 'rafales ' . $gust . ' km/h');
        }
        if ($forecast) {
            $code = isset($s['weather']) ? (int) $s['weather'] : null;
            if ($code !== null && self::severity($code) === 6) {
                $raise(2, mb_strtolower(self::CODES[$code] ?? 'orage'));
            } elseif ($code === 6 || $code === 7) {
                $raise(2, mb_strtolower(self::CODES[$code]));
            } elseif ($code !== null && self::severity($code) >= 3) {
                $raise(self::severity($code) === 5 ? 2 : 1, mb_strtolower(self::CODES[$code] ?? 'précipitations'));
            }
            if (($s['probafog'] ?? 0) >= 50 && $code !== 6 && $code !== 7) {
                $raise(1, 'brouillard probable (' . (int) $s['probafog'] . ' %)');
            }
            if (($s['probawind100'] ?? 0) >= 30) {
                $raise(2, 'risque de tempête (' . (int) $s['probawind100'] . ' %)');
            } elseif (($s['probawind70'] ?? 0) >= 50) {
                $raise(1, 'risque de coup de vent (' . (int) $s['probawind70'] . ' %)');
            }
        }
        return ['level' => ['ok', 'warn', 'bad'][$level], 'reasons' => $reasons, 'runway' => $rw];
    }

    /** Stations retenues : celles cochées dans l'administration, sinon les plus proches qui mesurent le vent. @param array<int,array<string,mixed>> $stations @return array<int,array<string,mixed>> */
    public static function selected(array $stations): array
    {
        $chosen = self::chosenIds();
        if ($chosen) {
            return array_values(array_filter($stations, function (array $s) use ($chosen): bool {
                return in_array($s['uuid'], $chosen, true);
            }));
        }
        $out = [];
        foreach ($stations as $s) {
            if ($s['wind'] !== null && $s['dir'] !== null) {
                $out[] = $s;
                if (count($out) === self::DEFAULT_STATIONS) {
                    break;
                }
            }
        }
        return $out;
    }

    /** Poids d'un point dans les moyennes : inverse de sa distance à l'aéroclub. */
    private static function weight(float $km): float
    {
        return 1 / max(self::MIN_WEIGHT_KM, $km);
    }

    // ---- Page ----

    /** Ce qu'affiche la page Météo (GET /api/v1/weather). @return array<string,mixed> */
    public static function view(int $now): array
    {
        $center = self::center();
        $runways = self::bothWays(Metar::runways(Settings::get('tablet_runways')));
        $limits = self::limits();
        $problems = [];
        if (self::token() === '') {
            $problems[] = 'Jeton METEO_CONCEPT_TOKEN absent du .env : la page ne peut pas interroger Météo Concept.';
        }
        if ($center === null) {
            $problems[] = 'Position de l\'aéroclub à saisir dans l\'administration (section Météo).';
        } else {
            self::refresh($center, $now);
        }
        if (!$runways) {
            $problems[] = 'Orientation des pistes à saisir dans l\'administration (section Tablette) pour calculer le vent de face et de travers.';
        }
        $calls = self::callsToday();
        if ($calls >= self::stopAt()) {
            $problems[] = $calls . ' appels à Météo Concept aujourd\'hui : la page garde les dernières données jusqu\'à minuit.';
        }

        $out = [
            'problems' => $problems,
            'runways' => $runways,
            'limits' => $limits,
            'radius_km' => self::RADIUS_KM,
            'calls' => ['today' => $calls, 'stop_at' => self::stopAt(), 'quota' => self::quota()['limit']],
            'observed' => null,
            'stations' => [],
            'hours' => [],
            'days' => [],
            'points' => [],
            'updated' => ['observations' => null, 'forecast' => null],
            'sun' => $center === null ? null : self::sun($center, $now),
        ];
        $av = self::aviation($runways);
        $taf = $av['taf_parsed'];
        unset($av['taf_parsed']);
        $out += $av;
        if ($center === null) {
            return $out;
        }

        // Vent observé : moyenne des stations retenues, pondérée par la distance.
        $obs = self::load('obs');
        if (($obs['data']['center'] ?? '') === self::latlng($center)) {
            $out['updated']['observations'] = Time::iso($obs['fetched_at']);
            $items = [];
            foreach (self::selected($obs['data']['stations']) as $s) {
                $used = $s['ts'] !== null && $now - $s['ts'] <= self::OBS_MAX_AGE && $s['wind'] !== null;
                if ($used) {
                    $items[] = ['w' => self::weight((float) $s['dist']), 'wind' => $s['wind'], 'gust' => $s['gust'], 'dir' => $s['dir'], 'temp' => $s['temp'], 'ts' => $s['ts']];
                }
                $out['stations'][] = [
                    'name' => $s['name'], 'dist' => $s['dist'], 'ts' => $s['ts'] === null ? null : Time::iso($s['ts']), 'used' => $used,
                    'wind' => $s['wind'], 'gust' => $s['gust'], 'dir' => $s['dir'], 'temp' => $s['temp'],
                    'weight' => $used ? self::weight((float) $s['dist']) : 0,
                ];
            }
            $total = array_sum(array_column($out['stations'], 'weight'));
            foreach ($out['stations'] as &$st) {
                $st['weight'] = $total > 0 ? (int) round(100 * $st['weight'] / $total) : 0;
            }
            unset($st);
            if ($items) {
                $b = self::blend($items);
                $out['observed'] = [
                    'wind' => $b['wind'], 'gust' => $b['gust'], 'dir' => $b['dir'], 'temp' => $b['temp'],
                    'gust_max' => $b['gust_max'], 'n' => count($items),
                    'ts' => Time::iso((int) max(array_column($items, 'ts'))),
                    'assess' => self::assess($b, $runways, $limits, false),
                ];
            }
        }

        // Prévisions : moyenne des points de grille distincts, pondérée par leur distance à l'aéroclub.
        $fc = self::load('forecast');
        $points = [];
        foreach ((array) ($fc['data']['points'] ?? []) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $first = $p['periods'][0] ?? null;
            $km = $first !== null && $first['lat'] !== null ? self::distanceKm($center[0], $center[1], (float) $first['lat'], (float) $first['lon']) : 0.0;
            $out['points'][] = ['label' => $p['label'], 'grid_km' => round($km, 1), 'dup' => !empty($p['dup'])];
            if (empty($p['dup'])) {
                $points[] = $p + ['w' => self::weight($km)];
            }
        }
        if ($points) {
            $out['updated']['forecast'] = Time::iso($fc['fetched_at']);
            $out['days'] = self::days($points, $now, $runways, $limits, $taf);
            $out['hours'] = self::hours($points, $now, $runways, $limits);
        }
        return $out;
    }

    /**
     * Aujourd'hui et demain, par période.
     * @param array<int,array<string,mixed>> $points
     * @param int[] $runways
     * @param array{cross_warn:int,cross_max:int,gust_warn:int,gust_max:int} $limits
     * @param array<string,mixed>|null $taf TAF analysé (Metar::parseTaf), pour le plafond et la visibilité
     * @return array<int,array<string,mixed>>
     */
    private static function days(array $points, int $now, array $runways, array $limits, ?array $taf): array
    {
        $tz = Config::timezone();
        $out = [];
        foreach ([0, 1] as $offset) {
            $date = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->modify('+' . $offset . ' day')->format('Y-m-d');
            $periods = [];
            foreach (self::PERIOD_START as $period => $hour) {
                $start = (new \DateTimeImmutable($date . ' 00:00', $tz))->setTime($hour, 0)->getTimestamp();
                $end = $start + 6 * 3600;
                $items = [];
                foreach ($points as $p) {
                    foreach ($p['periods'] as $it) {
                        if ($it['period'] === $period && Time::localDate($it['ts']) === $date) {
                            $items[] = $it + ['w' => $p['w']];
                            break;
                        }
                    }
                }
                if (!$items) {
                    $periods[] = null;
                    continue;
                }
                $b = self::blend($items);
                $window = $taf === null ? null : Metar::tafWindow($taf, max($start, $now), $end);
                $periods[] = $b + [
                    'period' => $period, 'name' => self::PERIODS[$period],
                    'start' => Time::iso($start), 'end' => Time::iso($end),
                    'state' => $end <= $now ? 'past' : ($start <= $now ? 'now' : 'next'),
                    'taf' => $window,
                    'assess' => self::withTaf(self::assess($b, $runways, $limits, true), $window),
                ];
            }
            $out[] = ['date' => $date, 'periods' => $periods];
        }
        return $out;
    }

    /**
     * Prochaines heures (12 au plus) quand l'API en donne : la nuit, elle en renvoie parfois très peu.
     * @param array<int,array<string,mixed>> $points
     * @param int[] $runways
     * @param array{cross_warn:int,cross_max:int,gust_warn:int,gust_max:int} $limits
     * @return array<int,array<string,mixed>>
     */
    private static function hours(array $points, int $now, array $runways, array $limits): array
    {
        $byTs = [];
        foreach ($points as $p) {
            foreach ((array) ($p['hours'] ?? []) as $it) {
                if ($it['ts'] > $now - 3600 && $it['ts'] <= $now + 12 * 3600) {
                    $byTs[$it['ts']][] = $it + ['w' => $p['w']];
                }
            }
        }
        ksort($byTs);
        $out = [];
        foreach ($byTs as $ts => $items) {
            $b = self::blend($items);
            $out[] = $b + ['ts' => Time::iso($ts), 'assess' => self::assess($b, $runways, $limits, true)];
        }
        return $out;
    }

    /**
     * Soleil à l'aéroclub : lever, coucher et nuit aéronautique (fin du crépuscule civil à l'aube civile).
     * @param array{0:float,1:float} $center
     * @return array<string,mixed>
     */
    private static function sun(array $center, int $now): array
    {
        $tz = Config::timezone();
        $out = [];
        foreach ([0, 1] as $offset) {
            $noon = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->modify('+' . $offset . ' day')->setTime(12, 0)->getTimestamp();
            $s = date_sun_info($noon, $center[0], $center[1]);
            $iso = function ($v): ?string {
                return is_int($v) ? Time::iso($v) : null;
            };
            $out[] = [
                'date' => Time::localDate($noon),
                'dawn' => $iso($s['civil_twilight_begin']), 'sunrise' => $iso($s['sunrise']),
                'sunset' => $iso($s['sunset']), 'dusk' => $iso($s['civil_twilight_end']),
            ];
        }
        $night = Metar::nightAt($center[0], $center[1], $now);
        return ['days' => $out, 'night' => $night === null ? null : ['night' => $night['night'], 'until' => Time::iso($night['until'])]];
    }

    /**
     * METAR et TAF de l'aérodrome réglé pour la tablette : plafond, visibilité et temps présent,
     * que Météo Concept ne donne pas. « taf_parsed » sert aux périodes et n'est pas envoyé à la page.
     * @param int[] $runways
     * @return array<string,mixed>
     */
    private static function aviation(array $runways): array
    {
        $icao = strtoupper(trim(Settings::get('tablet_icao')));
        $out = ['metar' => null, 'taf' => null, 'icao' => $icao === '' ? null : $icao, 'taf_parsed' => null];
        if ($icao === '') {
            return $out;
        }
        $m = Metar::latest($icao);
        if ($m !== null) {
            $d = Metar::describe($m, $runways);
            $out['metar'] = [
                'raw' => $d['raw'], 'category' => $d['category'], 'qnh' => $d['qnh'],
                'obs' => $d['obs'] === null ? null : Time::iso($d['obs']),
                'wind' => $d['wind'] === null ? null : ['dir' => $d['wind']['dir'], 'kt' => $d['wind']['speed'], 'gust_kt' => $d['wind']['gust']],
                'sky' => self::sky($d['decoded']),
            ];
        }
        $t = Metar::taf($icao);
        $issued = $t !== null && isset($t['issueTime']) ? Time::parse((string) $t['issueTime']) : null;
        if ($t !== null && $issued !== null) {
            $parsed = Metar::parseTaf((string) $t['rawTAF'], $issued);
            $out['taf_parsed'] = $parsed;
            $out['taf'] = [
                'raw' => (string) $t['rawTAF'], 'issued' => Time::iso($issued),
                'from' => $parsed['from'] === null ? null : Time::iso($parsed['from']),
                'to' => $parsed['to'] === null ? null : Time::iso($parsed['to']),
                'groups' => array_map(function (array $g): array {
                    return [
                        'type' => $g['type'], 'prob' => $g['prob'], 'text' => $g['text'],
                        'from' => $g['from'] === null ? null : Time::iso($g['from']), 'to' => $g['to'] === null ? null : Time::iso($g['to']),
                        'wind' => $g['wind'],
                    ] + self::sky($g);
                }, $parsed['groups']),
            ];
        }
        return $out;
    }

    /** Ciel d'un groupe décodé de METAR ou de TAF, pour la page. @param array<string,mixed> $g @return array<string,mixed> */
    private static function sky(array $g): array
    {
        return [
            'label' => $g['label'], 'code' => $g['code'], 'weather' => $g['weather'], 'visibility' => $g['visibility'],
            'ceiling' => $g['ceiling'], 'layers' => $g['layers'], 'clear' => $g['sky'],
        ];
    }

    /**
     * Ajoute à la pastille d'une période ce que dit le TAF : plafond ou visibilité sous les minima du vol à vue
     * (Metar::CEILING_FT, Metar::VISIBILITY_M) en orange ou en rouge ; conditions temporaires en orange au plus.
     * @param array{level:string,reasons:string[],runway:mixed} $assess
     * @param array<string,mixed>|null $w fenêtre de Metar::tafWindow()
     * @return array{level:string,reasons:string[],runway:mixed}
     */
    public static function withTaf(array $assess, ?array $w): array
    {
        if ($w === null) {
            return $assess;
        }
        $rank = ['ok' => 0, 'warn' => 1, 'bad' => 2];
        $level = $rank[$assess['level']];
        $check = function (?array $c, bool $temp) use (&$level, &$assess): void {
            if ($c === null) {
                return;
            }
            $prefix = $temp ? 'temporairement ' : '';
            $max = $temp ? 1 : 2;
            if ($c['ceiling'] !== null && $c['ceiling'] < Metar::CEILING_FT[0]) {
                $level = max($level, $c['ceiling'] < Metar::CEILING_FT[1] ? $max : 1);
                $assess['reasons'][] = $prefix . 'plafond ' . $c['ceiling'] . ' ft';
            }
            if ($c['visibility'] !== null && $c['visibility'] < Metar::VISIBILITY_M[0]) {
                $level = max($level, $c['visibility'] < Metar::VISIBILITY_M[1] ? $max : 1);
                $assess['reasons'][] = $prefix . 'visibilité ' . number_format($c['visibility'] / 1000, 1, ',', '') . ' km';
            }
        };
        $check($w['main'], false);
        $check($w['temp'], true);
        $assess['level'] = array_search($level, $rank, true);
        return $assess;
    }
}
