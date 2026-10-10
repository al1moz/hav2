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
        'domotique' => ['Domotique', 'Panneaux translucides sur un fond futuriste, cadres blancs fins et arrondis, accents cyan, orange et corail.'],
        'nuit' => ['Nuit', 'Orange faible luminosité, pour une chambre ou un couloir.'],
        'jarvis' => ['Jarvis', 'Interface tête haute cyan sur fond nuit, grille, lueurs et équerres.'],
        'ironman' => ['Iron Man', 'Rouge et or sur fond sombre, réacteur ARC bleu pour les unités.'],
        'hologramme' => ['Hologramme', 'Verre bleuté translucide, lignes de balayage et léger scintillement.'],
    ];

    /** Couleurs du favicon et de la barre du navigateur mobile pour chaque thème : fond, logo. */
    private const ICON = [
        'clair' => ['#1f7a5c', '#ffffff'],
        'sombre' => ['#19201d', '#5cc79f'],
        'encre' => ['#ecebe6', '#111111'],
        'domotique' => ['#0c1013', '#3cc4e6'],
        'nuit' => ['#140b08', '#ff9b5c'],
        'jarvis' => ['#030a12', '#00d4ff'],
        'ironman' => ['#8b0f14', '#f2b630'],
        'hologramme' => ['#0c1130', '#7cc7ff'],
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

    /**
     * Logo : maison connectée (toit, murs, éclair, ondes au-dessus du toit), tracé dans un carré de 32.
     * Sert dans la barre de navigation (couleur du texte, currentColor) et pour le favicon.
     */
    public static function logo(string $color = 'currentColor'): string
    {
        return "<g fill='none' stroke='$color' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'>"
            . "<path d='M4 18.5 16 9l12 9.5'/><path d='M7.5 16v12.5h17V16'/>"
            . "<path d='M11.2 5.6a7 7 0 0 1 9.6 0'/><path d='M13.6 8a3.6 3.6 0 0 1 4.8 0' stroke-width='2'/></g>"
            . "<path d='M17.4 15.2 12.6 22h3.2l-1.1 4.6 4.9-6.9h-3.2z' fill='$color'/>";
    }

    /** Favicon SVG (le logo) aux couleurs du thème, et couleur de la barre du navigateur sur mobile. */
    public static function icon(string $theme): string
    {
        [$bg, $fg] = self::ICON[$theme] ?? self::ICON['sombre'];
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='$bg'/>" . self::logo($fg) . '</svg>';
        return '<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,' . rawurlencode($svg) . '">'
            . '<meta name="theme-color" content="' . $bg . '">';
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
            // Sur smartphone, les liens se replient derrière le bouton « burger » (public/assets/app.js).
            $nav = '<nav aria-label="Pages"><a class="brand" href="/" title="' . $site . '" aria-label="' . $site . ', page Aujourd\'hui">'
                . '<svg viewBox="0 0 32 32" aria-hidden="true">' . self::logo() . '</svg><span class="brand-name" aria-hidden="true">' . $site . '</span></a>'
                . '<button type="button" class="burger" aria-controls="navlinks" aria-expanded="false" aria-label="Menu"><span></span><span></span><span></span></button>'
                . '<div class="navlinks" id="navlinks">' . $links
                . '<form method="post" action="/deconnexion" class="logout"><input type="hidden" name="csrf" value="' . self::h(Session::csrf()) . '">'
                . '<button type="submit">Se déconnecter</button></form></div></nav>';
        }
        $v = self::assetVersion();
        $chat = $withNav && Chat::available() ? self::chat() : '';
        echo '<!doctype html><html lang="fr" data-theme="' . self::theme() . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::h($title) . ' · ' . $site . '</title>'
            . self::icon(self::theme())
            . '<link rel="stylesheet" href="/assets/app.css?v=' . $v . '">'
            . '</head><body' . ($chat ? ' class="has-chat"' : '') . ' data-page="' . self::h(trim($path, '/') ?: 'accueil') . '" data-tz="' . self::h(Config::timezone()->getName()) . '">'
            . '<div class="shell">' . $nav . '<main>' . $body . '</main></div>'
            . '<div class="tip" id="tip" hidden></div>' . $chat
            . '<script src="/assets/charts.js?v=' . $v . '"></script><script src="/assets/app.js?v=' . $v . '"></script>'
            . ($chat ? '<script src="/assets/chat.js?v=' . $v . '"></script>' : '')
            . '</body></html>';
    }

    /** Bouton et panneau « Demander à Claude » (public/assets/chat.js). */
    private static function chat(): string
    {
        return '<button type="button" class="chat-open" id="chat-open" aria-controls="chat" aria-expanded="false">Demander à Claude</button>'
            . '<section class="chat" id="chat" hidden aria-label="Demander à Claude" data-csrf="' . self::h(Session::csrf()) . '">'
            . '<header><h2>Demander à Claude</h2><button type="button" class="link" id="chat-new">Nouvelle discussion</button>'
            . '<button type="button" class="x" id="chat-close" aria-label="Fermer">×</button></header>'
            . '<div class="chat-log" id="chat-log" aria-live="polite"></div>'
            . '<form id="chat-form"><textarea id="chat-q" rows="2" maxlength="2000" placeholder="Ta question…" aria-label="Question"></textarea>'
            . '<button type="submit">Envoyer</button></form>'
            . '<p class="chat-note">Claude lit les données du site pour répondre. Vérifie les chiffres importants.</p></section>';
    }

    public static function redirect(string $to): void
    {
        header('Location: ' . $to, true, 303);
    }

    public static function assetVersion(): string
    {
        $t = 0;
        foreach (['app.css', 'app.js', 'charts.js', 'chat.js', 'tablet.css', 'tablet.js'] as $f) {
            $t = max($t, (int) @filemtime(APP_ROOT . '/public/assets/' . $f));
        }
        return (string) $t;
    }
}
