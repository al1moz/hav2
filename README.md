# ConsoV2

Nouveau site de suivi de consommation : API de réception des mesures (add-on Home Assistant)
et pages du site (Aujourd'hui, Électricité, Chauffage, Températures, Humidité, Comparer, Administration). PHP 7.4 natif sans framework, MySQL 5.7.

## Démarrer en local

```sh
cp .env.example .env        # puis remplacer chaque « a-remplacer »
docker compose up -d --build
```

Au premier démarrage, MySQL crée la base `consov2` avec `sql/001_schema.sql` et `sql/002_seed.sql`.
Le site répond sur http://127.0.0.1:8080 et MySQL sur 127.0.0.1:3307 (ports réglables avec `WEB_PORT` et `DB_LOCAL_PORT` dans `.env`).

Puis, une fois la base prête :

```sh
docker compose exec php php bin/migrate.php    # applique les mises à jour de la base (à relancer après chaque mise à jour du code)
docker compose exec php php bin/password.php   # mot de passe du site (12 caractères au moins)
```

Le site est entièrement privé : toutes les pages demandent ce mot de passe.

Sans add-on branché sur le Docker local, des fausses mesures crédibles remplissent les pages
(refusé si `APP_DEBUG` n'est pas à 1, donc jamais en production) :

```sh
docker compose exec php php bin/fake-data.php --days=30   # historique, sans écraser ce qui existe
docker compose exec php php bin/fake-data.php --live      # puis une mesure toutes les 5 minutes
```

Contrôles :

```sh
curl http://127.0.0.1:8080/api/v1/health
docker compose exec php php tests/unit.php
```

## Jetons d'API

```sh
docker compose exec php php bin/token.php create addon-maison ingest   # pour l'add-on
docker compose exec php php bin/token.php create lecture read          # pour lire les données
docker compose exec php php bin/token.php list
docker compose exec php php bin/token.php revoke <id>
```

Le jeton n'est affiché qu'une fois. Seule son empreinte SHA-256 est en base.
Test de bout en bout : `INGEST=<jeton> READ=<jeton> sh tests/smoke.sh`.

## Page tablette

`/tablette` : horloge, sondes Netatmo, METAR de l'aérodrome et webcam en fond, réglés dans
l'administration (section Tablette). Les valeurs se rechargent chaque minute, la webcam toutes les 5 minutes,
sans recharger la page. Le thème « Nuit » peut remplacer celui de la tablette de 22 h à 7 h.

La tablette n'a pas besoin du mot de passe du site : créer un jeton de portée `tablet`, puis ouvrir
une fois sur la tablette l'adresse affichée (`/tablette?jeton=…`). Le jeton est gardé dans un cookie
qui n'ouvre que cette page ; le révoquer dans l'administration coupe l'accès.

Si la tablette est déjà réglée sur une ancienne adresse, la mettre dans `.env` (`TABLET_LEGACY_PATH`) :
la page y répond aussi, **sans jeton ni mot de passe**, et peut s'afficher dans un iframe (Home Assistant).
Ne pas la diffuser. Le serveur web doit alors passer les adresses en `.php` au routeur
(voir `docker/nginx/default.conf`).

De nuit aéronautique (fin du crépuscule civil à l'aube civile, à la position de la station donnée
par le METAR), la case Condition affiche « NUIT » au lieu de VFR, avec l'heure du prochain changement.

## Demander à Claude

Un bouton en bas de chaque page ouvre une discussion : Claude (API d'Anthropic, modèle `claude-opus-5-5`)
lit les données avec des outils en lecture seule (`src/ChatTools.php`) puis répond en français.

- Clé d'API dans `.env` : `ANTHROPIC_API_KEY=…`. Sans clé, le bouton n'apparaît pas.
- Administration, section « Discussion avec Claude » : bouton affiché ou non, questions par jour,
  niveau de réflexion, ce que Claude doit savoir sur la maison, journal des questions avec leur coût estimé.
- Si les filtres de sécurité d'Anthropic refusaient une question, l'API la repasse sur un autre modèle
  (`fallbacks: "default"`).
- Une réponse peut demander plusieurs appels : nginx doit laisser 300 s à PHP (`fastcgi_read_timeout`,
  voir `docker/nginx/default.conf`). Le site envoie une ligne vide toutes les 10 s pendant l'attente.

## Reprendre l'historique de l'ancien site

En local, importer le dump dans une base `legacy`, puis lancer la migration
(l'ancienne base n'est que lue) :

```sh
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS legacy"'
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" legacy' < ../conso/conso-09-10-2026.sql
docker compose exec php php bin/migrate-releve.php           # --reset pour recommencer
```

Environ 1 min 30 pour 420 000 relevés. Le script affiche à la fin la consommation par année
pour la comparer à l'ancien site.

## API v1

| Route | Jeton | Rôle |
|---|---|---|
| `GET /api/v1/health` | aucun | test de disponibilité |
| `POST /api/v1/measurements` | ingest | lot de mesures (voir ci-dessous) |
| `GET /api/v1/metrics` | read | liste des mesures |
| `GET /api/v1/latest` | read | dernières valeurs et état de chaque source (alerte d'absence de données) |
| `GET /api/v1/series?metric=&from=&to=&step=raw\|hour\|day` | read | courbe |
| `GET /api/v1/summary?metric=&period=day\|month\|year&from=&to=` | read | totaux, moyennes, coût |
| `GET /api/v1/dashboard`, `breakdown`, `cost`, `profile`, `ecs`, `compare` | read | données préparées pour les pages |
| `POST /api/v1/chat` | session + CSRF | question à Claude, réponse en lignes JSON |

Les routes de lecture acceptent aussi la session du site (utilisées par les pages).

`from`/`to` : `AAAA-MM-JJ` (journée locale, bornes incluses) ou date ISO avec fuseau.

Envoi d'un lot :

```http
POST /api/v1/measurements
Authorization: Bearer <jeton ingest>
Idempotency-Key: <identifiant unique du lot>

{"measurements": [
  {"metric": "elec_index", "value": 27363120, "unit": "Wh", "ts": "2026-10-09T16:20:00Z"},
  {"metric": "circuit_geothermie", "value": 420, "ts": "2026-10-09T16:20:00Z"},
  {"metric": "pac_temp_depart", "source": "arkteos", "unit": "°C", "value": 34.5, "ts": "2026-10-09T16:20:00Z"}
]}
```

Réponse `202` : `{"accepted": 3, "duplicates": 0, "late": 0, "rejected": 0, "errors": []}`.

- Une mesure inconnue est créée si `source` et `unit` sont fournis (`kind`: `gauge` par défaut, ou `counter` pour un index).
- Une unité compatible est convertie (Wh vers kWh, kW vers W…).
- Renvoyer un lot ne compte jamais deux fois : même clé, même réponse ; mêmes mesures, `duplicates`.
- Un index incohérent (baisse, saut au-delà de 36 kW) est rejeté.
- `401` jeton absent, `403` mauvaise portée, `422` corps invalide : l'add-on ne renvoie pas.
  `5xx` ou réseau : l'add-on garde le lot et réessaie.

## Organisation

```
public/index.php   seul fichier exposé : routes de l'API et des pages
bootstrap.php      chargement automatique des classes de src/ et du .env
src/               Config, Db, Router, Http, Auth, Session, Time, Units, Energy, Aggregates, Ingest, Ecs, View, Pages, Admin, Tablet, Metar, Claude, Chat, ChatTools, Api/
public/assets/     app.css (8 thèmes), charts.js (graphiques SVG), app.js (remplissage des pages), chat.js (Demander à Claude), tablet.css et tablet.js (page tablette), fonts/ (Orbitron, licence OFL)
sql/               schéma et données de départ
bin/               migrate.php, password.php, token.php, migrate-releve.php, fake-data.php
tests/             unit.php (calculs), smoke.sh (API de bout en bout)
docker/            image php:7.4-fpm et configuration nginx
```

Les dates sont stockées en UTC. Les journées (`sample_daily`) suivent `APP_TIMEZONE`.
