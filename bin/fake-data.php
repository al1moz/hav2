<?php
// Fausses mesures pour tester le site en local, quand l'add-on envoie à la production.
//   php bin/fake-data.php               les 7 derniers jours, toutes les 5 minutes
//   php bin/fake-data.php --days=30     plus d'historique
//   php bin/fake-data.php --live        puis une mesure toutes les 5 minutes (Ctrl-C pour arrêter)
//   php bin/fake-data.php --ecs         avec une activation de l'appoint ECS il y a 2 jours
// Chaque mesure ne commence qu'après sa dernière valeur déjà en base : rien n'est écrasé,
// et relancer le script ne double rien. Refusé si APP_DEBUG n'est pas à 1 (jamais en production).
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Conso\Config;
use Conso\Db;
use Conso\Ingest;
use Conso\Time;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!Config::debug()) {
    fwrite(STDERR, "Script de test : il ne tourne qu'avec APP_DEBUG=1 dans .env (jamais en production).\n");
    exit(2);
}

$days = 7;
$live = false;
$ecs = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--days=(\d{1,3})$/', $arg, $m)) {
        $days = max(1, (int) $m[1]);
    } elseif ($arg === '--live') {
        $live = true;
    } elseif ($arg === '--ecs') {
        $ecs = true;
    } else {
        fwrite(STDERR, "Option inconnue : $arg (--days=N, --live, --ecs)\n");
        exit(2);
    }
}

/** Bruit régulier entre -1 et 1 : les mêmes valeurs à chaque lancement. */
function wave(int $ts, int $k): float
{
    return 0.5 * sin($ts / 1300 + $k * 1.7) + 0.35 * sin($ts / 4700 + $k * 3.1) + 0.15 * sin($ts / 337 + $k * 5.3);
}

/** Tirage stable pour une journée (0 à 99). */
function dice(string $day, string $what): int
{
    return (int) (crc32($day . $what) % 100);
}

/** Les mesures d'un pas de 5 minutes : code => [source, unité, valeur]. @return array<string,array{0:string,1:string,2:float}> */
function step(int $ts, int $ecsFrom, int $ecsTo): array
{
    $local = (new DateTime('@' . $ts))->setTimezone(Config::timezone());
    $h = (int) $local->format('G') + (int) $local->format('i') / 60;
    $min = (int) $local->format('i');
    $day = $local->format('Y-m-d');
    $weekend = (int) $local->format('N') >= 6;
    $d = $ts / 86400;
    $between = function (float $from, float $to) use ($h): bool {
        return $h >= $from && $h < $to;
    };

    // Météo : douceur d'octobre, plus froid la nuit, quelques jours plus frais.
    $out = 11 + 4.5 * sin(2 * M_PI * ($h - 9) / 24) + 2.5 * sin(2 * M_PI * $d / 5.3 + 1) + 0.4 * wave($ts, 1);
    $outHum = max(45, min(99, 78 - 3.2 * ($out - 11) + 5 * sin($d / 3.1) + 2 * wave($ts, 2)));
    $pressure = 1015 + 7 * sin(2 * M_PI * $d / 4.3) + 2 * sin(2 * M_PI * $d / 1.1) + 0.3 * wave($ts, 3);
    $living = 20.6 + 0.6 * sin(2 * M_PI * ($h - 13) / 24) + 0.15 * wave($ts, 4);
    $upstairs = 19.9 + 0.4 * sin(2 * M_PI * ($h - 15) / 24) + 0.15 * wave($ts, 5);
    $occupied = $between(7, 8.5) || $between(12, 13.5) || $between(18, 23) || ($weekend && $between(9, 18));

    // PAC : marche par cycles, d'autant plus longs qu'il fait froid ; chauffe du ballon à 2 h.
    $need = max(0, 17.5 - $out);
    $duty = min(1, $need / 35);
    $ecsHeat = $between(2, 2.75);
    $pacOn = $ecsHeat || $min < $duty * 60;
    $geo = $pacOn ? ($ecsHeat ? 2200 : 1850) + 60 * wave($ts, 6) : 25;
    $flow = $pacOn ? ($ecsHeat ? 48 : 31 + 0.8 * $need) : 25 + 2 * wave($ts, 7);
    $tank = 52 - 6 * fmod($h + 21.25, 24) / 24 + 0.3 * wave($ts, 8);

    // Circuits suivis par les Shelly (W, moyenne sur 5 min).
    $towel = ($weekend ? $between(8, 9.5) : $between(6.5, 8)) || ($out < 14 && $between(19, 20)) ? ($min % 10 < 6 ? 1000 : 0) : 0;
    $plugs = 55 + ($occupied ? 90 : 0) + ($between(7.2, 7.3) ? 1800 : 0) + 15 * wave($ts, 9);
    $dinner = $between(19.15, 19.85) ? ($min % 6 < 4 ? 2100 : 300) : 0;
    $lunch = $weekend && $between(12, 12.6) ? 1500 : 0;
    $vmc = 42 + ($between(7, 8) || $between(19, 20) ? 30 : 0) + 4 * wave($ts, 10);
    $garage = 12 + ($min % 30 < 12 ? 85 : 0);
    $washer = dice($day, 'lavage') < 40 && $between(10, 11.5) ? ($between(10, 10.35) ? 2000 : 250) : 0;
    $dishes = $between(21, 22.5) ? ($between(21, 21.4) ? 1800 : 90) : 0;
    $backup = $ts >= $ecsFrom && $ts < $ecsTo ? 2600 : 4 + 0.5 * wave($ts, 11);
    $circuits = [
        'circuit_seche_serviettes' => $towel,
        'circuit_geothermie' => $geo,
        'circuit_prises_rdc' => $plugs,
        'circuit_appoint_ecs' => $backup,
        'circuit_double_flux' => $vmc,
        'circuit_cuisson' => $dinner + $lunch,
        'circuit_garage' => $garage,
        'circuit_lavage' => $washer + $dishes,
    ];
    $rest = 90 + ($occupied && ($h > 18 || $h < 8) ? 120 : 0) + 15 * wave($ts, 12);
    $total = array_sum($circuits) + $rest;

    $m = [];
    foreach ($circuits as $code => $w) {
        $m[$code] = ['shelly', 'W', round($w, 1)];
    }
    $m['elec_power'] = ['linky', 'VA', round($total * 1.04)];
    $m['elec_voltage'] = ['linky', 'V', round(232 + 2.5 * sin(2 * M_PI * $h / 24) + wave($ts, 13))];
    $m['_wh'] = ['', '', $total * 300 / 3600];
    $m['temp_living'] = ['netatmo', '°C', round($living, 1)];
    $m['temp_upstairs'] = ['netatmo', '°C', round($upstairs, 1)];
    $m['temp_outdoor'] = ['netatmo', '°C', round($out, 1)];
    $m['humidity_living'] = ['netatmo', '%', round(54 + 4 * sin(2 * M_PI * ($h - 20) / 24) + ($dinner ? 5 : 0) + wave($ts, 14))];
    $m['humidity_upstairs'] = ['netatmo', '%', round(57 + 3 * sin(2 * M_PI * ($h - 7) / 24) + wave($ts, 15))];
    $m['humidity_outdoor'] = ['netatmo', '%', round($outHum)];
    $m['co2_living'] = ['netatmo', 'ppm', round(470 + ($occupied ? 450 : 80) + 60 * wave($ts, 16))];
    $m['pressure_outdoor'] = ['netatmo', 'hPa', round($pressure, 1)];
    $m['pac_primaire_temp_eau_aller'] = ['arkteos', '°C', round($flow, 1)];
    $m['pac_primaire_temp_eau_retour'] = ['arkteos', '°C', round($flow - ($pacOn ? 4.2 : 0.8), 1)];
    $m['pac_ecs_temp_eau_milieu'] = ['arkteos', '°C', round($tank, 1)];
    $m['pac_ecs_temp_eau_bas'] = ['arkteos', '°C', round($tank - 9, 1)];
    $m['pac_exterieur_temp'] = ['arkteos', '°C', round($out + 0.8, 1)];
    $m['pac_zone1_temp_interieur'] = ['arkteos', '°C', round($living - 0.2, 1)];
    $m['pac_zone2_temp_interieur'] = ['arkteos', '°C', round($upstairs - 0.2, 1)];
    $m['pac_zone1_consigne'] = ['arkteos', '°C', 20.0];
    $m['pac_zone2_consigne'] = ['arkteos', '°C', 19.0];
    $m['pac_primaire_pression'] = ['arkteos', 'bar', round(1.6 + 0.04 * wave($ts, 17), 2)];
    $m['pac_externe_pression'] = ['arkteos', 'bar', round(1.9 + 0.04 * wave($ts, 18), 2)];
    return $m;
}

/** Dernier horodatage et dernière valeur de chaque mesure déjà en base. @return array<string,array{ts:int,value:float}> */
function latest(): array
{
    $out = [];
    foreach (Db::all('SELECT m.code, l.ts, l.value FROM sample_latest l JOIN metric m ON m.id = l.metric_id') as $r) {
        $out[$r['code']] = ['ts' => Time::fromDb($r['ts']), 'value' => (float) $r['value']];
    }
    return $out;
}

/** Envoie les pas de $from à $to par le même chemin que l'API. @return int[] [acceptées, rejetées] */
function send(int $from, int $to, int $ecsFrom, int $ecsTo): array
{
    $latest = latest();
    $indexWh = isset($latest['elec_index']) ? $latest['elec_index']['value'] * 1000 : 25000000.0;
    $items = [];
    $accepted = 0;
    $rejected = 0;
    for ($ts = $from; $ts <= $to; $ts += 300) {
        $m = step($ts, $ecsFrom, $ecsTo);
        $wh = $m['_wh'][2];
        unset($m['_wh']);
        if (!isset($latest['elec_index']) || $ts > $latest['elec_index']['ts']) {
            $indexWh += $wh;
            $m['elec_index'] = ['linky', 'Wh', round($indexWh)];
        }
        foreach ($m as $code => [$source, $unit, $value]) {
            if (isset($latest[$code]) && $ts <= $latest[$code]['ts']) {
                continue;
            }
            $item = ['metric' => $code, 'source' => $source, 'unit' => $unit, 'value' => $value, 'ts' => Time::iso($ts)];
            if ($code === 'elec_index') {
                $item['unit'] = 'Wh';
                $item['kind'] = 'counter';
            }
            $items[] = $item;
        }
        if (count($items) > 4000 || $ts + 300 > $to) {
            [$status, $body] = Ingest::handle(['measurements' => $items], 0, null);
            $accepted += (int) ($body['accepted'] ?? 0);
            $rejected += (int) ($body['rejected'] ?? 0);
            if ($status !== 202 || !empty($body['errors'])) {
                fwrite(STDERR, 'Réponse ' . $status . ' : ' . json_encode($body['errors'] ?? $body, JSON_UNESCAPED_UNICODE) . "\n");
            }
            $items = [];
            echo '.';
        }
    }
    return [$accepted, $rejected];
}

$now = intdiv(time(), 300) * 300;
$from = $now - $days * 86400;
$ecsFrom = $ecs ? $now - 2 * 86400 - 3 * 3600 : 0;
$ecsTo = $ecs ? $ecsFrom + 40 * 60 : 0;

echo "Fausses mesures du " . Time::iso($from) . " au " . Time::iso($now) . " (UTC)";
[$accepted, $rejected] = send($from, $now, $ecsFrom, $ecsTo);
echo "\n$accepted mesures ajoutées, $rejected rejetées.\n";

while ($live) {
    $next = intdiv(time(), 300) * 300 + 300;
    echo 'Prochain envoi à ' . (new DateTime('@' . $next))->setTimezone(Config::timezone())->format('H:i') . "…\n";
    sleep(max(1, $next - time()));
    [$accepted, $rejected] = send($next, $next, 0, 0);
    echo " $accepted mesures ajoutées.\n";
}
