<?php
// Tests des calculs, sans base de données : php tests/unit.php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Conso\Energy;
use Conso\Time;
use Conso\Units;

$failures = 0;
$check = function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'ÉCHEC ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
};
$near = function (float $a, float $b): bool {
    return abs($a - $b) < 1e-6;
};

$h = Time::parse('2026-01-10T10:00:00Z');

// Puissance moyenne : 1200 W pendant 5 min = 100 Wh, dans l'heure de 10 h.
$e = Energy::gauge($h, $h + 300, 1200.0, 1.0, 900);
$check('gauge 1200 W x 5 min = 100 Wh', count($e) === 1 && $near($e[$h], 100.0));
$check('gauge au-delà du trou max = rien', Energy::gauge($h, $h + 1800, 1200.0, 1.0, 900) === []);
$check('gauge à 0 W = rien', Energy::gauge($h, $h + 300, 0.0, 1.0, 900) === []);

// Intervalle à cheval sur deux heures : 10:57:30 -> 11:02:30, moitié-moitié.
$e = Energy::gauge($h + 3450, $h + 3750, 1200.0, 1.0, 900);
$check('gauge à cheval sur 2 heures', $near($e[$h], 50.0) && $near($e[$h + 3600], 50.0));

// Index en kWh : +3 kWh sur un trou de 3 h -> 1000 Wh par heure.
$e = Energy::counter($h, 1000.0, $h + 3 * 3600, 1003.0, 1000.0, 62 * 86400);
$check('counter réparti sur le trou', count($e) === 3 && $near($e[$h + 7200], 1000.0) && $near(array_sum($e), 3000.0));
$check('counter qui baisse = rien', Energy::counter($h, 1003.0, $h + 300, 1000.0, 1000.0, 62 * 86400) === []);

// Index Linky en kWh : erreurs de lecture vues dans l'ancienne base.
$check('index normal accepté', Energy::counterPlausible($h, 17667.9, $h + 300, 17668.1, 1000.0, 36000.0, 62 * 86400));
$check('index x1000 écarté', !Energy::counterPlausible($h, 17667.9, $h + 300, 17679996.0, 1000.0, 36000.0, 62 * 86400));
$check('index tronqué écarté', !Energy::counterPlausible($h, 24871.8, $h + 300, 3916.9, 1000.0, 36000.0, 62 * 86400));
$check('trou de 24 jours accepté', Energy::counterPlausible($h, 15000.0, $h + 24 * 86400, 15811.0, 1000.0, 36000.0, 62 * 86400));

$check('Wh -> kWh', $near((float) Units::factor('Wh', 'kWh'), 0.001));
$check('W et °C incompatibles', Units::factor('W', '°C') === null);

$check('ISO avec Z', Time::parse('2026-10-09T16:20:00Z') === 1791562800);
$check('ISO avec décalage', Time::parse('2026-10-09T18:20:00+02:00') === 1791562800);
$check('date sans fuseau refusée', Time::parse('2026-10-09 16:20:00') === null);

// Journées locales (Europe/Paris) : 22:30 UTC en hiver = 23:30 locale, 22:30 UTC en été = 00:30 le lendemain.
$check('date locale hiver', Time::localDate((int) Time::parse('2026-01-10T22:30:00Z')) === '2026-01-10');
$check('date locale été', Time::localDate((int) Time::parse('2026-07-10T22:30:00Z')) === '2026-07-11');
$check('borne de journée locale', Time::parseBound('2026-07-11', false) === Time::parse('2026-07-10T22:00:00Z'));

echo $failures === 0 ? "\nTous les tests passent.\n" : "\n$failures test(s) en échec.\n";
exit($failures === 0 ? 0 : 1);
