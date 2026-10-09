#!/bin/sh
# Test de bout en bout de l'API sur le Docker local. Crée deux mesures de test (smoke_power, smoke_index).
#   INGEST=<jeton ingest> READ=<jeton read> sh tests/smoke.sh
set -eu
BASE=${BASE:-http://127.0.0.1:${WEB_PORT:-8080}}
: "${INGEST:?jeton ingest manquant}" "${READ:?jeton read manquant}"
NOW=$(date -u +%s)
T1=$(date -u -d "@$((NOW - 600))" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u -r $((NOW - 600)) +%Y-%m-%dT%H:%M:%SZ)
T2=$(date -u -d "@$((NOW - 300))" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u -r $((NOW - 300)) +%Y-%m-%dT%H:%M:%SZ)
KEY="smoke-$NOW"

echo "== health";        curl -s "$BASE/api/v1/health"; echo
echo "== sans jeton";    curl -s -o /dev/null -w "%{http_code}\n" -X POST "$BASE/api/v1/measurements"
echo "== jeton read refusé en écriture"; curl -s -o /dev/null -w "%{http_code}\n" -X POST -H "Authorization: Bearer $READ" "$BASE/api/v1/measurements"

BODY=$(cat <<JSON
{"site":"maison","measurements":[
 {"source":"smoke","metric":"smoke_power","unit":"W","value":1200,"ts":"$T1"},
 {"source":"smoke","metric":"smoke_power","unit":"W","value":1200,"ts":"$T2"},
 {"source":"smoke","metric":"smoke_index","unit":"Wh","kind":"counter","value":5000,"ts":"$T1"},
 {"source":"smoke","metric":"smoke_index","unit":"Wh","kind":"counter","value":5100,"ts":"$T2"},
 {"metric":"temp_outdoor","value":"douze","ts":"$T2"}
]}
JSON
)
echo "== envoi d'un lot (attendu : accepted 4, rejected 1)"
curl -s -X POST -H "Authorization: Bearer $INGEST" -H "Content-Type: application/json" -H "Idempotency-Key: $KEY" -d "$BODY" "$BASE/api/v1/measurements"; echo
echo "== même lot, même clé (attendu : replayed)"
curl -s -X POST -H "Authorization: Bearer $INGEST" -H "Content-Type: application/json" -H "Idempotency-Key: $KEY" -d "$BODY" "$BASE/api/v1/measurements"; echo
echo "== même lot, autre clé (attendu : duplicates 4)"
curl -s -X POST -H "Authorization: Bearer $INGEST" -H "Content-Type: application/json" -H "Idempotency-Key: $KEY-bis" -d "$BODY" "$BASE/api/v1/measurements"; echo
echo "== série horaire smoke_power (attendu : 100 Wh)"
curl -s -H "Authorization: Bearer $READ" "$BASE/api/v1/series?metric=smoke_power&step=hour"; echo
echo "== série horaire smoke_index (attendu : 100 Wh)"
curl -s -H "Authorization: Bearer $READ" "$BASE/api/v1/series?metric=smoke_index&step=hour"; echo
echo "== dernières valeurs"
curl -s -H "Authorization: Bearer $READ" "$BASE/api/v1/latest" | head -c 600; echo
