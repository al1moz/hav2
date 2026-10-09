<?php
declare(strict_types=1);

namespace Conso;

/** Petites aides pour lire la requête et répondre en JSON. */
final class Http
{
    /** @param mixed $data */
    public static function json(int $status, $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @param array<string,mixed> $extra */
    public static function error(int $status, string $code, string $message, array $extra = []): void
    {
        self::json($status, ['error' => array_merge(['code' => $code, 'message' => $message], $extra)]);
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }
        if ($name === 'Authorization' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return null;
    }

    public static function query(string $name): ?string
    {
        $value = $_GET[$name] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Corps JSON de la requête, ou null s'il est absent ou invalide. @return mixed */
    public static function jsonBody()
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }
}
