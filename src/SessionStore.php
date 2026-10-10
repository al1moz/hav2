<?php
declare(strict_types=1);

namespace Conso;

/**
 * Sessions du site rangées dans MySQL (table web_session) plutôt que dans des fichiers
 * du conteneur php : elles survivent à un redémarrage du conteneur et aucun autre
 * programme ne peut les effacer avec un délai de nettoyage plus court.
 */
final class SessionStore implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    /** @var int */
    private $lifetime;

    public function __construct(int $lifetime)
    {
        $this->lifetime = $lifetime;
    }

    /** Vrai si la table existe (migration 009 appliquée). */
    public static function available(): bool
    {
        try {
            Db::pdo()->query('SELECT 1 FROM web_session LIMIT 0');
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function open($path, $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function read($id)
    {
        $row = Db::one(
            'SELECT data FROM web_session WHERE id = ? AND updated_at > UTC_TIMESTAMP() - INTERVAL ? SECOND',
            [$id, $this->lifetime]
        );
        return $row === null ? '' : (string) $row['data'];
    }

    public function write($id, $data): bool
    {
        Db::run(
            'INSERT INTO web_session (id, data, updated_at) VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)',
            [$id, $data]
        );
        return true;
    }

    public function destroy($id): bool
    {
        Db::run('DELETE FROM web_session WHERE id = ?', [$id]);
        return true;
    }

    #[\ReturnTypeWillChange]
    public function gc($maxlifetime)
    {
        return Db::run('DELETE FROM web_session WHERE updated_at < UTC_TIMESTAMP() - INTERVAL ? SECOND', [$this->lifetime])->rowCount();
    }

    public function validateId($id): bool
    {
        return Db::one(
            'SELECT 1 AS ok FROM web_session WHERE id = ? AND updated_at > UTC_TIMESTAMP() - INTERVAL ? SECOND',
            [$id, $this->lifetime]
        ) !== null;
    }

    public function updateTimestamp($id, $data): bool
    {
        Db::run('UPDATE web_session SET updated_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
        return true;
    }
}
