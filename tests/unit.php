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
use Conso\Weather;

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

// Météo : moyennes des points, vent sur la piste, pastilles, réponses de Météo Concept.
$check('position : virgule et espace', Weather::parsePoint('46.5, 2.25') === [46.5, 2.25]);
$check('position : hors limites refusée', Weather::parsePoint('95, 10') === null && Weather::parsePoint('nord') === null);
$b = Weather::blend([['w' => 1, 'wind' => 10, 'dir' => 350, 'gust' => 20, 'weather' => 1], ['w' => 1, 'wind' => 10, 'dir' => 10, 'gust' => 30, 'weather' => 3]]);
$check('moyenne : direction 350° et 10° = 0°', $b['dir'] === 0);
$check('moyenne : vent et rafales', $near((float) $b['wind'], 10.0) && $near((float) $b['gust'], 25.0) && $b['gust_max'] == 30);
$check('moyenne : temps à égalité = le plus mauvais', $b['weather'] === 3 && $b['label'] === 'Nuageux');
$b = Weather::blend([['w' => 3, 'wind' => 0, 'weather' => 0, 'probafog' => 10], ['w' => 1, 'wind' => 20, 'weather' => 104, 'probafog' => 60]]);
$check('moyenne pondérée', $near((float) $b['wind'], 5.0));
$check('moyenne : code le plus fréquent', $b['weather'] === 0);
$check('moyenne : risque de brouillard au plus haut', $b['probafog'] == 60);
$check('pistes : sens opposé ajouté', Weather::bothWays([70]) === [70, 250]);
$rw = Weather::runwayWind(230.0, 22.0, 35.0, [70, 250]);
$check('vent sur la piste : 25, face 21, travers 8 de gauche', $rw['runway'] === 250 && $rw['head'] === 21 && $rw['cross'] === -8 && $rw['gust_cross'] === -12);
$check('vent sur la piste : de droite positif', Weather::runwayWind(270.0, 10.0, null, [250])['cross'] === 3);
$check('vent variable : tout en travers', Weather::runwayWind(null, 12.0, null, [250])['cross'] === 12);
$lim = ['cross_warn' => 18, 'cross_max' => 25, 'gust_warn' => 37, 'gust_max' => 46];
$check('pastille : vent faible = vert', Weather::assess(['wind' => 10, 'gust' => 15, 'dir' => 250], [70, 250], $lim, false)['level'] === 'ok');
$check('pastille : travers en rafale 26 = rouge', Weather::assess(['wind' => 15, 'gust' => 26, 'dir' => 340], [70, 250], $lim, false)['level'] === 'bad');
$check('pastille : travers 20 = orange', Weather::assess(['wind' => 20, 'gust' => 20, 'dir' => 340], [70, 250], $lim, false)['level'] === 'warn');
$check('pastille : rafales 40 = orange', Weather::assess(['wind' => 20, 'gust' => 40, 'dir' => 250], [70, 250], $lim, false)['level'] === 'warn');
$check('pastille : orage = rouge', Weather::assess(['wind' => 5, 'dir' => 250, 'weather' => 104], [250], $lim, true)['level'] === 'bad');
$check('pastille : brouillard probable = orange', Weather::assess(['wind' => 5, 'dir' => 250, 'weather' => 3, 'probafog' => 60], [250], $lim, true)['level'] === 'warn');
$check('pastille : observation sans le temps', Weather::assess(['wind' => 5, 'dir' => 250, 'weather' => 104], [250], $lim, false)['level'] === 'ok');
$f = ['forecast' => [
    [['latitude' => 46.62, 'longitude' => 2.56, 'day' => 0, 'period' => 3, 'datetime' => '2026-10-10T20:00:00+0200', 'wind10m' => 15, 'gust10m' => 30, 'dirwind10m' => 290, 'weather' => 3, 'temp2m' => 13]],
    [['latitude' => 46.62, 'longitude' => 2.56, 'day' => 1, 'period' => 1, 'datetime' => '2026-10-11T08:00:00+0200', 'wind10m' => 12, 'gust10m' => 28, 'dirwind10m' => 250, 'weather' => 10, 'temp2m' => 11],
     ['latitude' => 46.62, 'longitude' => 2.56, 'day' => 1, 'period' => 2, 'datetime' => '2026-10-13T14:00:00+0200', 'wind10m' => 12, 'weather' => 10]],
]];
$p = Weather::parsePeriods($f, '2026-10-12');
$check('périodes : rangées par date réelle, au-delà de demain ignorées', count($p['items']) === 2 && Time::localDate($p['items'][1]['ts']) === '2026-10-11' && $p['items'][1]['period'] === 1);
$check('périodes : point de grille', $p['grid'] === '46.6200,2.5600');
$check('périodes : réponse vide', Weather::parsePeriods(['code' => 403], '2026-10-12') === null);
$st = Weather::parseStations([
    ['station' => ['name' => 'B', 'uuid' => str_repeat('b', 36), 'latitude' => 46.7, 'longitude' => 2.5], 'observation' => []],
    ['station' => ['name' => 'A', 'uuid' => str_repeat('a', 36), 'latitude' => 46.51, 'longitude' => 2.5],
     'observation' => ['time' => '2026-10-10T22:25:00+00:00', 'wind_10m' => ['value' => '4.8'], 'windgust_10m' => ['value' => '9.7'], 'wind_direction' => ['value' => '270']]],
], [46.5, 2.5]);
$check('stations : triées par distance, sans relevé = vides', $st[0]['name'] === 'A' && $st[0]['wind'] === 4.8 && $st[0]['dir'] === 270.0 && $st[1]['ts'] === null && $st[1]['wind'] === null);
$check('TAF : une ligne par groupe', Metar::tafLines('TAF LFPG 101700Z 1018/1118 29010KT 9999 BKN030 PROB30 TEMPO 1018/1020 4000 SHRA BECMG 1018/1021 23005KT TEMPO 1100/1104 BR=')
    === ['TAF LFPG 101700Z 1018/1118 29010KT 9999 BKN030', 'PROB30 TEMPO 1018/1020 4000 SHRA', 'BECMG 1018/1021 23005KT', 'TEMPO 1100/1104 BR']);

// METAR et TAF décodés : temps présent, couches, plafond, groupes du TAF.
$g = Metar::decodeGroup(['23006KT', '9999', '-SHRA', 'FEW020', 'BKN047', 'OVC080']);
$check('METAR : plafond = plus basse couche BKN', $g['ceiling'] === 4700 && count($g['layers']) === 3 && $g['visibility'] === 9999);
$check('METAR : averses faibles, pictogramme d\'averses', $g['weather'] === ['Averses faibles de pluie'] && $g['code'] === 43);
$check('METAR : orage fort avec pluie', Metar::decodeGroup(['+TSRA'])['label'] === 'Orage fort avec pluie');
$check('METAR : brouillard givrant', Metar::decodeGroup(['FZFG', 'VV002'])['label'] === 'Brouillard givrant' && Metar::decodeGroup(['FZFG', 'VV002'])['ceiling'] === 200);
$check('METAR : CAVOK = ciel clair, 10 km', Metar::decodeGroup(['CAVOK'])['code'] === 0 && Metar::decodeGroup(['CAVOK'])['visibility'] === 10000);
$check('METAR : nuages seuls, pas de plafond', Metar::decodeGroup(['SCT030'])['ceiling'] === null && Metar::decodeGroup(['SCT030'])['label'] === 'Nuageux');
$taf = Metar::parseTaf('TAF LFPG 101700Z 1018/1118 29010KT 9999 BKN030 PROB30 TEMPO 1018/1020 4000 SHRA BECMG 1018/1021 23005KT TEMPO 1100/1104 BR BKN008', Time::parse('2026-10-10T17:00:00Z'));
$check('TAF : validité', $taf['from'] === Time::parse('2026-10-10T18:00:00Z') && $taf['to'] === Time::parse('2026-10-11T18:00:00Z'));
$check('TAF : groupes', array_column($taf['groups'], 'type') === ['BASE', 'TEMPO', 'BECMG', 'TEMPO'] && $taf['groups'][1]['prob'] === 30);
$w = Metar::tafWindow($taf, Time::parse('2026-10-11T00:00:00Z'), Time::parse('2026-10-11T06:00:00Z'));
$check('TAF : plafond de la période et temporairement 800 ft', $w['main']['ceiling'] === 3000 && $w['temp']['ceiling'] === 800 && $w['temp']['weather'] === ['Brume']);
$check('TAF : période hors validité', Metar::tafWindow($taf, Time::parse('2026-10-12T06:00:00Z'), Time::parse('2026-10-12T12:00:00Z')) === null);
$a = Weather::withTaf(['level' => 'ok', 'reasons' => [], 'runway' => null], $w);
$check('pastille : temporairement plafond 800 ft = orange', $a['level'] === 'warn' && $a['reasons'] === ['temporairement plafond 800 ft']);
$fm = Metar::parseTaf('TAF LFPG 101700Z 1018/1118 29010KT 9999 SCT030 FM110300 20015KT 3000 RA OVC006', Time::parse('2026-10-10T17:00:00Z'));
$w = Metar::tafWindow($fm, Time::parse('2026-10-11T04:00:00Z'), Time::parse('2026-10-11T06:00:00Z'));
$check('TAF : FM remplace la prévision', $w['main']['ceiling'] === 600 && $w['main']['visibility'] === 3000
    && Weather::withTaf(['level' => 'ok', 'reasons' => [], 'runway' => null], $w)['level'] === 'bad');

echo $failures === 0 ? "\nTous les tests passent.\n" : "\n$failures test(s) en échec.\n";
exit($failures === 0 ? 0 : 1);
