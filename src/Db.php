<?php
declare(strict_types=1);

namespace Conso;

use PDO;

/** Connexion PDO unique. Requêtes préparées uniquement. */
final class Db
{
    /** @var PDO|null */
    private static $pdo;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect('DB');
        }
        return self::$pdo;
    }

    /** Connexion à partir des variables PREFIX_HOST, PREFIX_PORT, PREFIX_NAME, PREFIX_USER, PREFIX_PASS. */
    public static function connect(string $prefix): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Config::require($prefix . '_HOST'),
            Config::get($prefix . '_PORT', '3306'),
            Config::require($prefix . '_NAME')
        );
        $pdo = new PDO($dsn, Config::require($prefix . '_USER'), Config::get($prefix . '_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    /** @param array<int|string,mixed> $params */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @param array<int|string,mixed> $params @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<int|string,mixed> $params @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }
}
