<?php
declare(strict_types=1);

namespace Conso;

/** Jetons d'API (Bearer). Seule l'empreinte SHA-256 du jeton est en base. */
final class Auth
{
    public const SCOPES = ['ingest', 'read', 'tablet'];

    /**
     * Vérifie le jeton de la requête et sa portée. Répond 401/403 et renvoie null en cas d'échec.
     * @return array<string,mixed>|null la ligne api_token
     */
    public static function requireToken(string $scope): ?array
    {
        $header = Http::header('Authorization');
        if ($header === null || !preg_match('/^Bearer\s+([A-Za-z0-9_\-]{20,128})$/', $header, $m)) {
            header('WWW-Authenticate: Bearer');
            Http::error(401, 'unauthorized', 'Jeton manquant ou invalide.');
            return null;
        }
        $token = self::lookup($m[1]);
        if ($token === null) {
            header('WWW-Authenticate: Bearer');
            Http::error(401, 'unauthorized', 'Jeton manquant ou invalide.');
            return null;
        }
        if (!in_array($scope, explode(',', (string) $token['scopes']), true)) {
            Http::error(403, 'forbidden', "Ce jeton n'a pas la portée « $scope ».");
            return null;
        }
        self::touch((int) $token['id']);
        return $token;
    }

    /**
     * Jeton valide ayant cette portée (page tablette : jeton passé dans l'adresse ou le cookie).
     * @return array<string,mixed>|null la ligne api_token
     */
    public static function checkToken(string $value, string $scope): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{20,128}$/', $value)) {
            return null;
        }
        $token = self::lookup($value);
        if ($token === null || !in_array($scope, explode(',', (string) $token['scopes']), true)) {
            return null;
        }
        self::touch((int) $token['id']);
        return $token;
    }

    /** @return array<string,mixed>|null */
    private static function lookup(string $value): ?array
    {
        return Db::one('SELECT id, name, scopes FROM api_token WHERE token_hash = ? AND revoked_at IS NULL', [hash('sha256', $value)]);
    }

    private static function touch(int $id): void
    {
        Db::run(
            'UPDATE api_token SET last_used_at = UTC_TIMESTAMP()
              WHERE id = ? AND (last_used_at IS NULL OR last_used_at < UTC_TIMESTAMP() - INTERVAL 1 MINUTE)',
            [$id]
        );
    }

    /**
     * Lecture des données : session du site ou jeton de portée « read ».
     * @return bool false si une réponse d'erreur a été envoyée
     */
    public static function requireRead(): bool
    {
        if (Http::capturing()) {
            return true;
        }
        if (Http::header('Authorization') === null && Session::loggedIn()) {
            return true;
        }
        return self::requireToken('read') !== null;
    }

    /** Crée un jeton et renvoie sa valeur en clair (affichée une seule fois). @param string[] $scopes */
    public static function createToken(string $name, array $scopes): string
    {
        foreach ($scopes as $scope) {
            if (!in_array($scope, self::SCOPES, true)) {
                throw new \InvalidArgumentException("Portée inconnue : $scope");
            }
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Db::run(
            'INSERT INTO api_token (name, token_hash, scopes, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())',
            [$name, hash('sha256', $token), implode(',', $scopes)]
        );
        return $token;
    }
}
