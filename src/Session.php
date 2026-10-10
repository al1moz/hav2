<?php
declare(strict_types=1);

namespace Conso;

/**
 * Connexion au site : un seul mot de passe (haché dans setting.password_hash),
 * session de 30 jours après la dernière visite (rangée dans MySQL), jeton CSRF pour tous les formulaires.
 */
final class Session
{
    private const LIFETIME = 30 * 86400;
    private const MAX_FAILURES = 5;
    private const FAILURE_WINDOW_MIN = 15;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.gc_maxlifetime', (string) self::LIFETIME);
        ini_set('session.use_strict_mode', '1');
        session_name('consov2');
        session_set_cookie_params([
            'lifetime' => self::LIFETIME,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (SessionStore::available()) {
            session_set_save_handler(new SessionStore(self::LIFETIME), true);
        }
        session_start();
        // Échéance glissante : chaque visite connectée repousse la fin du cookie de 30 jours.
        if (!empty($_SESSION['auth']) && isset($_COOKIE['consov2'])) {
            setcookie('consov2', session_id(), [
                'expires' => time() + self::LIFETIME, 'path' => '/', 'secure' => self::isHttps(), 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
    }

    public static function loggedIn(): bool
    {
        if (!isset($_COOKIE['consov2'])) {
            return false;
        }
        self::start();
        return !empty($_SESSION['auth']);
    }

    /** Vérifie le mot de passe. Renvoie null si connecté, sinon le message d'erreur. */
    public static function login(string $password): ?string
    {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $failures = (int) Db::one(
            'SELECT COUNT(*) AS c FROM login_attempt WHERE ip = ? AND ts > UTC_TIMESTAMP() - INTERVAL ' . self::FAILURE_WINDOW_MIN . ' MINUTE',
            [$ip]
        )['c'];
        if ($failures >= self::MAX_FAILURES) {
            return 'Trop de tentatives. Réessaie dans ' . self::FAILURE_WINDOW_MIN . ' minutes.';
        }
        $hash = Settings::get('password_hash');
        if ($hash === '') {
            return 'Aucun mot de passe défini. Lance : php bin/password.php';
        }
        if (!password_verify($password, $hash)) {
            Db::run('INSERT INTO login_attempt (ip, ts) VALUES (?, UTC_TIMESTAMP())', [$ip]);
            Db::run('DELETE FROM login_attempt WHERE ts < UTC_TIMESTAMP() - INTERVAL 1 DAY');
            return 'Mot de passe incorrect.';
        }
        Db::run('DELETE FROM login_attempt WHERE ip = ?', [$ip]);
        self::start();
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return null;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
        setcookie('consov2', '', ['expires' => 1, 'path' => '/', 'secure' => self::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    public static function csrf(): string
    {
        self::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(): bool
    {
        $sent = $_POST['csrf'] ?? '';
        return is_string($sent) && $sent !== '' && hash_equals(self::csrf(), $sent);
    }

    /** Message affiché une seule fois après une redirection. */
    public static function flash(?string $message = null): ?string
    {
        self::start();
        if ($message !== null) {
            $_SESSION['flash'] = $message;
            return null;
        }
        $value = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $value;
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
