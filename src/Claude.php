<?php
declare(strict_types=1);

namespace Conso;

/**
 * Appel de l'API Messages d'Anthropic (HTTP brut : le SDK PHP demande PHP 8 et Composer).
 * La clé ANTHROPIC_API_KEY reste dans le .env du serveur, jamais dans le navigateur.
 * Les réponses sont décodées en objets (stdClass) pour être renvoyées à l'identique dans
 * la boucle d'outils : un {} vide reste un objet, et les blocs de réflexion restent valides.
 */
final class Claude
{
    public const MODEL = 'claude-opus-5-5';
    private const VERSION = '2023-06-01';
    /** Repli automatique si les filtres de sécurité refusent la requête (choix du modèle par Anthropic). */
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
    private const RETRY_STATUS = [0, 429, 500, 502, 503, 504, 529];

    /** Prix en dollars par million de jetons (Opus 5.5) : entrée, sortie, écriture et lecture du cache (5 min). */
    private const PRICE_IN = 4.0;
    private const PRICE_OUT = 20.0;
    private const PRICE_CACHE_WRITE = 5.0;
    private const PRICE_CACHE_READ = 0.4;

    /** @var bool repli refusé par l'API pendant cette requête */
    private static $noFallback = false;

    public static function apiKey(): string
    {
        return trim((string) Config::get('ANTHROPIC_API_KEY', ''));
    }

    public static function model(): string
    {
        return (string) Config::get('ANTHROPIC_MODEL', self::MODEL);
    }

    /**
     * Envoie une requête et renvoie [statut HTTP, réponse décodée] ; statut 0 si le réseau a échoué.
     * Une seule nouvelle tentative après une surcharge ou une coupure.
     * @param array<string,mixed> $body
     * @param callable|null $wait appelé régulièrement pendant l'attente (garde la connexion du navigateur ouverte)
     * @return array{0:int,1:mixed}
     */
    public static function send(array $body, ?callable $wait = null): array
    {
        $betas = [];
        if (!self::$noFallback) {
            $body['fallbacks'] = 'default';
            $betas = [self::FALLBACK_BETA];
        }
        [$status, $data] = self::post($body, $betas, $wait);
        if ($status === 400 && $betas && stripos(self::errorMessage($data), 'fallback') !== false) {
            // Repli refusé pour ce modèle ou ce compte : la requête passe sans lui, et les suivantes aussi.
            self::$noFallback = true;
            unset($body['fallbacks']);
            $betas = [];
            [$status, $data] = self::post($body, $betas, $wait);
        }
        if (in_array($status, self::RETRY_STATUS, true)) {
            sleep(2);
            [$status, $data] = self::post($body, $betas, $wait);
        }
        return [$status, $data];
    }

    /** @param mixed $data */
    public static function errorMessage($data): string
    {
        if (is_object($data) && isset($data->error->message) && is_string($data->error->message)) {
            return $data->error->message;
        }
        return '';
    }

    /**
     * Contenu de la réponse à renvoyer dans la conversation. Après un repli en cours de réponse
     * (bloc « fallback »), seuls les textes qui le précèdent sont gardés : les blocs de réflexion
     * et d'outils du modèle qui a refusé ne doivent pas être renvoyés.
     * @param array<int,object> $content
     * @return array<int,object>
     */
    public static function replayable(array $content): array
    {
        $last = -1;
        foreach ($content as $i => $block) {
            if (($block->type ?? '') === 'fallback') {
                $last = $i;
            }
        }
        $out = [];
        foreach ($content as $i => $block) {
            if ($i < $last && ($block->type ?? '') !== 'text') {
                continue;
            }
            $out[] = $block;
        }
        return $out;
    }

    /** Texte de la réponse (blocs « text », les blocs de réflexion sont ignorés). @param array<int,object> $content */
    public static function text(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if (($block->type ?? '') === 'text' && isset($block->text)) {
                $parts[] = (string) $block->text;
            }
        }
        return trim(implode("\n\n", $parts));
    }

    /**
     * Jetons consommés par une réponse (toutes les tentatives si un repli a eu lieu).
     * @param mixed $usage
     * @return array{in:int,out:int,cache_write:int,cache_read:int}
     */
    public static function tokens($usage): array
    {
        $sum = ['in' => 0, 'out' => 0, 'cache_write' => 0, 'cache_read' => 0];
        if (!is_object($usage)) {
            return $sum;
        }
        $parts = isset($usage->iterations) && is_array($usage->iterations) && $usage->iterations ? $usage->iterations : [$usage];
        foreach ($parts as $u) {
            $sum['in'] += (int) ($u->input_tokens ?? 0);
            $sum['out'] += (int) ($u->output_tokens ?? 0);
            $sum['cache_write'] += (int) ($u->cache_creation_input_tokens ?? 0);
            $sum['cache_read'] += (int) ($u->cache_read_input_tokens ?? 0);
        }
        return $sum;
    }

    /** Coût estimé en dollars, au prix d'Opus 5.5. @param array{in:int,out:int,cache_write:int,cache_read:int} $t */
    public static function cost(array $t): float
    {
        return ($t['in'] * self::PRICE_IN + $t['out'] * self::PRICE_OUT
            + $t['cache_write'] * self::PRICE_CACHE_WRITE + $t['cache_read'] * self::PRICE_CACHE_READ) / 1e6;
    }

    /**
     * @param array<string,mixed> $body
     * @param string[] $betas
     * @return array{0:int,1:mixed}
     */
    private static function post(array $body, array $betas, ?callable $wait): array
    {
        $url = rtrim((string) Config::get('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/') . '/v1/messages';
        $headers = ['content-type: application/json', 'x-api-key: ' . self::apiKey(), 'anthropic-version: ' . self::VERSION];
        if ($betas) {
            $headers[] = 'anthropic-beta: ' . implode(',', $betas);
        }
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $options = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 120,
            ];
            if ($wait !== null) {
                $options[CURLOPT_NOPROGRESS] = false;
                $options[CURLOPT_PROGRESSFUNCTION] = function () use ($wait): int {
                    $wait();
                    return 0;
                };
            }
            curl_setopt_array($ch, $options);
            $raw = curl_exec($ch);
            $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($raw === false) {
                error_log('Claude : ' . curl_error($ch));
            }
            curl_close($ch);
        } else {
            $context = stream_context_create(['http' => [
                'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $json,
                'timeout' => 120, 'ignore_errors' => true,
            ]]);
            $raw = @file_get_contents($url, false, $context);
            $status = 0;
            if ($raw !== false && isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }
        }
        $data = is_string($raw) ? json_decode($raw) : null;
        if ($status !== 200) {
            error_log('Claude : HTTP ' . $status . ' ' . self::errorMessage($data));
        }
        return [$status, $data];
    }
}
