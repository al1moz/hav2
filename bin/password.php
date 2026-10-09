<?php
// Définit le mot de passe du site : php bin/password.php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Conso\Db;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$read = function (string $prompt): string {
    echo $prompt;
    if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
        system('stty -echo');
        $value = trim((string) fgets(STDIN));
        system('stty echo');
        echo "\n";
        return $value;
    }
    return trim((string) fgets(STDIN));
};

$password = $read('Nouveau mot de passe (12 caractères au moins) : ');
if (strlen($password) < 12) {
    fwrite(STDERR, "Trop court.\n");
    exit(2);
}
if ($read('Encore une fois : ') !== $password) {
    fwrite(STDERR, "Les deux saisies diffèrent.\n");
    exit(2);
}
Db::run(
    "INSERT INTO setting (name, value) VALUES ('password_hash', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
    [password_hash($password, PASSWORD_DEFAULT)]
);
echo "Mot de passe enregistré.\n";
