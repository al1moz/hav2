<?php
declare(strict_types=1);

namespace Conso;

/** Gabarit commun des pages : en-tête, navigation, thème. */
final class View
{
    public const THEMES = [
        'clair' => ['Clair', 'Fond clair, couleurs douces. Pour le jour.'],
        'sombre' => ['Sombre', 'Fond sombre, moins éblouissant le soir.'],
        'encre' => ['Encre électronique', 'Noir et blanc pur, sans animation, motifs au lieu des couleurs.'],
        'lcd' => ['LCD', 'Afficheur vert rétro, gros chiffres.'],
        'nuit' => ['Nuit', 'Orange faible luminosité, pour une chambre ou un couloir.'],
        'contraste' => ['Contraste élevé', 'Blanc sur noir, jaune pour l\'accent. Lisible de loin.'],
    ];

    private const NAV = [
        '/' => 'Aujourd\'hui',
        '/electricite' => 'Électricité',
        '/chauffage' => 'Chauffage',
        '/temperatures' => 'Températures',
        '/humidite' => 'Humidité',
        '/comparer' => 'Comparer',
        '/admin' => 'Administration',
    ];

    public static function h(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function theme(): string
    {
        $theme = Settings::get('theme_site', 'clair');
        return isset(self::THEMES[$theme]) ? $theme : 'clair';
    }

    /** Page complète. $body est du HTML déjà échappé. */
    public static function page(string $path, string $title, string $body, bool $withNav = true): void
    {
        $site = self::h(Settings::get('site_name', 'Maison'));
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        $nav = '';
        if ($withNav) {
            $links = '';
            foreach (self::NAV as $href => $label) {
                $current = $href === $path ? ' aria-current="page"' : '';
                $links .= '<a href="' . $href . '"' . $current . '>' . self::h($label) . '</a>';
            }
            $nav = '<nav aria-label="Pages"><span class="brand">' . $site . '</span>' . $links
                . '<form method="post" action="/deconnexion" class="logout"><input type="hidden" name="csrf" value="' . self::h(Session::csrf()) . '">'
                . '<button type="submit">Se déconnecter</button></form></nav>';
        }
        $v = self::assetVersion();
        echo '<!doctype html><html lang="fr" data-theme="' . self::theme() . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::h($title) . ' · ' . $site . '</title>'
            . '<link rel="stylesheet" href="/assets/app.css?v=' . $v . '">'
            . '</head><body data-page="' . self::h(trim($path, '/') ?: 'accueil') . '" data-tz="' . self::h(Config::timezone()->getName()) . '">'
            . '<div class="shell">' . $nav . '<main>' . $body . '</main></div>'
            . '<div class="tip" id="tip" hidden></div>'
            . '<script src="/assets/charts.js?v=' . $v . '"></script><script src="/assets/app.js?v=' . $v . '"></script>'
            . '</body></html>';
    }

    public static function redirect(string $to): void
    {
        header('Location: ' . $to, true, 303);
    }

    private static function assetVersion(): string
    {
        $t = 0;
        foreach (['app.css', 'app.js', 'charts.js'] as $f) {
            $t = max($t, (int) @filemtime(APP_ROOT . '/public/assets/' . $f));
        }
        return (string) $t;
    }
}
