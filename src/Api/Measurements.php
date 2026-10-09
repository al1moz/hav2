<?php
declare(strict_types=1);

namespace Conso\Api;

use Conso\Auth;
use Conso\Http;
use Conso\Ingest;

/** POST /api/v1/measurements : réception d'un lot de mesures envoyé par l'add-on. */
final class Measurements
{
    public static function post(): void
    {
        $token = Auth::requireToken('ingest');
        if ($token === null) {
            return;
        }
        [$status, $body] = Ingest::handle(Http::jsonBody(), (int) $token['id'], Http::header('Idempotency-Key'));
        Http::json($status, $body);
    }
}
