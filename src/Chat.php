<?php
declare(strict_types=1);

namespace Conso;

/**
 * Discussion avec Claude (bouton « Demander à Claude » des pages du site).
 * POST /api/v1/chat {question, history: [{q, a}]} : Claude lit les données avec les outils
 * de ChatTools (lecture seule), puis répond. La réponse arrive en lignes JSON :
 * {"type":"step"} pour chaque lecture, puis {"type":"answer"} ou {"type":"error"}.
 */
final class Chat
{
    /** Tours d'outils au plus ; ensuite Claude doit répondre avec ce qu'il a lu. */
    private const MAX_TURNS = 8;
    private const MAX_QUESTION = 2000;
    private const MAX_HISTORY = 6;
    private const MAX_ANSWER_KEPT = 6000;
    private const MAX_RESULT_BYTES = 60000;
    private const DEADLINE_S = 240;
    private const KEEPALIVE_S = 10;

    public const EFFORTS = [
        'low' => 'Faible : rapide et économique',
        'medium' => 'Moyenne',
        'high' => 'Élevée : plus lent et plus cher',
    ];

    /** @var int */
    private static $lastOutput = 0;

    public static function available(): bool
    {
        return Claude::apiKey() !== '' && Settings::get('chat_enabled', '1') === '1';
    }

    public static function post(): void
    {
        if (!Session::loggedIn()) {
            Http::error(401, 'unauthorized', 'Connexion requise.');
            return;
        }
        $csrf = Http::header('X-CSRF-Token');
        if ($csrf === null || !hash_equals(Session::csrf(), $csrf)) {
            Http::error(403, 'csrf', 'Page expirée : recharge-la.');
            return;
        }
        // La session n'est plus utile : la libérer pour que les autres pages ne l'attendent pas.
        session_write_close();
        if (!self::available()) {
            Http::error(503, 'chat_disabled', Claude::apiKey() === '' ? 'Clé ANTHROPIC_API_KEY absente du .env.' : 'Discussion désactivée dans l\'administration.');
            return;
        }
        $body = Http::jsonBody();
        $question = is_array($body) && is_string($body['question'] ?? null) ? trim($body['question']) : '';
        if ($question === '' || mb_strlen($question) > self::MAX_QUESTION) {
            Http::error(422, 'invalid_question', 'Question vide ou trop longue (' . self::MAX_QUESTION . ' caractères au plus).');
            return;
        }
        $limit = Settings::int('chat_daily_limit', 30);
        if (self::countToday() >= $limit) {
            Http::error(429, 'daily_limit', "Limite de $limit questions par jour atteinte (réglable dans l'administration).");
            return;
        }

        Db::run('INSERT INTO chat_log (created_at, question, status, model) VALUES (UTC_TIMESTAMP(), ?, ?, ?)', [$question, 'running', Claude::model()]);
        $id = (int) Db::pdo()->lastInsertId();

        ignore_user_abort(true);
        set_time_limit(self::DEADLINE_S + 60);
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $started = microtime(true);
        $log = ['status' => 'error', 'answer' => null, 'error' => null, 'tools' => [], 'turns' => 0, 'model' => Claude::model(),
            'tokens' => ['in' => 0, 'out' => 0, 'cache_write' => 0, 'cache_read' => 0]];
        try {
            $log = self::converse($question, self::history(is_array($body) ? ($body['history'] ?? null) : null), $log);
        } catch (\Throwable $e) {
            error_log((string) $e);
            $log['error'] = substr($e->getMessage(), 0, 500);
        }
        $cost = Claude::cost($log['tokens']);
        if ($log['answer'] !== null) {
            self::emit(['type' => 'answer', 'text' => $log['answer'], 'status' => $log['status'], 'cost_usd' => round($cost, 4)]);
        } else {
            self::emit(['type' => 'error', 'text' => self::userError($log['error'])]);
        }
        Db::run(
            'UPDATE chat_log SET answer = ?, tools = ?, status = ?, error = ?, model = ?, turns = ?, input_tokens = ?, output_tokens = ?,
                    cache_read_tokens = ?, cache_write_tokens = ?, cost_usd = ?, duration_ms = ? WHERE id = ?',
            [
                $log['answer'], json_encode($log['tools'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $log['status'],
                $log['error'] === null ? null : substr($log['error'], 0, 500), substr((string) $log['model'], 0, 64), $log['turns'],
                $log['tokens']['in'], $log['tokens']['out'], $log['tokens']['cache_read'], $log['tokens']['cache_write'],
                round($cost, 5), (int) round((microtime(true) - $started) * 1000), $id,
            ]
        );
    }

    /**
     * La boucle : question, appels d'outils, réponse. Le contenu de chaque réponse est renvoyé
     * tel quel au tour suivant (réflexion comprise) ; l'historique n'est jamais modifié.
     * @param array<int,array{0:string,1:string}> $history
     * @param array<string,mixed> $log
     * @return array<string,mixed>
     */
    private static function converse(string $question, array $history, array $log): array
    {
        $messages = [];
        foreach ($history as [$q, $a]) {
            $messages[] = ['role' => 'user', 'content' => $q];
            $messages[] = ['role' => 'assistant', 'content' => $a];
        }
        $messages[] = ['role' => 'user', 'content' => $question];
        $effort = Settings::get('chat_effort', 'low');
        $request = [
            'model' => Claude::model(),
            'max_tokens' => 16000,
            'output_config' => ['effort' => isset(self::EFFORTS[$effort]) ? $effort : 'low'],
            'cache_control' => ['type' => 'ephemeral'],
            'system' => [
                ['type' => 'text', 'text' => self::systemPrompt(), 'cache_control' => ['type' => 'ephemeral']],
                ['type' => 'text', 'text' => self::now()],
            ],
            'tools' => ChatTools::definitions(),
        ];
        $deadline = time() + self::DEADLINE_S;
        $wait = function (): void {
            if (time() - self::$lastOutput >= self::KEEPALIVE_S) {
                self::emit(null);
            }
        };
        self::emit(['type' => 'step', 'text' => 'Lecture de la question']);

        for ($turn = 1; $turn <= self::MAX_TURNS + 1; $turn++) {
            if (time() > $deadline) {
                $log['error'] = 'deadline';
                return $log;
            }
            if (connection_aborted()) {
                $log['status'] = 'abandon'; // page quittée : inutile de payer la suite
                $log['error'] = 'abandon';
                return $log;
            }
            $request['messages'] = $messages;
            [$code, $resp] = Claude::send($request, $wait);
            $log['turns'] = $turn;
            if ($code !== 200 || !is_object($resp) || !isset($resp->content) || !is_array($resp->content)) {
                $log['error'] = 'HTTP ' . $code . ($code === 0 ? '' : ' ' . Claude::errorMessage($resp));
                return $log;
            }
            foreach (Claude::tokens($resp->usage ?? null) as $k => $n) {
                $log['tokens'][$k] += $n;
            }
            $log['model'] = (string) ($resp->model ?? $log['model']);
            $stop = (string) ($resp->stop_reason ?? '');
            if ($stop === 'refusal') {
                $log['status'] = 'refusal';
                $log['answer'] = 'Claude a refusé de répondre à cette question.';
                return $log;
            }
            $content = Claude::replayable($resp->content);
            $uses = array_values(array_filter($content, function ($b): bool {
                return ($b->type ?? '') === 'tool_use';
            }));
            if ($stop !== 'tool_use' || !$uses) {
                $text = Claude::text($content);
                $log['status'] = $stop === 'max_tokens' ? 'max_tokens' : ($text === '' ? 'empty' : 'ok');
                $log['answer'] = $text === '' ? 'Pas de réponse.' : $text . ($stop === 'max_tokens' ? "\n\n(Réponse coupée.)" : '');
                return $log;
            }
            if ($turn > self::MAX_TURNS) {
                $log['status'] = 'steps';
                $log['answer'] = 'La question demande trop d\'étapes. Essaie de la découper en questions plus simples.';
                return $log;
            }

            $messages[] = ['role' => 'assistant', 'content' => $content];
            $results = [];
            foreach ($uses as $use) {
                $input = isset($use->input) ? json_decode((string) json_encode($use->input), true) : [];
                $input = is_array($input) ? $input : [];
                $name = (string) ($use->name ?? '');
                if ($turn === self::MAX_TURNS) {
                    $data = null;
                    $error = 'Limite d\'étapes atteinte : réponds maintenant avec les données déjà lues.';
                } else {
                    self::emit(['type' => 'step', 'text' => ChatTools::label($name, $input)]);
                    [$data, $error] = ChatTools::run($name, $input);
                }
                $json = $error === null ? (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) : '';
                if ($error === null && strlen($json) > self::MAX_RESULT_BYTES) {
                    $error = 'Résultat trop volumineux : réduis la période ou regroupe par mois.';
                }
                $log['tools'][] = ['name' => $name, 'input' => $input] + ($error === null ? [] : ['error' => $error]);
                $results[] = $error === null
                    ? ['type' => 'tool_result', 'tool_use_id' => (string) $use->id, 'content' => $json]
                    : ['type' => 'tool_result', 'tool_use_id' => (string) $use->id, 'content' => $error, 'is_error' => true];
            }
            $messages[] = ['role' => 'user', 'content' => $results];
            self::emit(['type' => 'step', 'text' => 'Rédaction de la réponse']);
        }
        return $log;
    }

    /**
     * Échanges précédents envoyés par le navigateur, en texte seul (sans la réflexion ni les outils).
     * @param mixed $raw
     * @return array<int,array{0:string,1:string}>
     */
    public static function history($raw): array
    {
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach (array_slice(array_values($raw), -self::MAX_HISTORY) as $turn) {
            if (!is_array($turn) || !is_string($turn['q'] ?? null) || !is_string($turn['a'] ?? null)) {
                continue;
            }
            $q = trim($turn['q']);
            $a = trim($turn['a']);
            if ($q !== '' && $a !== '') {
                $out[] = [mb_substr($q, 0, self::MAX_QUESTION), mb_substr($a, 0, self::MAX_ANSWER_KEPT)];
            }
        }
        return $out;
    }

    /** Consignes, liste des mesures et contexte de la maison : identiques d'une question à l'autre (mis en cache). */
    private static function systemPrompt(): string
    {
        $metrics = [];
        foreach (Metrics::all() as $m) {
            $metrics[] = '- ' . $m['code'] . ' : ' . $m['label'] . ' (' . $m['unit'] . ', ' . $m['source']
                . ($m['kind'] === 'counter' ? ', index' : '') . ($m['energy_factor'] !== null ? ', donne des kWh' : '') . ')';
        }
        $context = trim(Settings::get('chat_context'));
        return "Tu réponds aux questions du propriétaire d'une maison sur sa consommation d'énergie, à partir des mesures de son site ConsoV2. "
            . "Tu parles français, simplement, et tu commences par la réponse.\n\n"
            . "Règles :\n"
            . "- Chaque chiffre vient d'un outil. Ne devine jamais une valeur : si une donnée manque ou n'est pas fiable, dis-le.\n"
            . "- Les outils prennent des journées locales (heure de Paris) au format AAAA-MM-JJ. Pour « cet hiver », « le mois dernier »… "
            . "pars de la date du jour donnée plus bas.\n"
            . "- elec_index est le compteur Linky : la consommation totale de la maison. Les circuit_* sont mesurés à part par des Shelly ; "
            . "le « reste » est ce qu'aucun circuit ne mesure.\n"
            . "- Les coûts sont en euros TTC, au prix du kWh en vigueur à chaque date.\n"
            . "- Réponse courte : quelques phrases, chiffres avec leur unité et arrondis (kWh à une décimale, euros au centime sous 10 €, "
            . "à l'euro au-delà). Texte simple : listes à tirets si utile, **gras** pour le chiffre principal, ni tableau ni titre.\n"
            . "- Si la question ne concerne ni la maison, ni ses mesures, ni son énergie, dis-le en une phrase.\n\n"
            . "Mesures disponibles (code : libellé, unité, source) :\n" . implode("\n", $metrics)
            . ($context === '' ? '' : "\n\nCe qu'il faut savoir sur la maison :\n" . $context);
    }

    private static function now(): string
    {
        $now = new \DateTime('now', Config::timezone());
        return 'Nous sommes le ' . Tablet::frenchDate($now) . ' ' . $now->format('Y') . ', il est ' . $now->format('H:i') . ' (heure de Paris).';
    }

    private static function countToday(): int
    {
        $midnight = (new \DateTimeImmutable('now', Config::timezone()))->setTime(0, 0)->getTimestamp();
        return (int) Db::one('SELECT COUNT(*) AS c FROM chat_log WHERE created_at >= ?', [Time::toDb($midnight)])['c'];
    }

    private static function userError(?string $error): string
    {
        $code = $error !== null && preg_match('/^HTTP (\d+)/', $error, $m) ? (int) $m[1] : -1;
        switch (true) {
            case $code === 0:
                return 'Le serveur n\'arrive pas à joindre l\'API d\'Anthropic. Réessaie dans un moment.';
            case $code === 401 || $code === 403:
                return 'Clé d\'API refusée : vérifie ANTHROPIC_API_KEY dans le .env du serveur.';
            case $code === 429:
                return 'Limite de l\'API atteinte. Réessaie dans une minute.';
            case $code >= 500:
                return 'Le service de Claude est surchargé ou indisponible. Réessaie plus tard.';
            case $code === 400:
                return 'Requête refusée par l\'API : ' . substr((string) $error, 9);
            case $error === 'deadline':
                return 'La réponse prenait trop de temps. Essaie une question plus simple.';
        }
        return 'Erreur interne, voir les journaux du conteneur php.';
    }

    /** Une ligne JSON, ou une ligne vide pour garder la connexion ouverte. @param array<string,mixed>|null $event */
    private static function emit(?array $event): void
    {
        echo ($event === null ? '' : json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n";
        flush();
        self::$lastOutput = time();
    }
}
