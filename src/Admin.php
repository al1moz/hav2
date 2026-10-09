<?php
declare(strict_types=1);

namespace Conso;

/** Page Administration : réglages, thème, prix, mesures, jetons, mot de passe. */
final class Admin
{
    /** @param array<string,mixed> $post @return string message à afficher */
    public static function handle(array $post): string
    {
        $action = (string) ($post['action'] ?? '');
        switch ($action) {
            case 'general':
                $name = trim((string) ($post['site_name'] ?? ''));
                if ($name === '' || mb_strlen($name) > 60) {
                    return 'Nom du site : 1 à 60 caractères.';
                }
                $stale = (int) ($post['stale_after_minutes'] ?? 0);
                $ecs = (int) ($post['ecs_threshold_w'] ?? 0);
                $days = (int) ($post['ecs_alert_days'] ?? 0);
                if ($stale < 5 || $stale > 1440 || $ecs < 50 || $ecs > 5000 || $days < 1 || $days > 90) {
                    return 'Valeur hors limites (absence de données : 5 à 1440 min, seuil ECS : 50 à 5000 W, alerte ECS : 1 à 90 jours).';
                }
                self::set(['site_name' => $name, 'stale_after_minutes' => (string) $stale, 'ecs_threshold_w' => (string) $ecs, 'ecs_alert_days' => (string) $days]);
                return 'Réglages enregistrés.';

            case 'theme':
                $theme = (string) ($post['theme_site'] ?? '');
                if (!isset(View::THEMES[$theme])) {
                    return 'Thème inconnu.';
                }
                self::set(['theme_site' => $theme]);
                return 'Thème « ' . View::THEMES[$theme][0] . ' » appliqué.';

            case 'tablet':
                $theme = (string) ($post['theme_tablet'] ?? '');
                $background = trim((string) ($post['tablet_background'] ?? ''));
                $icao = strtoupper(trim((string) ($post['tablet_icao'] ?? '')));
                $runways = trim((string) ($post['tablet_runways'] ?? ''));
                if (!isset(View::THEMES[$theme])) {
                    return 'Thème inconnu.';
                }
                if ($background !== '' && !Tablet::validImageUrl($background)) {
                    return 'Image de fond : une adresse http:// ou https:// complète, sans espace ni guillemet.';
                }
                if ($icao !== '' && !preg_match('/^[A-Z0-9]{4}$/', $icao)) {
                    return 'Aérodrome : code OACI de 4 caractères, ou vide pour ne pas afficher le METAR.';
                }
                if (mb_strlen($runways) > 40 || ($runways !== '' && !Metar::runways($runways))) {
                    return 'Pistes : orientations en degrés séparées par des virgules (ex. 070, 250).';
                }
                self::set([
                    'theme_tablet' => $theme,
                    'tablet_night' => isset($post['tablet_night']) ? '1' : '0',
                    'tablet_background' => $background,
                    'tablet_icao' => $icao,
                    'tablet_runways' => $runways,
                ]);
                return 'Réglages de la tablette enregistrés.';

            case 'price_add':
                $from = (string) ($post['valid_from'] ?? '');
                $price = str_replace(',', '.', (string) ($post['kwh_price'] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !is_numeric($price) || (float) $price <= 0 || (float) $price > 5) {
                    return 'Prix : date AAAA-MM-JJ et prix du kWh en euros (ex. 0,2516).';
                }
                Db::run('INSERT INTO price (valid_from, kwh_price) VALUES (?, ?) ON DUPLICATE KEY UPDATE kwh_price = VALUES(kwh_price)', [$from, $price]);
                return 'Prix enregistré.';

            case 'price_delete':
                Db::run('DELETE FROM price WHERE valid_from = ?', [(string) ($post['valid_from'] ?? '')]);
                return 'Prix supprimé.';

            case 'metrics':
                $labels = (array) ($post['label'] ?? []);
                $sorts = (array) ($post['sort'] ?? []);
                $visible = (array) ($post['visible'] ?? []);
                $stmt = Db::pdo()->prepare('UPDATE metric SET label = ?, sort = ?, visible = ? WHERE id = ?');
                foreach ($labels as $id => $label) {
                    $label = trim((string) $label);
                    if ($label === '' || mb_strlen($label) > 128) {
                        continue;
                    }
                    $stmt->execute([$label, (int) ($sorts[$id] ?? 0), isset($visible[$id]) ? 1 : 0, (int) $id]);
                }
                return 'Mesures enregistrées.';

            case 'token_create':
                $name = trim((string) ($post['name'] ?? ''));
                $scopes = array_values(array_intersect(Auth::SCOPES, (array) ($post['scopes'] ?? [])));
                if ($name === '' || mb_strlen($name) > 64 || !$scopes) {
                    return 'Jeton : un nom et au moins une portée.';
                }
                $token = Auth::createToken($name, $scopes);
                $message = "Jeton « $name » créé. Copie-le maintenant, il ne sera plus affiché :\n$token";
                if (in_array('tablet', $scopes, true)) {
                    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
                    $message .= "\n\nSur la tablette, ouvre une fois cette adresse (elle garde ensuite le jeton) :\n"
                        . (Session::isHttps() ? 'https' : 'http') . '://' . $host . Tablet::path() . '?jeton=' . $token;
                }
                return $message;

            case 'token_revoke':
                Db::run('UPDATE api_token SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND revoked_at IS NULL', [(int) ($post['id'] ?? 0)]);
                return 'Jeton révoqué.';

            case 'password':
                $current = (string) ($post['current'] ?? '');
                $new = (string) ($post['new'] ?? '');
                if (!password_verify($current, Settings::get('password_hash'))) {
                    return 'Mot de passe actuel incorrect.';
                }
                if (strlen($new) < 12 || $new !== (string) ($post['confirm'] ?? '')) {
                    return 'Nouveau mot de passe : 12 caractères au moins, saisi deux fois à l\'identique.';
                }
                self::set(['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
                return 'Mot de passe changé.';
        }
        return 'Action inconnue.';
    }

    public static function render(?string $flash): string
    {
        $h = [View::class, 'h'];
        $csrf = '<input type="hidden" name="csrf" value="' . $h(Session::csrf()) . '">';
        $form = function (string $action, string $inner, string $class = '') use ($csrf): string {
            return '<form method="post" action="/admin"' . ($class ? ' class="' . $class . '"' : '') . '>' . $csrf
                . '<input type="hidden" name="action" value="' . $action . '">' . $inner . '</form>';
        };

        $out = '<h1>Administration</h1>';
        if ($flash !== null) {
            $out .= '<div class="flash" role="status"><pre>' . $h($flash) . '</pre></div>';
        }

        // Réglages généraux
        $out .= '<section class="panel"><h2>Réglages</h2>' . $form('general',
            '<div class="fields">'
            . self::field('site_name', 'Nom affiché du site', Settings::get('site_name', 'Maison'), 'text', 'Aucun nom de lieu : le dépôt peut rester public.')
            . self::field('stale_after_minutes', 'Alerte « plus de données » après (minutes)', Settings::get('stale_after_minutes', '60'), 'number')
            . self::field('ecs_threshold_w', 'Seuil d\'activation de l\'appoint ECS (W)', Settings::get('ecs_threshold_w', '500'), 'number', 'Au repos le Shelly mesure environ 4 W.')
            . self::field('ecs_alert_days', 'Bandeau d\'alerte ECS pendant (jours)', Settings::get('ecs_alert_days', '7'), 'number')
            . '</div><button type="submit">Enregistrer</button>') . '</section>';

        // Thème
        $current = View::theme();
        $opts = '';
        foreach (View::THEMES as $key => [$label, $desc]) {
            $opts .= '<label class="opt"><input type="radio" name="theme_site" value="' . $key . '"' . ($key === $current ? ' checked' : '') . '>'
                . '<span>' . $h($label) . '</span><small>' . $h($desc) . '</small></label>';
        }
        $out .= '<section class="panel"><h2>Thème du site</h2>' . $form('theme', '<div class="opts">' . $opts . '</div><button type="submit">Appliquer</button>') . '</section>';

        // Tablette
        $tabletTheme = Settings::get('theme_tablet', 'sombre');
        $opts = '';
        foreach (View::THEMES as $key => [$label, $desc]) {
            $opts .= '<label class="opt"><input type="radio" name="theme_tablet" value="' . $key . '"' . ($key === $tabletTheme ? ' checked' : '') . '>'
                . '<span>' . $h($label) . '</span><small>' . $h($desc) . '</small></label>';
        }
        $out .= '<section class="panel"><h2>Tablette</h2><p class="note">La page <a href="' . $h(Tablet::path()) . '">' . $h(Tablet::path()) . '</a> : horloge, sondes Netatmo, METAR et webcam en fond. '
            . 'La tablette s\'ouvre avec un jeton de portée « tablet » (section Jetons d\'API), sans donner accès au reste du site.</p>'
            . $form('tablet', '<div class="opts">' . $opts . '</div>'
                . '<label class="check"><input type="checkbox" name="tablet_night"' . (Settings::get('tablet_night', '1') === '1' ? ' checked' : '') . '> Thème Nuit de 22 h à 7 h</label>'
                . '<div class="fields">'
                . self::field('tablet_background', 'Image de fond (webcam)', Settings::get('tablet_background'), 'text', 'Rechargée toutes les 5 minutes, visible avec les thèmes Clair et Sombre. Une adresse https, sinon la tablette la bloque.')
                . self::field('tablet_icao', 'Aérodrome du METAR (code OACI)', Settings::get('tablet_icao'), 'text', 'Vide : pas de METAR.')
                . self::field('tablet_runways', 'Orientation des pistes (degrés)', Settings::get('tablet_runways'), 'text', 'Ex. 070, 250. Sert à colorer le vent selon la piste la plus favorable.')
                . '</div><button type="submit">Enregistrer</button>')
            . '</section>';

        // Prix
        $rows = '';
        foreach (Db::all('SELECT valid_from, kwh_price FROM price ORDER BY valid_from DESC') as $p) {
            $rows .= '<tr><td>' . $h($p['valid_from']) . '</td><td>' . $h(rtrim(rtrim(number_format((float) $p['kwh_price'], 5, ',', ''), '0'), ',')) . ' €</td><td>'
                . $form('price_delete', '<input type="hidden" name="valid_from" value="' . $h($p['valid_from']) . '"><button type="submit" class="link">Supprimer</button>', 'inline')
                . '</td></tr>';
        }
        $out .= '<section class="panel"><h2>Prix du kWh (tarif Base)</h2><p class="note">Chaque jour est chiffré au prix en vigueur à cette date.</p>'
            . '<div class="tbl"><table><tr><th>À partir du</th><th>Prix TTC</th><th></th></tr>' . $rows . '</table></div>'
            . $form('price_add', '<div class="fields">' . self::field('valid_from', 'À partir du', '', 'date') . self::field('kwh_price', 'Prix du kWh (€)', '', 'text') . '</div><button type="submit">Ajouter</button>')
            . '</section>';

        // Mesures
        $rows = '';
        foreach (Metrics::all() as $m) {
            $id = (int) $m['id'];
            $rows .= '<tr><td><code>' . $h($m['code']) . '</code></td><td>' . $h($m['source']) . '</td><td>' . $h($m['unit']) . '</td>'
                . '<td><input name="label[' . $id . ']" value="' . $h($m['label']) . '" aria-label="Libellé de ' . $h($m['code']) . '"></td>'
                . '<td><input name="sort[' . $id . ']" type="number" value="' . (int) $m['sort'] . '" class="num" aria-label="Ordre"></td>'
                . '<td><input name="visible[' . $id . ']" type="checkbox"' . ($m['visible'] ? ' checked' : '') . ' aria-label="Visible"></td></tr>';
        }
        $out .= '<section class="panel"><h2>Mesures</h2><p class="note">Libellés et ordre d\'affichage. Une mesure masquée n\'apparaît plus dans les graphiques par circuit.</p>'
            . $form('metrics', '<div class="tbl"><table><tr><th>Code</th><th>Source</th><th>Unité</th><th>Libellé</th><th>Ordre</th><th>Visible</th></tr>' . $rows . '</table></div><button type="submit">Enregistrer</button>')
            . '</section>';

        // Jetons
        $rows = '';
        foreach (Db::all('SELECT id, name, scopes, created_at, last_used_at, revoked_at FROM api_token ORDER BY revoked_at IS NOT NULL, id DESC') as $t) {
            $rows .= '<tr' . ($t['revoked_at'] ? ' class="muted"' : '') . '><td>' . $h($t['name']) . '</td><td>' . $h($t['scopes']) . '</td><td>' . $h(substr($t['created_at'], 0, 10)) . '</td>'
                . '<td>' . $h($t['last_used_at'] ? substr($t['last_used_at'], 0, 16) . ' UTC' : 'jamais') . '</td><td>'
                . ($t['revoked_at'] ? 'révoqué' : $form('token_revoke', '<input type="hidden" name="id" value="' . (int) $t['id'] . '"><button type="submit" class="link">Révoquer</button>', 'inline'))
                . '</td></tr>';
        }
        $scopes = '';
        foreach (['ingest' => 'envoi des mesures (add-on)', 'read' => 'lecture des données', 'tablet' => 'page tablette'] as $s => $label) {
            $scopes .= '<label class="check"><input type="checkbox" name="scopes[]" value="' . $s . '"> ' . $h($s) . ' <small>' . $h($label) . '</small></label>';
        }
        $out .= '<section class="panel"><h2>Jetons d\'API</h2>'
            . '<div class="tbl"><table><tr><th>Nom</th><th>Portées</th><th>Créé</th><th>Dernier usage</th><th></th></tr>' . $rows . '</table></div>'
            . $form('token_create', '<div class="fields">' . self::field('name', 'Nom du nouveau jeton', '', 'text') . '<fieldset><legend>Portées</legend>' . $scopes . '</fieldset></div><button type="submit">Créer</button>')
            . '</section>';

        // Mot de passe
        $out .= '<section class="panel"><h2>Mot de passe du site</h2>' . $form('password', '<div class="fields">'
            . self::field('current', 'Mot de passe actuel', '', 'password', '', 'current-password')
            . self::field('new', 'Nouveau (12 caractères au moins)', '', 'password', '', 'new-password')
            . self::field('confirm', 'Encore une fois', '', 'password', '', 'new-password')
            . '</div><button type="submit">Changer</button>') . '</section>';

        return $out;
    }

    private static function field(string $name, string $label, string $value, string $type, string $hint = '', string $autocomplete = ''): string
    {
        $id = 'f-' . $name;
        return '<div class="field"><label for="' . $id . '">' . View::h($label) . '</label>'
            . '<input id="' . $id . '" name="' . $name . '" type="' . $type . '" value="' . View::h($value) . '"'
            . ($type === 'number' ? ' inputmode="numeric"' : '') . ($autocomplete ? ' autocomplete="' . $autocomplete . '"' : '') . '>'
            . ($hint ? '<small>' . View::h($hint) . '</small>' : '') . '</div>';
    }

    /** @param array<string,string> $values */
    private static function set(array $values): void
    {
        $stmt = Db::pdo()->prepare('INSERT INTO setting (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        foreach ($values as $name => $value) {
            $stmt->execute([$name, $value]);
        }
    }
}
