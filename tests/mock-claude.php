<?php
// Fausse API Messages pour essayer le chat sans clé ni dépense. Dans le conteneur php :
//   php -S 127.0.0.1:9999 tests/mock-claude.php   puis dans .env : ANTHROPIC_API_KEY=test-key
//   et ANTHROPIC_BASE_URL=http://127.0.0.1:9999. Vérifie la forme des requêtes (outils stricts, repli,
//   contenu renvoyé à l'identique, identifiants des tool_result) et simule : REFUS, ERREUR529, BOUCLE,
//   FALLBACK400, LENT (23 s) dans la question. Requêtes et réponses gardées dans /tmp/mock.
$dir = '/tmp/mock';
@mkdir($dir);
$raw = file_get_contents('php://input');
$req = json_decode($raw);
$n = count(glob("$dir/req-*.json")) + 1;
file_put_contents(sprintf('%s/req-%02d.json', $dir, $n), $raw);
$h = function ($k) { return $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] ?? null; };
$problems = [];
if ($h('x-api-key') !== 'test-key') $problems[] = 'x-api-key';
if ($h('anthropic-version') !== '2023-06-01') $problems[] = 'version';
if (($req->model ?? '') !== 'claude-opus-5-5') $problems[] = 'model';
if (isset($req->thinking)) $problems[] = 'thinking present';
if (isset($req->tool_choice)) $problems[] = 'tool_choice present';
foreach ($req->tools as $t) {
    if (($t->strict ?? false) !== true || ($t->input_schema->additionalProperties ?? null) !== false || !is_object($t->input_schema->properties)) $problems[] = 'tool ' . $t->name;
}
if (!is_array($req->system) || !isset($req->system[0]->cache_control)) $problems[] = 'system cache';
$msgs = $req->messages;
for ($i = 0; $i < count($msgs); $i++) if ($msgs[$i]->role !== ($i % 2 ? 'assistant' : 'user')) $problems[] = "role $i";
$first = $msgs[count($msgs) - 1];
$question = '';
foreach ($msgs as $m) if ($m->role === 'user' && is_string($m->content)) $question = $m->content;
$send = function ($code, $body) use ($dir, $n) {
    http_response_code($code);
    header('Content-Type: application/json');
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    file_put_contents(sprintf('%s/resp-%02d.json', $dir, $n), $json);
    echo $json;
    exit;
};
if ($problems) $send(400, ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'mock: ' . implode(', ', $problems)]]);
if (strpos($question, 'FALLBACK400') !== false && isset($req->fallbacks)) $send(400, ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'fallbacks: not supported for this model']]);
if (strpos($question, 'FALLBACK400') === false && (($req->fallbacks ?? null) !== 'default' || $h('anthropic-beta') !== 'server-side-fallback-2026-07-01')) $send(400, ['type' => 'error', 'error' => ['message' => 'mock: fallbacks/beta']]);
if (strpos($question, 'LENT') !== false && is_string($first->content)) sleep(23);
if (strpos($question, 'ERREUR529') !== false) $send(529, ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]);
$usage = ['input_tokens' => 1200, 'output_tokens' => 300, 'cache_creation_input_tokens' => 2500, 'cache_read_input_tokens' => 0];
if (strpos($question, 'REFUS') !== false) $send(200, ['id' => 'm', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'content' => [], 'stop_reason' => 'refusal', 'stop_details' => ['category' => null], 'usage' => $usage]);
// Contrôle de l'historique : le dernier message assistant doit être identique à la dernière réponse envoyée.
if (is_array($first->content) && ($first->content[0]->type ?? '') === 'tool_result') {
    $prev = json_decode(file_get_contents(sprintf('%s/resp-%02d.json', $dir, $n - 1)));
    $asst = $msgs[count($msgs) - 2];
    if (json_encode($asst->content) !== json_encode($prev->content)) $send(400, ['error' => ['message' => 'mock: contenu assistant modifié']]);
    $ids = array_map(function ($b) { return $b->tool_use_id; }, $first->content);
    $want = array_values(array_map(function ($b) { return $b->id; }, array_filter($prev->content, function ($b) { return $b->type === 'tool_use'; })));
    if ($ids !== $want) $send(400, ['error' => ['message' => 'mock: tool_result ids']]);
}
$tool = function ($id, $name, $input) { return ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]; };
$thinking = ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig' . $n];
if (strpos($question, 'BOUCLE') !== false) {
    $send(200, ['id' => 'm', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'tool_use', 'usage' => $usage,
        'content' => [$thinking, $tool('tu' . $n, 'get_dashboard', new stdClass())]]);
}
if (is_string($first->content)) {
    $send(200, ['id' => 'm', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'tool_use', 'usage' => $usage,
        'content' => [$thinking, $tool('tu_a' . $n, 'get_summary', ['metric' => 'elec_index', 'period' => 'month', 'from' => '2026-09-01', 'to' => '2026-10-09']),
            $tool('tu_b' . $n, 'get_dashboard', new stdClass()), $tool('tu_c' . $n, 'get_series', ['metric' => 'temp_outdoor', 'from' => '2026-01-01', 'to' => '2026-03-01', 'step' => 'hour'])]]);
}
$summary = json_decode($first->content[0]->content, true);
$sept = $summary['rows'][0] ?? null;
$err = $first->content[2]->is_error ?? false;
$text = "Tu as consommé **" . ($sept ? str_replace('.', ',', (string) round($sept['energy_kwh'], 1)) : '?') . " kWh** en septembre, pour " . ($sept ? $sept['cost_eur'] : '?') . " €.\n\n- Résultat d'erreur reçu pour la 3e lecture : " . ($err ? 'oui (' . $first->content[2]->content . ')' : 'non') . "\n- Mesures dans l'état actuel : " . count(json_decode($first->content[1]->content, true)['latest']) . "\n\nQuestions déjà posées : " . (count($msgs) - 3) / 2;
$send(200, ['id' => 'm', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn',
    'usage' => ['input_tokens' => 400, 'output_tokens' => 120, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 3700],
    'content' => [$thinking, ['type' => 'text', 'text' => $text]]]);
