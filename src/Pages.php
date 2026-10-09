<?php
declare(strict_types=1);

namespace Conso;

/**
 * Pages du site. Le HTML ne contient que la structure : les graphiques et les
 * chiffres sont remplis par public/assets/app.js à partir de l'API JSON.
 */
final class Pages
{
    /** Toutes les pages exigent la connexion (site entièrement privé). */
    private static function guard(): bool
    {
        if (Session::loggedIn()) {
            return true;
        }
        View::redirect('/connexion');
        return false;
    }

    public static function login(): void
    {
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $error = Session::login((string) ($_POST['password'] ?? ''));
            if ($error === null) {
                View::redirect('/');
                return;
            }
            http_response_code(401);
        } elseif (Session::loggedIn()) {
            View::redirect('/');
            return;
        }
        $msg = $error === null ? '' : '<p class="form-error" role="alert">' . View::h($error) . '</p>';
        View::page('/connexion', 'Connexion', '<section class="login-box"><h1>' . View::h(Settings::get('site_name', 'Maison')) . '</h1>'
            . '<form method="post" action="/connexion">' . $msg
            . '<label for="pw">Mot de passe</label><input id="pw" name="password" type="password" autocomplete="current-password" required autofocus>'
            . '<button type="submit">Se connecter</button></form></section>', false);
    }

    public static function logout(): void
    {
        if (Session::loggedIn() && Session::checkCsrf()) {
            Session::logout();
        }
        View::redirect('/connexion');
    }

    public static function home(): void
    {
        if (!self::guard()) {
            return;
        }
        View::page('/', 'Aujourd\'hui', <<<'HTML'
<h1>Aujourd'hui</h1>
<p class="sub" id="home-sub">Chargement…</p>
<div id="alerts"></div>
<div class="tiles" id="home-tiles"></div>
<div class="chartbox"><h3>Puissance des dernières 24 heures</h3><p class="cap">kW, moyenne sur 5 minutes</p><div id="c-day"></div></div>
HTML
        );
    }

    public static function electricity(): void
    {
        if (!self::guard()) {
            return;
        }
        View::page('/electricite', 'Électricité', <<<'HTML'
<h1>Électricité</h1>
<p class="sub">Consommation détaillée par circuit. Le reste correspond à ce que les Shelly ne mesurent pas.</p>
<div class="chartbox">
  <h3>Consommation</h3><p class="cap" id="elec-cap">kWh</p>
  <div class="controls" role="group" aria-label="Période" id="elec-ctl">
    <button type="button" data-p="7">7 jours</button><button type="button" data-p="30" aria-pressed="true">30 jours</button>
    <button type="button" data-p="12m">12 mois</button><button type="button" data-p="y">Années</button>
  </div>
  <div id="c-elec"></div><div class="legend" id="lg-elec"></div>
  <details><summary>Voir le tableau</summary><div class="tbl" id="t-elec"></div></details>
</div>
<div class="two">
  <div class="chartbox"><h3>Répartition</h3><p class="cap" id="rep-cap">kWh par circuit</p><div id="c-rep"></div></div>
  <div class="chartbox"><h3>Profil horaire moyen</h3><p class="cap">kW, moyenne par heure de la journée, 30 derniers jours</p><div id="c-prof"></div></div>
</div>
HTML
        );
    }

    public static function heating(): void
    {
        if (!self::guard()) {
            return;
        }
        View::page('/chauffage', 'Chauffage', <<<'HTML'
<h1>Chauffage</h1>
<p class="sub">La géothermie consomme plus quand il fait froid. Les deux graphiques partagent le même axe des jours.</p>
<div class="controls" role="group" aria-label="Période" id="heat-ctl">
  <button type="button" data-d="30" aria-pressed="true">30 jours</button><button type="button" data-d="90">90 jours</button><button type="button" data-d="365">12 mois</button>
</div>
<div class="two">
  <div class="chartbox"><h3>Consommation de la PAC</h3><p class="cap">kWh par jour (circuit Géothermie, jours où le Shelly répondait)</p><div id="c-pac"></div></div>
  <div class="chartbox"><h3>Température extérieure</h3><p class="cap">°C, moyenne du jour</p><div id="c-ext"></div></div>
</div>
<div class="chartbox" id="pac-box">
  <h3>Températures d'eau de la PAC</h3>
  <p class="cap" id="pac-cap">Elles apparaîtront ici quand l'add-on enverra les données Arkteos.</p>
  <div class="tiles" id="pac-tiles"></div>
  <div id="c-water"></div>
  <div class="legend" id="lg-water"></div>
</div>
<div class="chartbox" id="appoint">
  <h3>Résistance d'appoint du ballon ECS</h3>
  <p class="cap" id="ecs-cap">Elle ne devrait jamais s'allumer.</p>
  <div class="tiles" id="ecs-tiles"></div>
  <div class="tbl" id="ecs-table"></div>
</div>
HTML
        );
    }

    public static function temperatures(): void
    {
        if (!self::guard()) {
            return;
        }
        View::page('/temperatures', 'Températures', <<<'HTML'
<h1>Températures</h1>
<p class="sub">Intérieur et extérieur, moyenne par jour.</p>
<div class="controls" role="group" aria-label="Période" id="temp-ctl">
  <button type="button" data-d="30" aria-pressed="true">30 jours</button><button type="button" data-d="90">90 jours</button><button type="button" data-d="365">12 mois</button>
</div>
<div class="chartbox"><h3>Salon, étage et extérieur</h3><p class="cap">°C, moyenne du jour</p><div id="c-temp"></div><div class="legend" id="lg-temp"></div></div>
<div class="chartbox"><h3>Minimum et maximum dehors</h3><p class="cap">°C par jour</p><div id="c-minmax"></div><div class="legend" id="lg-minmax"></div></div>
HTML
        );
    }

    public static function humidity(): void
    {
        if (!self::guard()) {
            return;
        }
        View::page('/humidite', 'Humidité', <<<'HTML'
<h1>Humidité</h1>
<p class="sub">L'ancien site ne mesurait que le salon. L'étage, l'extérieur et le CO₂ arriveront avec le nouvel add-on (modules Netatmo via Home Assistant).</p>
<div class="tiles" id="hum-tiles"></div>
<div class="controls" role="group" aria-label="Période" id="hum-ctl">
  <button type="button" data-d="30" aria-pressed="true">30 jours</button><button type="button" data-d="90">90 jours</button><button type="button" data-d="365">12 mois</button>
</div>
<div class="chartbox"><h3>Humidité relative</h3><p class="cap">%, moyenne du jour. Bande verte : zone de confort 40 à 60 %</p><div id="c-hum"></div><div class="legend" id="lg-hum"></div></div>
<div class="chartbox"><h3>Eau contenue dans l'air</h3><p class="cap">g/m³ (humidité absolue), calculée à partir de la température et de l'humidité. Quand la courbe extérieure passe sous l'intérieure, ventiler assèche la maison.</p><div id="c-abs"></div><div class="legend" id="lg-abs"></div></div>
HTML
        );
    }

    public static function compare(): void
    {
        if (!self::guard()) {
            return;
        }
        View::page('/comparer', 'Comparer', <<<'HTML'
<h1>Comparer les années</h1>
<p class="sub">Chaque couleur est une année, toujours la même d'un graphique à l'autre.</p>
<div class="controls" role="group" aria-label="Années comparées" id="yr-ctl"></div>
<div class="chartbox"><h3>Consommation électrique par mois</h3><p class="cap">kWh par mois (compteur Linky)</p><div id="c-ym"></div><div class="legend" id="lg-ym"></div><div class="tbl" id="t-yr"></div></div>
<div class="chartbox"><h3>Température extérieure par mois</h3><p class="cap">°C, moyenne du mois</p><div id="c-yt"></div><div class="legend" id="lg-yt"></div></div>
<div class="two">
  <div class="chartbox"><h3>Consommation selon la température</h3><p class="cap">Un point par jour complet : kWh du jour selon la température extérieure moyenne</p><div id="c-sc"></div><div class="legend" id="lg-sc"></div></div>
  <div class="chartbox"><h3>Ce que montre la corrélation</h3><div id="corr" class="stack"></div></div>
</div>
<div class="chartbox"><h3>Hivers comparés à froid égal</h3><p class="cap">Degrés-jours unifiés (base 18 °C) et kWh par degré-jour, d'octobre à avril, jours complets seulement</p><div class="tbl" id="t-dju"></div></div>
HTML
        );
    }

    public static function admin(): void
    {
        if (!self::guard()) {
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Session::flash(Session::checkCsrf() ? Admin::handle($_POST) : 'Formulaire expiré, recommence.');
            View::redirect('/admin');
            return;
        }
        View::page('/admin', 'Administration', Admin::render(Session::flash()));
    }
}
