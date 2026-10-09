<?php
// Gestion des jetons d'API en ligne de commande (en attendant l'administration).
//   php bin/token.php create <nom> <portées>   ex. create addon-maison ingest
//   php bin/token.php list
//   php bin/token.php revoke <id>
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Conso\Auth;
use Conso\Db;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$command = $argv[1] ?? '';
switch ($command) {
    case 'create':
        if (!isset($argv[2], $argv[3])) {
            fwrite(STDERR, "Usage : php bin/token.php create <nom> <portées séparées par des virgules : ingest,read,tablet>\n");
            exit(2);
        }
        $token = Auth::createToken($argv[2], explode(',', $argv[3]));
        echo "Jeton créé. Il ne sera plus jamais affiché :\n$token\n";
        break;
    case 'list':
        foreach (Db::all('SELECT id, name, scopes, created_at, last_used_at, revoked_at FROM api_token ORDER BY id') as $t) {
            printf("%3d  %-20s %-20s créé %s  dernier usage %s%s\n", $t['id'], $t['name'], $t['scopes'], $t['created_at'],
                $t['last_used_at'] ?? 'jamais', $t['revoked_at'] ? "  RÉVOQUÉ {$t['revoked_at']}" : '');
        }
        break;
    case 'revoke':
        $n = Db::run('UPDATE api_token SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND revoked_at IS NULL', [(int) ($argv[2] ?? 0)])->rowCount();
        echo $n ? "Jeton révoqué.\n" : "Aucun jeton actif avec cet id.\n";
        break;
    default:
        fwrite(STDERR, "Commandes : create, list, revoke\n");
        exit(2);
}
