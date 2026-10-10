<?php
declare(strict_types=1);

namespace Conso;

/**
 * Page tablette (reprise de l'ancienne page « pi ») : horloge, sondes Netatmo,
 * METAR de l'aérodrome et webcam en fond. L'ancienne adresse (TABLET_LEGACY_PATH) est
 * publique ; /tablette s'ouvre par la session du site ou par un jeton de portée « tablet » :
 * passé une fois dans l'adresse (?jeton=…), il est gardé dans un cookie qui n'ouvre que cette page.
 */
final class Tablet
{
    private const COOKIE = 'consov2_tablette';
    private const COOKIE_DAYS = 400;
    private const METAR_STALE_S = 5400;

    /** Ordre d'affichage de la colonne de gauche : code => [libellé, unité, classe]. */
    private const TILES = [
        'humidity_outdoor' => ['Humidité ext.', '%', ''],
        'pressure_outdoor' => ['Pression', 'hPa', 'small pressure'],
        'temp_living' => ['Salon', '°C', ''],
        'temp_outdoor' => ['Extérieur', '°C', ''],
        'humidity_living' => ['Humidité', '%', ''],
    ];

    private const DAYS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /**
     * Adresses de la page : /tablette et, si TABLET_LEGACY_PATH est défini dans .env,
     * l'ancienne adresse déjà réglée sur la tablette (hors dépôt : elle peut nommer un lieu).
     * @return string[]
     */
    public static function paths(): array
    {
        $legacy = self::legacyPath();
        return $legacy === null ? ['/tablette'] : ['/tablette', $legacy];
    }

    /** Ancienne adresse (TABLET_LEGACY_PATH), ouverte sans jeton à la demande d'Alain : la page n'a rien de confidentiel. */
    private static function legacyPath(): ?string
    {
        $legacy = trim((string) Config::get('TABLET_LEGACY_PATH', ''), '/');
        if ($legacy === '' || !preg_match('#^[A-Za-z0-9._/-]+$#', $legacy) || strpos($legacy, '..') !== false) {
            return null;
        }
        return '/' . $legacy;
    }

    /** Adresse à ouvrir sur la tablette (l'ancienne si elle est définie). */
    public static function path(): string
    {
        $paths = self::paths();
        return end($paths);
    }

    public static function page(): void
    {
        $path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        $given = Http::query('jeton');
        if ($given !== null) {
            if (Auth::checkToken($given, 'tablet') === null) {
                self::denied('Ce jeton est inconnu, révoqué, ou n\'a pas la portée « tablet ».');
                return;
            }
            self::keep($given);
            View::redirect($path);
            return;
        }
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        if ($path === self::legacyPath()) {
            // Adresse publique : ni jeton ni session (Home Assistant l'affiche dans un iframe).
        } elseif (is_string($cookie) && $cookie !== '' && Auth::checkToken($cookie, 'tablet') !== null) {
            self::keep($cookie);
        } elseif (!Session::loggedIn()) {
            self::denied('Cette tablette n\'a pas encore de jeton.');
            return;
        }

        if (Http::query('partiel') !== null) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            echo self::panel();
            return;
        }

        $theme = self::theme();
        $bg = self::background();
        $origin = $bg === '' ? '' : ' ' . parse_url($bg, PHP_URL_SCHEME) . '://' . parse_url($bg, PHP_URL_HOST) . (parse_url($bg, PHP_URL_PORT) ? ':' . parse_url($bg, PHP_URL_PORT) : '');
        $v = View::assetVersion();
        $now = new \DateTime('now', Config::timezone());
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        // L'adresse publique peut s'afficher dans Home Assistant (iframe) : frame-ancestors l'emporte sur X-Frame-Options de nginx.
        $frames = $path === self::legacyPath() ? '*' : "'none'";
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:" . $origin . "; style-src 'self' 'unsafe-inline'; frame-ancestors " . $frames . "; base-uri 'none'; form-action 'self'");
        echo '<!doctype html><html lang="fr" data-theme="' . $theme . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes">'
            . '<title>' . View::h(Settings::get('site_name', 'Maison')) . '</title>'
            . '<link rel="stylesheet" href="/assets/tablet.css?v=' . $v . '">'
            . '<noscript><meta http-equiv="refresh" content="300"></noscript>'
            . '</head><body' . ($bg === '' ? '' : ' style="background-image:url(\'' . View::h($bg) . '\')"') . '>'
            . self::panel()
            . '<div class="clock"><div class="time" id="time">' . $now->format('H:i') . '</div>'
            . '<div class="date" id="date">' . self::frenchDate($now) . '</div>'
            . '<div class="offline" id="offline" hidden></div></div>'
            . '<script src="/assets/tablet.js?v=' . $v . '"></script>'
            . '</body></html>';
    }

    /** Tout ce qui change avec les données, rechargé chaque minute par tablet.js. */
    private static function panel(): string
    {
        $staleAfter = Settings::int('stale_after_minutes', 60) * 60;
        $now = time();
        $latest = [];
        $codes = array_keys(self::TILES);
        $rows = Db::all(
            'SELECT m.code, l.ts, l.value FROM sample_latest l JOIN metric m ON m.id = l.metric_id
              WHERE m.code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')',
            $codes
        );
        foreach ($rows as $r) {
            $latest[$r['code']] = ['value' => (float) $r['value'], 'ts' => Time::fromDb($r['ts'])];
        }

        $left = '';
        foreach (self::TILES as $code => [$label, $unit, $class]) {
            if (!isset($latest[$code])) {
                continue;
            }
            $age = $now - $latest[$code]['ts'];
            $stale = $age > $staleAfter;
            $left .= '<div class="' . trim('box ' . $class . ($stale ? ' stale' : '')) . '"><div class="lbl">' . View::h($label) . '</div>'
                . '<div class="val">' . self::number($latest[$code]['value']) . '<small> ' . $unit . '</small></div>'
                . ($stale ? '<div class="age">' . self::since($latest[$code]['ts']) . '</div>' : '') . '</div>';
        }

        $metar = '';
        $right = '';
        $icao = strtoupper(trim(Settings::get('tablet_icao')));
        if ($icao !== '') {
            $m = Metar::latest($icao);
            if ($m === null) {
                $metar = '<div class="box metar stale">METAR ' . View::h($icao) . ' indisponible pour le moment.</div>';
            } else {
                $d = Metar::describe($m, Metar::runways(Settings::get('tablet_runways')));
                $old = $d['obs'] !== null && $now - $d['obs'] > self::METAR_STALE_S ? ' stale' : '';
                $cat = self::categoryLevel($d['category']);
                // De nuit aéronautique, le vol VFR de jour n'est plus possible : « NUIT » remplace VFR et MVFR.
                $condition = $d['category'];
                $night = Metar::night($m, $now);
                if ($night !== null && $night['night'] && ($cat === 'ok' || $cat === 'warn')) {
                    $condition = 'NUIT';
                    $cat = 'warn';
                }
                $metar = '<div class="box metar' . $old . '">'
                    . ($d['clouds'] ? '<div class="clouds">' . implode('<br>', array_map([View::class, 'h'], $d['clouds'])) . '</div>' : '')
                    . '<div class="raw ' . $cat . '">' . View::h($d['raw']) . '</div></div>';
                $w = $d['wind'];
                if ($w !== null) {
                    $lvl = (string) $w['level'];
                    $notes = [];
                    if ($w['gust'] !== null) {
                        $notes[] = 'rafales ' . $w['gust'] . ' kt';
                    }
                    if ($w['cross'] !== null) {
                        $notes[] = 'travers ' . $w['cross'] . ' kt';
                    }
                    if ($lvl === 'warn' || $lvl === 'bad') {
                        $notes[] = $lvl === 'bad' ? 'hors limites' : 'limite pilote';
                    }
                    $right .= '<div class="box wind' . $old . '"><div class="lbl">Vent</div>'
                        . '<div class="val ' . $lvl . '">' . $w['dir'] . ($w['dir'] === 'VRB' ? '' : '°') . '</div>'
                        . '<div class="val2 ' . $lvl . '">' . $w['speed'] . ' kt</div>'
                        . ($notes ? '<div class="note ' . $lvl . '">' . View::h(implode(' · ', $notes)) . '</div>' : '') . '</div>';
                }
                if ($condition !== null) {
                    $change = $night === null ? '' : '<div class="note">' . ($night['night'] ? 'jour à ' : 'nuit à ') . self::clock($night['until']) . '</div>';
                    $right .= '<div class="box' . $old . '"><div class="lbl">Condition</div><div class="val ' . $cat . '">' . View::h($condition) . '</div>' . $change . '</div>';
                }
                if ($d['qnh'] !== null) {
                    $right .= '<div class="box' . $old . '"><div class="lbl">QNH</div><div class="val p">' . $d['qnh'] . '<small> hPa</small></div></div>';
                }
            }
        }

        return '<div id="panel" data-theme="' . self::theme() . '" data-v="' . View::assetVersion() . '" data-bg="' . View::h(self::background()) . '">'
            . ($left === '' ? '' : '<div class="col left">' . $left . '</div>')
            . $metar
            . ($right === '' ? '' : '<div class="col right">' . $right . '</div>')
            . '</div>';
    }

    /** Thème de la tablette, remplacé par « Nuit » de 22 h à 7 h si l'option est cochée. */
    private static function theme(): string
    {
        $theme = Settings::get('theme_tablet', 'sombre');
        if (!isset(View::THEMES[$theme])) {
            $theme = 'sombre';
        }
        if (Settings::get('tablet_night', '1') === '1') {
            $hour = (int) (new \DateTime('now', Config::timezone()))->format('G');
            if ($hour >= 22 || $hour < 7) {
                $theme = 'nuit';
            }
        }
        return $theme;
    }

    /** Adresse de l'image de fond (webcam), vide si absente ou invalide. */
    private static function background(): string
    {
        $url = trim(Settings::get('tablet_background'));
        return self::validImageUrl($url) ? $url : '';
    }

    public static function validImageUrl(string $url): bool
    {
        return strlen($url) <= 500 && (bool) preg_match('#^https?://[^\s"\'()<>\\\\]+$#', $url) && parse_url($url, PHP_URL_HOST);
    }

    private static function categoryLevel(?string $category): string
    {
        switch ($category) {
            case 'VFR':
                return 'ok';
            case 'MVFR':
                return 'warn';
            case 'IFR':
            case 'LIFR':
                return 'bad';
        }
        return '';
    }

    private static function number(float $value): string
    {
        $n = (int) round($value);
        return $n < 0 ? '−' . abs($n) : (string) $n;
    }

    private static function clock(int $ts): string
    {
        return (new \DateTime('@' . $ts))->setTimezone(Config::timezone())->format('H:i');
    }

    private static function since(int $ts): string
    {
        $local = (new \DateTime('@' . $ts))->setTimezone(Config::timezone());
        return time() - $ts < 86400 ? 'à ' . $local->format('H:i') : 'le ' . $local->format('d/m');
    }

    public static function frenchDate(\DateTime $d): string
    {
        return self::DAYS[(int) $d->format('w')] . ' ' . $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n') - 1];
    }

    private static function keep(string $token): void
    {
        setcookie(self::COOKIE, $token, [
            'expires' => time() + self::COOKIE_DAYS * 86400,
            'path' => '/',
            'secure' => Session::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function denied(string $reason): void
    {
        http_response_code(401);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<!doctype html><html lang="fr" data-theme="sombre"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Tablette</title>'
            . '<link rel="stylesheet" href="/assets/tablet.css?v=' . View::assetVersion() . '"></head><body>'
            . '<div class="denied"><h1>Page tablette</h1><p>' . View::h($reason) . '</p>'
            . '<p>Dans l\'administration du site, crée un jeton avec la portée « tablet », puis ouvre une fois sur la tablette l\'adresse affichée avec le jeton. La tablette le garde ensuite.</p>'
            . '<p><a href="/connexion">Se connecter au site</a></p></div></body></html>';
    }
}
