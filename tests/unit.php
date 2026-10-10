<?php
// Tests des calculs, sans base de données : php tests/unit.php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Conso\Chat;
use Conso\ChatTools;
use Conso\Claude;
use Conso\Energy;
use Conso\Metar;
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

// Compteur remplacé : le premier index refusé est confirmé par le suivant, une erreur isolée ne l'est jamais.
$candidate = ['ts' => $h + 300, 'value' => 12.5];
$check('nouvelle base confirmée', Energy::confirmsCandidate($candidate, $h + 600, 12.6, 1000.0, 36000.0));
$check('erreur isolée non confirmée', !Energy::confirmsCandidate(['ts' => $h + 300, 'value' => 17679996.0], $h + 600, 17668.2, 1000.0, 36000.0));
$check('candidat trop ancien ignoré', !Energy::confirmsCandidate($candidate, $h + 7 * 3600, 12.6, 1000.0, 36000.0));
$check('sans candidat rien à confirmer', !Energy::confirmsCandidate(null, $h + 600, 12.6, 1000.0, 36000.0));

$check('Wh -> kWh', $near((float) Units::factor('Wh', 'kWh'), 0.001));
$check('W et °C incompatibles', Units::factor('W', '°C') === null);

$check('ISO avec Z', Time::parse('2026-10-09T16:20:00Z') === 1791562800);
$check('ISO avec décalage', Time::parse('2026-10-09T18:20:00+02:00') === 1791562800);
$check('date sans fuseau refusée', Time::parse('2026-10-09 16:20:00') === null);

// Journées locales (Europe/Paris) : 22:30 UTC en hiver = 23:30 locale, 22:30 UTC en été = 00:30 le lendemain.
$check('date locale hiver', Time::localDate((int) Time::parse('2026-01-10T22:30:00Z')) === '2026-01-10');
$check('date locale été', Time::localDate((int) Time::parse('2026-07-10T22:30:00Z')) === '2026-07-11');
$check('borne de journée locale', Time::parseBound('2026-07-11', false) === Time::parse('2026-07-10T22:00:00Z'));

// METAR (page tablette) : objets JSON d'aviationweather.gov, aérodrome fictif.
$base = ['rawOb' => 'METAR LFPG 091600Z 25013KT 9999 SCT034 BKN086 OVC100 18/13 Q1017 NOSIG', 'wdir' => 250, 'wspd' => 13,
    'altim' => 1017, 'obsTime' => 1791561600, 'fltCat' => 'VFR',
    'clouds' => [['cover' => 'SCT', 'base' => 3400], ['cover' => 'BKN', 'base' => 8600], ['cover' => 'OVC', 'base' => 10000]]];
$d = Metar::describe($base, [70, 250]);
$check('METAR : VFR, QNH, vent dans l\'axe', $d['category'] === 'VFR' && $d['qnh'] === 1017 && $d['wind']['dir'] === '250'
    && $d['wind']['level'] === 'ok' && $d['wind']['cross'] === 0 && $d['wind']['gust'] === null);
$check('METAR : nuages en clair', count($d['clouds']) === 3 && $d['clouds'][0] === 'Nuages épars, 3/8 à 4/8 à 3400 ft');
$check('METAR : visibilité 4000 m = IFR', Metar::describe(['rawOb' => 'METAR LFPG 091600Z 25005KT 4000 BR OVC004 12/12 Q1012', 'wdir' => 250, 'wspd' => 5], [250])['category'] === 'IFR');
$cavok = Metar::describe(['rawOb' => 'METAR LFPG 091600Z 07008KT CAVOK 21/09 Q1024 TEMPO 3000 BR'], [70, 250]);
$check('METAR : CAVOK, TEMPO ignoré, vent lu dans le message', $cavok['category'] === 'VFR' && $cavok['clouds'] === ['CAVOK']
    && $cavok['wind']['dir'] === '070' && $cavok['wind']['speed'] === 8 && $cavok['qnh'] === 1024);
$check('METAR : rafales', Metar::describe($base + ['wgst' => 25], [250])['wind']['gust'] === 25);
$check('METAR : vent variable', Metar::describe(['rawOb' => 'METAR LFPG 091600Z VRB03KT 9999 NSC 15/10 Q1020'], [250])['wind']['dir'] === 'VRB');
$check('vent de travers 20 kt = hors limites', Metar::wind(340, 20, [70, 250])['level'] === 'bad');
$check('vent de travers 17 kt = limite pilote', Metar::wind(310, 20, [250])['level'] === 'warn');
$check('vent arrière 5 kt = hors limites', Metar::wind(70, 5, [250])['level'] === 'bad');
$check('piste la plus favorable retenue', Metar::wind(70, 5, [70, 250])['level'] === 'ok');
$check('sans piste, pas de couleur', Metar::wind(250, 13, [])['level'] === null);
// Nuit aéronautique à l'équateur (0, 0) le 9 oct. 2026 : aube civile 05:23 UTC, fin du crépuscule 18:11 UTC.
$day = Metar::nightAt(0.0, 0.0, Time::parse('2026-10-09T12:00:00Z'));
$check('nuit : midi = jour, nuit vers 18:11', $day !== null && !$day['night'] && gmdate('H:i', $day['until']) === '18:11');
$dusk = Metar::nightAt(0.0, 0.0, Time::parse('2026-10-09T18:05:00Z'));
$check('nuit : après le coucher, pas encore la nuit', $dusk !== null && !$dusk['night']);
$late = Metar::nightAt(0.0, 0.0, Time::parse('2026-10-09T23:30:00Z'));
$check('nuit : 23:30 = nuit jusqu\'au lendemain', $late !== null && $late['night'] && gmdate('Y-m-d', $late['until']) === '2026-10-10');
$early = Metar::nightAt(0.0, 0.0, Time::parse('2026-10-09T03:00:00Z'));
$check('nuit : 03:00 = nuit jusqu\'à l\'aube', $early !== null && $early['night'] && gmdate('Y-m-d H:i', $early['until']) === '2026-10-09 05:23');
$check('nuit : sans position, rien', Metar::night(['rawOb' => 'METAR'], time()) === null);
$check('pistes en degrés ou en numéros', Metar::runways('07/25') === [70, 250] && Metar::runways('070, 250') === [70, 250] && Metar::runways('') === []);

// Chat : outils, historique et réponses de l'API.
$tools = ChatTools::definitions();
$strict = true;
foreach ($tools as $t) {
    $schema = $t['input_schema'];
    $props = array_keys((array) $schema['properties']);
    $strict = $strict && $t['strict'] === true && $schema['additionalProperties'] === false && ($schema['required'] ?? []) === $props;
}
$check('outils : schémas stricts, tous les paramètres requis', $strict && count($tools) === 8);
$check('outils : sans paramètre = objet vide en JSON', strpos(json_encode($tools[0]), '"properties":{}') !== false);
$check('dates : période valide', ChatTools::range(['from' => '2026-01-01', 'to' => '2026-01-31'], 400) === ['2026-01-01', '2026-01-31']);
$check('dates : format refusé', is_string(ChatTools::range(['from' => '2026-1-1', 'to' => '2026-01-31'], 400)));
$check('dates : date impossible refusée', is_string(ChatTools::range(['from' => '2026-02-30', 'to' => '2026-03-01'], 400)));
$check('dates : ordre inversé refusé', is_string(ChatTools::range(['from' => '2026-02-01', 'to' => '2026-01-01'], 400)));
$check('dates : période trop longue', is_string(ChatTools::range(['from' => '2026-01-01', 'to' => '2026-01-17'], 16)));
$hist = Chat::history([['q' => 'a', 'a' => 'b'], ['q' => '', 'a' => 'x'], ['q' => ['x'], 'a' => 'y'], 'n', ['q' => ' c ', 'a' => 'd']]);
$check('historique : seuls les échanges complets en texte', $hist === [['a', 'b'], ['c', 'd']]);
$check('historique : 6 échanges au plus', count(Chat::history(array_fill(0, 10, ['q' => 'q', 'a' => 'a']))) === 6);
$resp = json_decode('{"content":[{"type":"text","text":"Avant"},{"type":"thinking","thinking":"","signature":"s"},{"type":"tool_use","id":"t1","name":"x","input":{}},'
    . '{"type":"fallback","from":{"model":"a"},"to":{"model":"b"}},{"type":"thinking","thinking":"","signature":"s2"},{"type":"text","text":"Après"}]}');
$kept = array_map(function ($b) { return $b->type; }, Claude::replayable($resp->content));
$check('repli : réflexion et outils avant le bloc fallback retirés', $kept === ['text', 'fallback', 'thinking', 'text']);
$check('repli : sans bloc fallback, contenu intact', Claude::replayable([$resp->content[1], $resp->content[2]]) === [$resp->content[1], $resp->content[2]]);
$check('repli : {} reste un objet vide', json_encode(Claude::replayable($resp->content)[0]) === '{"type":"text","text":"Avant"}'
    && strpos(json_encode($resp->content[2]), '"input":{}') !== false);
$check('texte de la réponse', Claude::text($resp->content) === "Avant\n\nAprès");
$usage = json_decode('{"input_tokens":10,"output_tokens":5,"iterations":[{"type":"message","input_tokens":100,"output_tokens":0},'
    . '{"type":"fallback_message","input_tokens":10,"output_tokens":5,"cache_read_input_tokens":1000}]}');
$check('jetons : toutes les tentatives comptées', Claude::tokens($usage) === ['in' => 110, 'out' => 5, 'cache_write' => 0, 'cache_read' => 1000]);
$check('coût : 1 M en entrée + 1 M en sortie = 24 $', $near(Claude::cost(['in' => 1000000, 'out' => 1000000, 'cache_write' => 0, 'cache_read' => 0]), 24.0));

echo $failures === 0 ? "\nTous les tests passent.\n" : "\n$failures test(s) en échec.\n";
exit($failures === 0 ? 0 : 1);
