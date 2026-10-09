<?php
// Reprise de l'historique : ancienne table `releve` (une colonne par mesure, heure locale)
// vers les tables sample / sample_hourly / sample_daily / sample_latest de ConsoV2.
//
//   php bin/migrate-releve.php            refuse de tourner si des données existent déjà
//   php bin/migrate-releve.php --reset    efface d'abord les données des mesures reprises
//
// L'ancienne base est lue via LEGACY_DB_* (lecture seule, rien n'y est modifié).
// Règles (voir le plan, section 11) :
// - heure locale -> UTC ; à la nuit du passage à l'heure d'hiver, la seconde occurrence
//   de 2 h-3 h est reconnue grâce à l'ordre des id ;
// - horodatages ramenés sur une grille de 5 minutes ;
// - énergie Linky calculée par différence d'index (CindexCount est ignoré) ; les index
//   incohérents (erreurs de lecture : baisse, saut au-delà de 36 kW) sont écartés ;
// - Shelly ignorés quand les 8 circuits valent 0 (Shelly hors ligne) ;
// - températures hors de ]-30, 60[ et humidité hors de ]0, 100] ignorées.
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Conso\Aggregates;
use Conso\Config;
use Conso\Db;
use Conso\Energy;
use Conso\Metrics;
use Conso\Settings;
use Conso\Time;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
ini_set('memory_limit', '1024M');
$reset = in_array('--reset', $argv, true);

$columns = [
    'Cindex' => 'elec_index',
    'CPT1' => 'circuit_seche_serviettes', 'CPT2' => 'circuit_geothermie',
    'CPT3' => 'circuit_prises_rdc', 'CPT4' => 'circuit_appoint_ecs',
    'CPT5' => 'circuit_double_flux', 'CPT6' => 'circuit_cuisson',
    'CPT7' => 'circuit_garage', 'CPT8' => 'circuit_lavage',
    'Sonde1' => 'temp_living', 'Sonde2' => 'temp_outdoor', 'Sonde3' => 'temp_upstairs',
    'Humidite' => 'humidity_living',
];
$metrics = [];
foreach ($columns as $column => $code) {
    $m = Metrics::find($code);
    if ($m === null) {
        fwrite(STDERR, "Mesure absente de la table metric : $code (sql/002_seed.sql a-t-il été chargé ?)\n");
        exit(1);
    }
    $metrics[$column] = $m;
}
$ids = implode(',', array_map(function (array $m): int {
    return $m['id'];
}, $metrics));

$pdo = Db::pdo();
$existing = (int) Db::one("SELECT COUNT(*) AS c FROM sample WHERE metric_id IN ($ids)")['c'];
if ($existing > 0 && !$reset) {
    fwrite(STDERR, "$existing mesures existent déjà pour ces grandeurs. Relancer avec --reset pour les remplacer.\n");
    exit(1);
}
if ($reset) {
    foreach (['sample', 'sample_hourly', 'sample_daily', 'sample_latest'] as $table) {
        $pdo->exec("DELETE FROM $table WHERE metric_id IN ($ids)");
    }
    echo "Données précédentes des mesures reprises effacées.\n";
}

$legacy = Db::connect('LEGACY_DB');
$legacy->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
$tz = Config::timezone();
$gaugeGap = Settings::int('gauge_max_gap_minutes', 15) * 60;
$counterGap = Settings::int('counter_max_gap_days', 62) * 86400;
$counterMaxPower = (float) Settings::int('counter_max_power_w', 36000);

$aggregates = new Aggregates();
$pending = [];
$state = [];
$count = ['rows' => 0, 'skipped_time' => 0, 'outliers' => 0, 'samples' => 0];
$perMetric = array_fill_keys(array_keys($columns), 0);
$lastUtc = null;
$lastGrid = null;
$started = microtime(true);

$flushSamples = function () use (&$pending, $pdo): void {
    foreach (array_chunk($pending, 2000) as $chunk) {
        $sql = 'INSERT IGNORE INTO sample (metric_id, ts, value) VALUES ' . implode(',', array_fill(0, count($chunk), '(?,?,?)'));
        $params = [];
        foreach ($chunk as $row) {
            array_push($params, ...$row);
        }
        $pdo->prepare($sql)->execute($params);
    }
    $pending = [];
};

$select = 'SELECT id, `DATE` AS d, ' . implode(', ', array_map(function (string $c): string {
    return "`$c`";
}, array_keys($columns))) . ' FROM releve ORDER BY id';

foreach ($legacy->query($select, PDO::FETCH_ASSOC) as $row) {
    $count['rows']++;

    // Heure locale -> UTC. PHP choisit l'heure d'été pour une heure ambiguë ; si on
    // revient en arrière, c'est la seconde occurrence : on ajoute une heure.
    $local = new DateTimeImmutable($row['d'], $tz);
    $utc = $local->getTimestamp();
    if ($lastUtc !== null && $utc <= $lastUtc) {
        $later = $utc + 3600;
        $sameLocal = (new DateTimeImmutable('@' . $later))->setTimezone($tz)->format('Y-m-d H:i:s') === $local->format('Y-m-d H:i:s');
        if ($sameLocal && $later > $lastUtc) {
            $utc = $later;
        }
    }
    $grid = $utc - ($utc % 300);
    if ($lastGrid !== null && $grid <= $lastGrid) {
        $count['skipped_time']++;
        continue;
    }
    $lastUtc = $utc;
    $lastGrid = $grid;

    $shellyOnline = false;
    for ($i = 1; $i <= 8; $i++) {
        if ((int) $row["CPT$i"] !== 0) {
            $shellyOnline = true;
            break;
        }
    }

    foreach ($columns as $column => $code) {
        $raw = $row[$column];
        if ($raw === null) {
            continue;
        }
        $value = (float) $raw;
        if ($column === 'Cindex') {
            $ok = $value > 0;
        } elseif ($column[0] === 'C') {
            $ok = $shellyOnline && $value >= 0;
        } elseif ($column === 'Humidite') {
            $ok = $value > 0 && $value <= 100;
        } else {
            $ok = $value > -30 && $value < 60;
        }
        if (!$ok) {
            continue;
        }

        $m = $metrics[$column];
        $id = $m['id'];
        $prev = $state[$id] ?? null;
        if ($m['kind'] === 'counter' && $prev !== null
            && !Energy::counterPlausible($prev[0], $prev[1], $grid, $value, $m['energy_factor'], $counterMaxPower, $counterGap)) {
            $count['outliers']++;
            continue;
        }
        $pending[] = [$id, Time::toDb($grid), $value];
        $aggregates->addValue($id, $grid, $value);
        if ($prev !== null && $m['energy_factor'] !== null) {
            $aggregates->addEnergy($id, $m['kind'] === 'counter'
                ? Energy::counter($prev[0], $prev[1], $grid, $value, $m['energy_factor'], $counterGap)
                : Energy::gauge($prev[0], $grid, $value, $m['energy_factor'], $gaugeGap));
        }
        $state[$id] = [$grid, $value];
        $perMetric[$column]++;
        $count['samples']++;
    }

    if (count($pending) >= 20000) {
        $pdo->beginTransaction();
        $flushSamples();
        $aggregates->flush($pdo);
        $pdo->commit();
    }
    if ($count['rows'] % 50000 === 0) {
        printf("%d relevés lus (%s), %.0f s\n", $count['rows'], $row['d'], microtime(true) - $started);
    }
}

$pdo->beginTransaction();
$flushSamples();
$aggregates->flush($pdo);
$latest = $pdo->prepare('REPLACE INTO sample_latest (metric_id, ts, value, received_at) VALUES (?, ?, ?, UTC_TIMESTAMP())');
foreach ($state as $id => [$ts, $value]) {
    $latest->execute([$id, Time::toDb($ts), $value]);
}
$pdo->commit();

printf("\nTerminé en %.0f s : %d relevés lus, %d ignorés (horodatage en double après passage en UTC et grille de 5 min), %d index Linky incohérents écartés, %d mesures écrites.\n",
    microtime(true) - $started, $count['rows'], $count['skipped_time'], $count['outliers'], $count['samples']);
foreach ($perMetric as $column => $n) {
    printf("  %-9s -> %-26s %8d\n", $column, $columns[$column], $n);
}

echo "\nContrôle : consommation Linky par année (différence d'index, journées locales)\n";
foreach (Db::all(
    'SELECT LEFT(day, 4) AS y, ROUND(SUM(energy_wh) / 1000) AS kwh, COUNT(*) AS days
       FROM sample_daily WHERE metric_id = ? GROUP BY LEFT(day, 4) ORDER BY y',
    [$metrics['Cindex']['id']]
) as $r) {
    printf("  %s : %6d kWh sur %3d jours\n", $r['y'], $r['kwh'], $r['days']);
}
