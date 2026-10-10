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
    private const TAF_CACHE = 'weather_taf_cache';
    private const TAF_URL = 'https://aviationweather.gov/api/data/taf?format=json&ids=';
    private const TAF_TTL = 1800;

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
    /** Phénomènes du temps présent (METAR, TAF) : nom et genre, pour accorder « faible » et « fort ». */
    private const WX = [
        'DZ' => ['bruine', 'f'], 'RA' => ['pluie', 'f'], 'SN' => ['neige', 'f'], 'SG' => ['neige en grains', 'f'],
        'IC' => ['cristaux de glace', 'mpl'], 'PL' => ['granules de glace', 'mpl'], 'GR' => ['grêle', 'f'], 'GS' => ['grésil', 'm'],
        'UP' => ['précipitations', 'fpl'], 'BR' => ['brume', 'f'], 'FG' => ['brouillard', 'm'], 'FU' => ['fumée', 'f'],
        'VA' => ['cendres', 'fpl'], 'DU' => ['poussière', 'f'], 'SA' => ['sable', 'm'], 'HZ' => ['brume sèche', 'f'],
        'PO' => ['tourbillons de poussière', 'mpl'], 'SQ' => ['grains', 'mpl'], 'FC' => ['trombe', 'f'],
        'SS' => ['tempête de sable', 'f'], 'DS' => ['tempête de poussière', 'f'],
    ];
    private const WX_RE = '/^(\+|-|VC)?(MI|BC|PR|DR|BL|SH|TS|FZ)?((?:DZ|RA|SN|SG|IC|PL|GR|GS|UP|BR|FG|FU|VA|DU|SA|HZ|PO|SQ|FC|SS|DS)*)$/';
    private const TAF_TYPES = ['BASE' => 'Prévision', 'FM' => 'À partir de', 'BECMG' => 'Devient', 'TEMPO' => 'Temporairement'];
    /** Minima retenus pour la pastille (vol à vue) : [orange, rouge] en pieds et en mètres. */
    public const CEILING_FT = [1500, 1000];
    public const VISIBILITY_M = [5000, 1500];

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
        return self::cached(self::CACHE, self::URL, 'rawOb', $icao, self::TTL, self::MAX_AGE);
    }

    /** Dernier TAF (page Météo), gardé 30 minutes, ou null s'il est indisponible ou n'est plus valide. @return array<string,mixed>|null */
    public static function taf(string $icao): ?array
    {
        $taf = self::cached(self::TAF_CACHE, self::TAF_URL, 'rawTAF', $icao, self::TAF_TTL, 30 * 3600);
        return $taf !== null && (!isset($taf['validTimeTo']) || (int) $taf['validTimeTo'] > time()) ? $taf : null;
    }

    /**
     * Lecture gardée dans la table setting : nouvel essai RETRY secondes après un échec,
     * réponse oubliée après $maxAge secondes.
     * @return array<string,mixed>|null
     */
    private static function cached(string $name, string $url, string $field, string $icao, int $ttl, int $maxAge): ?array
    {
        if (!preg_match('/^[A-Z0-9]{4}$/', $icao)) {
            return null;
        }
        $now = time();
        $cache = json_decode(Settings::get($name), true);
        if (!is_array($cache) || ($cache['icao'] ?? '') !== $icao) {
            $cache = ['icao' => $icao, 'tried_at' => 0, 'fetched_at' => 0, 'data' => null];
        }
        $fresh = $now - (int) $cache['fetched_at'] < $ttl;
        if (!$fresh && $now - (int) $cache['tried_at'] >= self::RETRY) {
            $cache['tried_at'] = $now;
            $data = self::fetch($url, $field, $icao);
            if ($data !== null) {
                $cache['data'] = $data;
                $cache['fetched_at'] = $now;
            }
            Db::run(
                'INSERT INTO setting (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$name, json_encode($cache, JSON_UNESCAPED_SLASHES)]
            );
        }
        return $now - (int) $cache['fetched_at'] < $maxAge && is_array($cache['data']) ? $cache['data'] : null;
    }

    /** @return array<string,mixed>|null */
    private static function fetch(string $url, string $field, string $icao): ?array
    {
        $context = stream_context_create(['http' => ['timeout' => 4, 'user_agent' => 'ConsoV2', 'ignore_errors' => true]]);
        $body = @file_get_contents($url . $icao, false, $context);
        $list = $body === false ? null : json_decode($body, true);
        if (!is_array($list) || !isset($list[0][$field])) {
            error_log(($field === 'rawOb' ? 'METAR ' : 'TAF ') . $icao . ' : réponse inattendue de aviationweather.gov');
            return null;
        }
        return $list[0];
    }

    /** TAF découpé en lignes : une par groupe d'évolution (BECMG, TEMPO, PROB30 TEMPO, FM…). @return string[] */
    public static function tafLines(string $raw): array
    {
        $raw = trim((string) preg_replace('/\s+/', ' ', str_replace('=', '', $raw)));
        return $raw === '' ? [] : preg_split('/ (?=(?:BECMG|FM\d{6}|PROB\d{2})\b)|(?<!PROB\d\d) (?=TEMPO\b)/', $raw);
    }

    /**
     * Ce qu'affiche la tablette.
     * @param array<string,mixed> $m objet JSON d'aviationweather.gov
     * @param int[] $runways orientations des pistes en degrés
     * @return array{raw:string,category:?string,clouds:string[],wind:?array<string,mixed>,qnh:?int,obs:?int,decoded:array<string,mixed>}
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
            'decoded' => self::decodeGroup(array_slice($tokens, 1)),
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

    /**
     * Décode un groupe de METAR ou de TAF : vent, visibilité, temps présent, couches de nuages et plafond
     * (plus basse couche BKN, OVC ou ciel invisible). « code » est le code de temps de Météo Concept
     * le plus proche, pour reprendre les mêmes pictogrammes.
     * @param string[] $tokens
     * @return array<string,mixed>
     */
    public static function decodeGroup(array $tokens): array
    {
        $g = ['wind' => null, 'visibility' => null, 'weather' => [], 'wx' => [], 'layers' => [], 'ceiling' => null,
            'sky' => null, 'has_clouds' => false, 'has_weather' => false];
        foreach ($tokens as $t) {
            if (preg_match('/^(\d{3}|VRB)(\d{2,3})(?:G(\d{2,3}))?(KT|MPS)$/', $t, $w)) {
                $k = $w[4] === 'MPS' ? 1.943844 : 1.0;
                $g['wind'] = ['dir' => $w[1] === 'VRB' ? null : (int) $w[1], 'kt' => (int) round((int) $w[2] * $k),
                    'gust_kt' => isset($w[3]) && $w[3] !== '' ? (int) round((int) $w[3] * $k) : null];
            } elseif ($t === 'CAVOK') {
                $g['visibility'] = 10000;
                $g['sky'] = 'CAVOK';
                $g['has_clouds'] = $g['has_weather'] = true;
            } elseif ($t === 'NSW') {
                $g['has_weather'] = true;
            } elseif (isset(self::NO_CLOUDS[$t])) {
                $g['sky'] = $t;
                $g['has_clouds'] = true;
            } elseif (preg_match('/^(FEW|SCT|BKN|OVC|VV)(\d{3}|\/\/\/)(CB|TCU)?$/', $t, $c)) {
                $base = $c[2] === '///' ? null : 100 * (int) $c[2];
                $g['layers'][] = ['cover' => $c[1], 'base' => $base, 'type' => $c[3] ?? null];
                $g['has_clouds'] = true;
                if ($base !== null && in_array($c[1], ['BKN', 'OVC', 'VV'], true) && ($g['ceiling'] === null || $base < $g['ceiling'])) {
                    $g['ceiling'] = $base;
                }
            } elseif ($t !== '' && preg_match(self::WX_RE, $t, $x) && ($x[2] !== '' || $x[3] !== '') && ($x[3] !== '' || in_array($x[2], ['TS', 'SH'], true))) {
                $g['weather'][] = self::wxLabel($x[1], $x[2], str_split($x[3], 2));
                $g['wx'][] = $t;
                $g['has_weather'] = true;
            } elseif ($g['visibility'] === null) {
                $v = self::visibility([$t]);
                if ($v !== null) {
                    $g['visibility'] = $v;
                }
            }
        }
        $g['code'] = self::code($g);
        $g['label'] = $g['weather'] ? implode(', ', $g['weather']) : self::skyLabel($g);
        return $g;
    }

    /** « -SHRA » donne « Averses faibles de pluie », « +TSRA » « Orage fort avec pluie ». @param string[] $phen */
    private static function wxLabel(string $int, string $desc, array $phen): string
    {
        $names = [];
        foreach ($phen as $p) {
            if (isset(self::WX[$p])) {
                $names[] = self::WX[$p];
            }
        }
        $what = implode(' et ', array_column($names, 0));
        if ($desc === 'SH') {
            [$noun, $gender, $rest] = ['averses', 'fpl', $what === '' ? '' : ' de ' . $what];
        } elseif ($desc === 'TS') {
            [$noun, $gender, $rest] = ['orage', 'm', $what === '' ? '' : ' avec ' . $what];
        } elseif ($desc === 'BC' || $desc === 'PR' || $desc === 'MI') {
            [$noun, $gender, $rest] = [['BC' => 'bancs de ', 'PR' => 'banc partiel de ', 'MI' => 'mince couche de '][$desc] . $what, 'mpl', ''];
        } else {
            [$noun, $gender, $rest] = [$what, $names[0][1] ?? 'f', ''];
            if ($desc === 'FZ') {
                $rest = $noun === 'brouillard' ? ' givrant' : ' verglaçante';
            } elseif ($desc === 'BL' || $desc === 'DR') {
                $rest = ' soulevée par le vent';
            }
        }
        $adj = '';
        if ($int === '-' || $int === '+') {
            $forms = $int === '-' ? ['m' => 'faible', 'f' => 'faible', 'mpl' => 'faibles', 'fpl' => 'faibles']
                : ['m' => 'fort', 'f' => 'forte', 'mpl' => 'forts', 'fpl' => 'fortes'];
            $adj = ' ' . $forms[$gender];
        }
        $text = $noun . $adj . $rest . ($int === 'VC' ? ' au voisinage' : '');
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /** Ciel sans temps présent : couverture la plus forte. @param array<string,mixed> $g */
    private static function skyLabel(array $g): ?string
    {
        if ($g['sky'] !== null) {
            return self::NO_CLOUDS[$g['sky']];
        }
        $cover = self::maxCover($g['layers']);
        return $cover === null ? null : ['FEW' => 'Peu nuageux', 'SCT' => 'Nuageux', 'BKN' => 'Très nuageux', 'OVC' => 'Couvert', 'VV' => 'Ciel invisible'][$cover];
    }

    /** @param array<int,array<string,mixed>> $layers */
    private static function maxCover(array $layers): ?string
    {
        $rank = ['FEW' => 1, 'SCT' => 2, 'BKN' => 3, 'OVC' => 4, 'VV' => 5];
        $best = null;
        foreach ($layers as $l) {
            if ($best === null || $rank[$l['cover']] > $rank[$best]) {
                $best = $l['cover'];
            }
        }
        return $best;
    }

    /** Code de temps de Météo Concept équivalent (pour le pictogramme), ou null. @param array<string,mixed> $g */
    private static function code(array $g): ?int
    {
        $wx = implode(' ', $g['wx']);
        $has = function (string $re) use ($wx): bool {
            return (bool) preg_match($re, $wx);
        };
        if ($has('/TS/')) {
            return 104;
        }
        if ($has('/GR/')) {
            return 235;
        }
        if ($has('/FZ(RA|DZ)/')) {
            return 14;
        }
        if ($has('/(SN|SG|PL|GS)/')) {
            return $has('/RA/') ? 31 : ($has('/SH/') ? 64 : 21);
        }
        if ($has('/SH/')) {
            return $has('/(^|\s)-/') ? 43 : ($has('/\+/') ? 45 : 44);
        }
        if ($has('/RA/')) {
            return $has('/(^|\s)-RA/') ? 10 : ($has('/\+RA/') ? 12 : 11);
        }
        if ($has('/DZ/')) {
            return 16;
        }
        if ($has('/FZFG/')) {
            return 7;
        }
        if ($has('/(FG|BR|HZ)/')) {
            return 6;
        }
        if ($g['sky'] !== null) {
            return 0;
        }
        $cover = self::maxCover($g['layers']);
        return $cover === null ? null : ['FEW' => 1, 'SCT' => 3, 'BKN' => 4, 'OVC' => 5, 'VV' => 5][$cover];
    }

    /**
     * Groupes d'un TAF avec leur validité : prévision de base, puis BECMG, TEMPO, PROB30/40 (TEMPO), FM.
     * Les jours du TAF (JJHH) sont replacés dans le mois de l'émission.
     * @return array{from:?int,to:?int,groups:array<int,array<string,mixed>>}
     */
    public static function parseTaf(string $raw, int $issued): array
    {
        $out = ['from' => null, 'to' => null, 'groups' => []];
        foreach (self::tafLines($raw) as $i => $line) {
            $tokens = explode(' ', $line);
            $type = 'BASE';
            $prob = null;
            if ($i > 0) {
                $first = array_shift($tokens);
                if (preg_match('/^PROB(\d{2})$/', $first, $p)) {
                    $prob = (int) $p[1];
                    $type = ($tokens[0] ?? '') === 'TEMPO' ? 'TEMPO' : 'PROB';
                    if ($type === 'TEMPO') {
                        array_shift($tokens);
                    }
                } elseif (preg_match('/^FM(\d{2})(\d{2})(\d{2})$/', $first, $fm)) {
                    $type = 'FM';
                    $from = self::tafTime((int) $fm[1], (int) $fm[2], (int) $fm[3], $issued);
                } else {
                    $type = $first;
                }
            } else {
                while ($tokens && preg_match('/^(TAF|AMD|COR|[A-Z]{4}|\d{6}Z)$/', $tokens[0]) && !preg_match('/^\d{4}\/\d{4}$/', $tokens[0])) {
                    array_shift($tokens);
                }
            }
            $to = null;
            if ($type !== 'FM') {
                $from = null;
                if ($tokens && preg_match('/^(\d{2})(\d{2})\/(\d{2})(\d{2})$/', $tokens[0], $v)) {
                    array_shift($tokens);
                    $from = self::tafTime((int) $v[1], (int) $v[2], 0, $issued);
                    $to = self::tafTime((int) $v[3], (int) $v[4], 0, $issued);
                }
            }
            if ($type === 'BASE') {
                $out['from'] = $from;
                $out['to'] = $to;
            }
            if ($type === 'PROB') {
                $type = 'TEMPO';
            }
            $out['groups'][] = ['type' => $type, 'prob' => $prob, 'from' => $from, 'to' => $to, 'text' => $line] + self::decodeGroup($tokens);
        }
        // FM : valable jusqu'au FM suivant ou à la fin du TAF.
        $next = $out['to'];
        for ($i = count($out['groups']) - 1; $i > 0; $i--) {
            if ($out['groups'][$i]['type'] === 'FM') {
                $out['groups'][$i]['to'] = $next;
                $next = $out['groups'][$i]['from'];
            }
        }
        return $out;
    }

    /** JJHHMM d'un TAF (UTC, 24 h = minuit suivant) en horodatage, dans le mois de l'émission ou le suivant. */
    private static function tafTime(int $day, int $hour, int $minute, int $issued): ?int
    {
        $ref = new \DateTimeImmutable('@' . $issued);
        $base = $ref->setDate((int) $ref->format('Y'), (int) $ref->format('n'), 1)->setTime(0, 0);
        if ($day < (int) $ref->format('j') - 10) {
            $base = $base->modify('+1 month');
        }
        $ts = $base->getTimestamp() + ($day - 1) * 86400 + $hour * 3600 + $minute * 60;
        return $day >= 1 && $day <= 31 ? $ts : null;
    }

    /**
     * Ce que dit le TAF entre $from et $to : conditions dominantes (base, modifiée par BECMG et FM, vues
     * heure par heure ; on garde la plus mauvaise) et conditions temporaires (TEMPO, PROB) qui chevauchent.
     * null si la période sort de la validité du TAF.
     * @param array{from:?int,to:?int,groups:array<int,array<string,mixed>>} $taf
     * @return array<string,mixed>|null
     */
    public static function tafWindow(array $taf, int $from, int $to): ?array
    {
        if ($taf['from'] === null || $taf['to'] === null || $to <= $taf['from'] || $from >= $taf['to']) {
            return null;
        }
        $from = max($from, $taf['from']);
        $to = min($to, $taf['to']);
        $main = ['ceiling' => null, 'visibility' => null, 'weather' => [], 'code' => null, 'clear' => true];
        for ($t = $from; $t < $to; $t += 3600) {
            $state = null;
            foreach ($taf['groups'] as $g) {
                if ($g['type'] === 'BASE' || ($g['type'] === 'FM' && $g['from'] !== null && $g['from'] <= $t)) {
                    $state = $g;
                } elseif ($g['type'] === 'BECMG' && $state !== null && $g['from'] !== null && $g['from'] <= $t) {
                    foreach ([['has_clouds', ['layers', 'ceiling', 'sky']], ['has_weather', ['weather', 'wx']]] as [$flag, $keys]) {
                        if ($g[$flag]) {
                            foreach ($keys as $k) {
                                $state[$k] = $g[$k];
                            }
                        }
                    }
                    if ($g['visibility'] !== null) {
                        $state['visibility'] = $g['visibility'];
                    }
                    if ($g['wind'] !== null) {
                        $state['wind'] = $g['wind'];
                    }
                    $state['code'] = self::code($state);
                }
            }
            if ($state !== null) {
                $main = self::worst($main, $state);
            }
        }
        $temp = null;
        foreach ($taf['groups'] as $g) {
            if ($g['type'] === 'TEMPO' && $g['from'] !== null && $g['to'] !== null && $g['from'] < $to && $g['to'] > $from) {
                $temp = self::worst($temp ?? ['ceiling' => null, 'visibility' => null, 'weather' => [], 'code' => null, 'clear' => true, 'prob' => null], $g);
                if ($g['prob'] !== null) {
                    $temp['prob'] = max((int) $temp['prob'], $g['prob']);
                }
            }
        }
        unset($main['clear']);
        if ($temp !== null) {
            unset($temp['clear']);
        }
        return ['main' => $main, 'temp' => $temp];
    }

    /** Garde la plus mauvaise de deux situations (plafond et visibilité les plus bas, temps réunis). @param array<string,mixed> $a @param array<string,mixed> $g @return array<string,mixed> */
    private static function worst(array $a, array $g): array
    {
        if ($g['ceiling'] !== null && ($a['ceiling'] === null || $g['ceiling'] < $a['ceiling'])) {
            $a['ceiling'] = $g['ceiling'];
        }
        if ($g['visibility'] !== null && ($a['visibility'] === null || $g['visibility'] < $a['visibility'])) {
            $a['visibility'] = $g['visibility'];
        }
        $a['weather'] = array_values(array_unique(array_merge($a['weather'], $g['weather'])));
        if ($g['code'] !== null && ($a['code'] === null || Weather::severity((int) $g['code']) > Weather::severity((int) $a['code'])
            || (Weather::severity((int) $g['code']) === Weather::severity((int) $a['code']) && $g['code'] > $a['code']))) {
            $a['code'] = $g['code'];
        }
        return $a;
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
