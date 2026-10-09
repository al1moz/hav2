<?php
declare(strict_types=1);

namespace Conso\Api;

use Conso\Db;
use Conso\Http;
use Conso\Time;

/** GET /api/v1/health : test de disponibilité, sans authentification et sans donnée. */
final class Health
{
    public static function get(): void
    {
        try {
            Db::one('SELECT 1');
            Http::json(200, ['status' => 'ok', 'time' => Time::iso(time())]);
        } catch (\Throwable $e) {
            Http::json(503, ['status' => 'error', 'time' => Time::iso(time())]);
        }
    }
}
