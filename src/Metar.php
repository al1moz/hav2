<?php
declare(strict_types=1);

namespace Conso;

/**
 * METAR de l'aérodrome réglé dans l'administration (page tablette), lu sur
 * aviationweather.gov et gardé 5 minutes dans setting.tablet_metar_cache.
 */
final class Metar
{
    private const CACHE = 'tablet_metar_cache';
    private const URL = 'https://aviationweather.gov/api/data/metar?format=json&ids=';
    private const TTL = 300;
    private const RETRY = 120;
    private const MAX_AGE = 7200;

    /** Vent en nœuds : [limite du pilote (orange), limite de l'avion (rouge)]. */
    private const CROSSWIND = [16, 20];
    private const HEADWIND = [32, 40];
    private const TAILWIND = [3, 3];

    private const CLOUDS = [
        'FEW' => 'Nuages peu nombreux, 1/8 à 2/8',
        'SCT' => 'Nuages épars, 3/8 à 4/8',
        'BKN' => 'Nuages fragmentés, 5/8 à 7/8',
        'OVC' => 'Couvert, 8/8',
        'VV' => 'Ciel invisible, visibilité verticale',
    ];
    private const NO_CLOUDS = [
        'CAVOK' => 'CAVOK',
        'NSC' => 'Aucun nuage significatif',
        'NCD' => 'Aucun nuage détecté',
        'SKC' => 'Ciel clair',
        'CLR' => 'Ciel clair',
    ];

    /** Dernier METAR (objet JSON d'aviationweather.gov), ou null si indisponible. @return array<string,mixed>|null */
    public static function latest(string $icao): ?array
    {
        if (!preg_match('/^[A-Z0-9]{4}$/', $icao)) {
            return null;
        }
        $now = time();
        $cache = json_decode(Settings::get(self::CACHE), true);
        if (!is_array($cache) || ($cache['icao'] ?? '') !== $icao) {
            $cache = ['icao' => $icao, 'tried_at' => 0, 'fetched_at' => 0, 'data' => null];
        }
        $fresh = $now - (int) $cache['fetched_at'] < self::TTL;
        if (!$fresh && $now - (int) $cache['tried_at'] >= self::RETRY) {
            $cache['tried_at'] = $now;
            $data = self::fetch($icao);
            if ($data !== null) {
                $cache['data'] = $data;
                $cache['fetched_at'] = $now;
            }
            Db::run(
                'INSERT INTO setting (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [self::CACHE, json_encode($cache, JSON_UNESCAPED_SLASHES)]
            );
        }
        return $now - (int) $cache['fetched_at'] < self::MAX_AGE && is_array($cache['data']) ? $cache['data'] : null;
    }

    /** @return array<string,mixed>|null */
    private static function fetch(string $icao): ?array
    {
        $context = stream_context_create(['http' => ['timeout' => 4, 'user_agent' => 'ConsoV2', 'ignore_errors' => true]]);
        $body = @file_get_contents(self::URL . $icao, false, $context);
        $list = $body === false ? null : json_decode($body, true);
        if (!is_array($list) || !isset($list[0]['rawOb'])) {
            error_log('METAR ' . $icao . ' : réponse inattendue de aviationweather.gov');
            return null;
        }
        return $list[0];
    }

    /**
     * Ce qu'affiche la tablette.
     * @param array<string,mixed> $m objet JSON d'aviationweather.gov
     * @param int[] $runways orientations des pistes en degrés
     * @return array{raw:string,category:?string,clouds:string[],wind:?array<string,mixed>,qnh:?int,obs:?int}
     */
    public static function describe(array $m, array $runways): array
    {
        $raw = trim((string) ($m['rawOb'] ?? ''));
        $tokens = self::mainPart($raw);
        $visibility = self::visibility($tokens);
        $category = $visibility !== null ? ($visibility > 8000 ? 'VFR' : 'IFR') : (isset($m['fltCat']) ? (string) $m['fltCat'] : null);

        $wind = null;
        $dir = $m['wdir'] ?? null;
        $speed = $m['wspd'] ?? null;
        $gust = $m['wgst'] ?? null;
        if (($dir === null || $speed === null) && preg_match('/(?:^|\s)(\d{3}|VRB)(\d{2,3})(?:G(\d{2,3}))?KT(?:\s|$)/', $raw, $w)) {
            $dir = $w[1];
            $speed = (int) $w[2];
            $gust = isset($w[3]) && $w[3] !== '' ? (int) $w[3] : null;
        }
        if ($speed !== null) {
            $wind = self::wind(is_numeric($dir) ? (int) $dir : null, (int) $speed, $runways);
            $wind['dir'] = is_numeric($dir) ? sprintf('%03d', (int) $dir) : 'VRB';
            $wind['gust'] = $gust !== null && (int) $gust > (int) $speed ? (int) $gust : null;
        }

        $qnh = isset($m['altim']) && is_numeric($m['altim']) ? (int) round((float) $m['altim']) : null;
        if ($qnh === null && preg_match('/(?:^|\s)Q(\d{4})(?:\s|$)/', $raw, $q)) {
            $qnh = (int) $q[1];
        }

        return [
            'raw' => $raw,
            'category' => $category,
            'clouds' => self::clouds($m, $tokens),
            'wind' => $wind,
            'qnh' => $qnh,
            'obs' => isset($m['obsTime']) && is_numeric($m['obsTime']) ? (int) $m['obsTime'] : null,
        ];
    }

    /**
     * Vent sur la piste la plus favorable. Sans piste réglée, pas de niveau.
     * @param int[] $runways
     * @return array{speed:int,level:?string,cross:?int}
     */
    public static function wind(?int $dir, int $speed, array $runways): array
    {
        $best = ['speed' => $speed, 'level' => null, 'cross' => null];
        $rank = ['ok' => 0, 'warn' => 1, 'bad' => 2];
        foreach ($runways as $runway) {
            if ($dir === null) {
                $cross = $speed;
                $head = 0;
            } else {
                $angle = deg2rad($dir - $runway);
                $cross = (int) round(abs($speed * sin($angle)));
                $head = (int) round($speed * cos($angle));
            }
            $level = 'ok';
            if ($cross >= self::CROSSWIND[0] || $head >= self::HEADWIND[0] || $head <= -self::TAILWIND[0]) {
                $level = 'warn';
            }
            if ($cross >= self::CROSSWIND[1] || $head >= self::HEADWIND[1] || $head <= -self::TAILWIND[1]) {
                $level = 'bad';
            }
            if ($best['level'] === null || $rank[$level] < $rank[$best['level']]
                || ($rank[$level] === $rank[$best['level']] && $cross < $best['cross'])) {
                $best = ['speed' => $speed, 'level' => $level, 'cross' => $cross];
            }
        }
        return $best;
    }

    /**
     * Nuit aéronautique à la station du METAR (lat et lon fournis par aviationweather.gov),
     * ou null si la position manque.
     * @param array<string,mixed> $m
     * @return array{night:bool,until:int}|null
     */
    public static function night(array $m, int $now): ?array
    {
        if (!isset($m['lat'], $m['lon']) || !is_numeric($m['lat']) || !is_numeric($m['lon'])) {
            return null;
        }
        return self::nightAt((float) $m['lat'], (float) $m['lon'], $now);
    }

    /**
     * Nuit au sens de SERA : de la fin du crépuscule civil au début de l'aube civile.
     * « until » est l'heure du prochain changement. Les jours voisins sont calculés aussi,
     * car date_sun_info() choisit le jour selon le fuseau de PHP.
     * @return array{night:bool,until:int}|null
     */
    public static function nightAt(float $lat, float $lon, int $now): ?array
    {
        $events = [];
        foreach ([-86400, 0, 86400] as $shift) {
            $sun = date_sun_info($now + $shift, $lat, $lon);
            if (is_int($sun['civil_twilight_begin']) && is_int($sun['civil_twilight_end'])) {
                $events[$sun['civil_twilight_begin']] = false;
                $events[$sun['civil_twilight_end']] = true;
            }
        }
        ksort($events);
        $night = null;
        foreach ($events as $ts => $startsNight) {
            if ($ts > $now) {
                return $night === null ? null : ['night' => $night, 'until' => $ts];
            }
            $night = $startsNight;
        }
        return null;
    }

    /** Orientations en degrés à partir du réglage (« 070, 250 » ou numéros de piste « 07/25 »). @return int[] */
    public static function runways(string $setting): array
    {
        $out = [];
        foreach (preg_split('/[^0-9]+/', $setting, -1, PREG_SPLIT_NO_EMPTY) as $n) {
            $deg = (int) $n;
            if (strlen($n) <= 2 && $deg <= 36) {
                $deg *= 10;
            }
            if ($deg >= 0 && $deg <= 360) {
                $out[] = $deg % 360;
            }
        }
        return array_values(array_unique($out));
    }

    /** Groupes du message principal, sans les prévisions (TEMPO, BECMG…). @return string[] */
    private static function mainPart(string $raw): array
    {
        $tokens = [];
        foreach (preg_split('/\s+/', str_replace('=', '', $raw), -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (in_array($t, ['TEMPO', 'BECMG', 'NOSIG', 'RMK'], true) || strncmp($t, 'PROB', 4) === 0) {
                break;
            }
            $tokens[] = $t;
        }
        return $tokens;
    }

    /** Visibilité dominante en mètres (CAVOK = plus de 10 km), ou null. @param string[] $tokens */
    private static function visibility(array $tokens): ?int
    {
        foreach ($tokens as $t) {
            if ($t === 'CAVOK') {
                return 10000;
            }
            if (preg_match('/^(\d{4})(NDV)?$/', $t, $v)) {
                return (int) $v[1];
            }
            if (preg_match('/^(\d+)KM$/', $t, $v)) {
                return 1000 * (int) $v[1];
            }
            if (preg_match('/^[PM]?(\d+)(?:\/(\d+))?SM$/', $t, $v)) {
                $miles = isset($v[2]) && $v[2] !== '' ? (int) $v[1] / max(1, (int) $v[2]) : (int) $v[1];
                return (int) round($miles * 1609.344);
            }
        }
        return null;
    }

    /** @param array<string,mixed> $m @param string[] $tokens @return string[] */
    private static function clouds(array $m, array $tokens): array
    {
        $out = [];
        foreach ((array) ($m['clouds'] ?? []) as $c) {
            $cover = (string) ($c['cover'] ?? '');
            if (isset(self::CLOUDS[$cover]) && isset($c['base']) && is_numeric($c['base'])) {
                $out[] = self::CLOUDS[$cover] . ' à ' . (int) $c['base'] . ' ft';
            }
        }
        if ($out) {
            return $out;
        }
        foreach ($tokens as $t) {
            if (isset(self::NO_CLOUDS[$t])) {
                return [self::NO_CLOUDS[$t]];
            }
            if (preg_match('/^(FEW|SCT|BKN|OVC|VV)(\d{3})/', $t, $c)) {
                $out[] = self::CLOUDS[$c[1]] . ' à ' . (100 * (int) $c[2]) . ' ft';
            }
        }
        return $out;
    }
}
