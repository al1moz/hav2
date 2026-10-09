<?php
// Applique les fichiers sql/NNN_*.sql pas encore passés (table schema_version).
// À lancer après chaque mise à jour du code : php bin/migrate.php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Conso\Db;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$pdo = Db::pdo();
$applied = [];
try {
    foreach (Db::all('SELECT version FROM schema_version') as $row) {
        $applied[(int) $row['version']] = true;
    }
} catch (PDOException $e) {
    // Base vide : 001 crée la table.
}

$files = glob(APP_ROOT . '/sql/[0-9][0-9][0-9]_*.sql');
sort($files);
$count = 0;
foreach ($files as $file) {
    $version = (int) substr(basename($file), 0, 3);
    if (isset($applied[$version])) {
        continue;
    }
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    foreach (preg_split('/;\s*$/m', $sql) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
    Db::run('INSERT IGNORE INTO schema_version (version, applied_at) VALUES (?, UTC_TIMESTAMP())', [$version]);
    echo 'Appliqué : ' . basename($file) . "\n";
    $count++;
}
echo $count === 0 ? "Base déjà à jour.\n" : "$count fichier(s) appliqué(s).\n";
