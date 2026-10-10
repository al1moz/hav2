# CLAUDE.md : ConsoV2

Mémoire du projet pour Claude. À lire avant toute modification. Le README décrit l'installation ; ce fichier dit ce qu'il ne faut pas oublier.

## Le projet en une phrase

Suivi de la consommation d'une maison : un add-on Home Assistant collecte les mesures (Linky, PAC, circuits Shelly, Netatmo) et les envoie par API à ce site, qui les stocke, les agrège et les affiche.

| Dépôt | Contenu |
|---|---|
| `al1moz/hav2` (ici) | Le site ConsoV2 : API, pages, migrations, reprise de l'historique |
| `al1moz/HAPython` | L'add-on unifié `consov2/` (les anciens add-ons `teleinfo`, `arkteos`, `mycron`, `tester` en ont été retirés le 9 oct. 2026) |

Production : https://conso.ctrl.ovh, l'ancien domaine. Alain y a mis le nouveau site le 9 oct. 2026 ; à confirmer en appelant l'API.

Pour lire le site depuis un fil Claude : un seul jeton de portée `read`, rangé dans la variable d'environnement `CONSOV2_READ_TOKEN` de l'environnement cloud du projet (jamais dans le chat), puis `curl -H "Authorization: Bearer $CONSOV2_READ_TOKEN" https://conso.ctrl.ovh/api/v1/latest`. Sans jeton, seul `/api/v1/health` répond.

## Façon de travailler avec Alain

- Il écrit en français : répondre en français, simplement, en commençant par la réponse.
- **Pas de PR.** Claude modifie les fichiers dans la copie locale d'Alain (dossier `ConsoV2` de son Mac, connecté à la session), sans commit. Alain teste avec sa pile Docker locale, puis, quand il le dit, Claude fait directement un commit et un push sur `main` ; Alain fait ensuite un `git pull` en production. Ne jamais pousser sans son feu vert. Demander avant de toucher à la production.
- **Secrets** : uniquement dans `.env` (jamais versionné) ou dans les options de l'add-on. Ne jamais recopier un mot de passe ou un jeton dans le chat, un commit ou un fichier.
- **Anonymat** : ne jamais écrire le nom de la commune (ni dans le code, ni dans les commits, ni dans les messages). Attention, l'ancienne organisation InfluxDB le contient : ne jamais la mettre en valeur par défaut. Pas d'adresse IP, de coordonnées GPS ou d'identifiant de compteur dans les dépôts (les deux sont publics) : l'IP de la PAC et les noms d'entités se saisissent dans la configuration de l'add-on.
- Le site est entièrement privé : un seul mot de passe, l'administration n'a pas de protection en plus.

## Contraintes techniques (non négociables)

- **MySQL 5.7** en production (`mysql:5.7.22`, conteneur partagé avec d'autres projets) : pas de CTE, pas de fonctions de fenêtrage, pas de JSON_TABLE.
- **PHP 7.4** (`php:7.4-fpm`) : pas de syntaxe PHP 8 (pas de `match`, de types union, de propriétés promues, de `?->`, de `str_contains`).
- **Pas de framework PHP**, pas de Composer. Pas de bibliothèque de graphiques : SVG fait main (`public/assets/charts.js`).
- Add-on : Python 3 avec seulement les paquets Debian `python3-serial` et `python3-paho-mqtt` (pas de pip). Compatible paho 1.x et 2.x.
- On garde InfluxDB et Grafana : l'add-on écrit dans les mêmes buckets, mesures, étiquettes et **types de champs** que les anciens add-ons (InfluxDB refuse un changement de type).

## Le site (ce dépôt)

- Point d'entrée unique `public/index.php` (routeur maison), classes dans `src/` (espace de noms `Conso\`), chargement automatique par `bootstrap.php`, configuration par `.env`.
- Heures stockées en UTC ; `sample_daily.day` est une date locale (`APP_TIMEZONE=Europe/Paris`).
- Migrations : fichiers `sql/NNN_*.sql`, appliqués par `php bin/migrate.php` (table `schema_version`). Ne jamais modifier une migration déjà appliquée : en ajouter une nouvelle.
- Tables principales : `metric` (code, source, libellé, unité, `kind` gauge/counter, `energy_factor`), `sample` (clé metric_id + ts), `sample_hourly`, `sample_daily`, `sample_latest`, `setting`, `price`, `api_token`, `ingest_batch`, `login_attempt`, `chat_log`.
- Jetons d'API hachés en SHA-256, portées `ingest`, `read`, `tablet`. Session du site : cookie `consov2` de 30 jours repoussé à chaque visite, session rangée dans MySQL (table `web_session`, `src/SessionStore.php`, migration 009 ; avant, fichiers du conteneur php, perdus quand il est recréé ou nettoyés par un autre programme : cause probable des déconnexions, non vérifiée), CSRF, 5 échecs de connexion par 15 min et par IP.
- Thèmes : variables CSS sous `html[data-theme=clair|sombre|encre|domotique|nuit|jarvis|ironman|hologramme]`. Domotique (demandé par Alain le 10 oct. 2026 d'après une image de tableau de bord « smart home ») remplace LCD (migration 010) : panneaux translucides par opacité, sans flou, cadres blancs fins arrondis, chiffres fins (Alain ne veut ni `backdrop-filter` ni lueur sur les graphiques : « trop flou ») ; fond futuriste `public/assets/img/domotique-fond.jpg` (maison holographique, sol en grille, globe, anneaux), dessiné en SVG pour le site (pas l'image de la capture, qui est sous droits), posé sur un calque fixe `body::before` (Safari sur iPhone ignore `background-attachment:fixed`). Les autres thèmes ont la même scène recolorée (`img/fond-<thème>.jpg`, demande d'Alain du 10 oct. 2026, Domotique gardé tel quel) et le même principe : panneaux translucides par opacité, sans flou ; ce qui recouvre la page (barre, menu, info-bulles, chat) reste opaque (`--solid`). Les huit images sont dessinées par `node bin/fonds.js [thème ...]` (SVG rendu en JPEG par Chromium avec Playwright ; tirage fixe, il redonne les mêmes fichiers). Les trois derniers (« futuristes », demandés par Alain le 10 oct. 2026) ajoutent lueurs, équerres et police Orbitron. Ils existent aussi pour la tablette (`tablet.css`). Palettes de séries (`--s1` à `--s8`) : Clair, Sombre et Encre gardent des palettes neutres ; Nuit est monochrome orange ; Domotique (couleurs de la photo d'Alain : cyan, corail, sable, glace, orange, crème, sarcelle), Jarvis, Iron Man et Hologramme ont des palettes assorties au thème, à la demande d'Alain (10 oct. 2026). Ces quatre-là sortent volontairement de la bande de luminosité et du seuil de saturation de `validate_palette.js`, mais passent ses contrôles de séparation (séries voisines, daltonisme, vision normale) et de contraste ; toutes leurs paires sont à au moins 10 en vision normale, et s8 se distingue du gris « Reste » (`--faint`), empilé juste après elle sur la page Électricité. Thème Contraste élevé retiré le 10 oct. 2026 (migration 007). Barre de navigation : Administration en roue dentée et Se déconnecter en porte avec une flèche (demande d'Alain du 11 oct. 2026, pour la place), libellés visibles dans le menu « burger ». Logo « maison connectée » en SVG (`View::logo()`) suivi du nom du site (`site_name`) dans la barre de navigation, repris en favicon aux couleurs du thème (`View::icon()`).
- Graphiques : périodes 24 h (mesures toutes les 5 min), 7 jours (par heure) et 30/90 jours, 12 mois (par jour) sur Chauffage, Températures et Humidité ; 24 h par heure sur Électricité (`/api/v1/breakdown?period=hour`). Zoom en glissant sur une courbe ou des barres (double-clic ou « Tout afficher » pour revenir), synchronisé entre les graphiques d'un même `zoomGroup`. Sous 1040 px de large, menu « burger » (au-dessus, le nom du site et la police Orbitron des thèmes futuristes tiennent sur une ligne).
- Coût (page Électricité, section « Coût », demande d'Alain du 10 oct. 2026) : mêmes périodes que la consommation. Tuiles par `/api/v1/cost?period=24h|7|30|12m|y` (période qui finit maintenant, comparée à la même durée juste avant, ou un an avant pour 12m et y ; calcul sur `sample_hourly`), barres et coût par circuit par `/breakdown`, qui donne aussi `cost`, `circuits_cost`, `rest_cost` et `subscription` par ligne. Chaque jour est chiffré au tarif en vigueur (`src/Prices.php`, table `price`) ; abonnement dans `price.subscription_month` (€ TTC par mois, saisi dans l'admin avec le prix du kWh, vide = non compté, ce qui est le cas tant qu'Alain ne l'a pas saisi), réparti × 12 / 365 par jour sur les jours ou heures où le Linky a des données. Barres en couleurs neutres (`--fg`, `--muted`) : la palette désigne les circuits juste à côté.
- Page tablette (`src/Tablet.php`, `src/Metar.php`, `public/assets/tablet.*`), reprise de l'ancienne page « pi » : `/tablette` et l'ancienne adresse réglée sur la tablette, donnée par `TABLET_LEGACY_PATH` dans `.env` (elle contient le nom de la commune : jamais dans le dépôt). nginx passe les adresses `.php` au routeur. L'ancienne adresse est publique (sans jeton ni session, affichable dans un iframe de Home Assistant : `frame-ancestors *`), à la demande d'Alain le 10 oct. 2026 : rien de confidentiel, adresse non connue. `/tablette` reste protégée : session ou jeton `tablet` ouvert une fois en `?jeton=…`, puis gardé dans le cookie `consov2_tablette`. Webcam, code OACI et pistes sont des réglages de l'admin (`tablet_*`) ; METAR d'aviationweather.gov gardé 5 min dans `setting.tablet_metar_cache`. Valeurs rechargées chaque minute, webcam toutes les 5 min (visible avec tous les thèmes ; sauf Clair et Sombre, un voile de la couleur du thème la recouvre, opacité `tablet_veil` de 0 à 90 %, 50 par défaut, demande d'Alain du 10 oct. 2026), thème Nuit de 22 h à 7 h en option. JS en ES5 et CSS sans variables, pour un vieux navigateur. De nuit aéronautique (SERA : fin du crépuscule civil à l'aube civile, à la position `lat`/`lon` de la station donnée par le METAR), la case Condition affiche « NUIT » au lieu de VFR/MVFR (demande d'Alain).
- Chat « Demander à Claude » (`src/Chat.php`, `src/Claude.php`, `src/ChatTools.php`, `public/assets/chat.js`) : `POST /api/v1/chat` (session + en-tête `X-CSRF-Token`), réponse en lignes JSON (étapes puis réponse, ligne vide toutes les 10 s contre les délais de nginx). HTTP brut avec curl (pas de SDK : PHP 7.4), clé `ANTHROPIC_API_KEY` dans `.env`, modèle `claude-opus-5-5`, effort réglable (`low` par défaut), `fallbacks: "default"` (bêta `server-side-fallback-2026-07-01`, abandonné pour la requête si l'API le refuse), 8 tours d'outils au plus. Outils stricts en lecture seule qui appellent les routes GET par `Http::capture()`. Les réponses sont décodées en objets et renvoyées telles quelles dans la boucle (réflexion comprise) ; les échanges précédents viennent du navigateur (sessionStorage) en texte seul. Réglages `chat_*` et journal `chat_log` (jetons, coût estimé) dans l'admin. Testé seulement avec une fausse API : pas encore avec une vraie clé.

- Page Météo (`/meteo`, `src/Weather.php`, `GET /api/v1/weather`, demandée par Alain le 11 oct. 2026) : météo pour voler à l'aéroclub, API Météo Concept (jeton `METEO_CONCEPT_TOKEN` dans `.env`, en-tête `Authorization: Bearer`, réponse JSON avec `Accept`). Forfait gratuit : 500 appels par jour ; `observations/around` (rayon 30 km, un appel pour toutes les stations), `forecast/daily/periods` et `forecast/nextHours` autorisés ; horaire sur 14 jours, `stations` et historique 24 h d'une station refusés (403). Cache MySQL `weather_cache` (migration 011) : observations 10 min, prévisions 1 h. Compteur d'appels : en-têtes `X-Api-Calls` et `X-Api-Limit` des réponses (demande d'Alain : il compte aussi les essais faits ailleurs avec le jeton), gardé dans la ligne `quota` et remis à zéro au changement de jour local ; arrêt à 90 % du quota (450). Réglages de l'admin (section Météo) : `weather_center` (position de l'aéroclub, jamais dans le dépôt), `weather_stations` (uuid cochés ; Alain en a choisi 4, dont le phare d'un port, plus venté ; vide = 4 plus proches avec du vent ; jamais de nom de station dans le dépôt, ils situent la maison), seuils en km/h (travers orange 18, max 25 rafales comprises, rafales 37 et 46). Pistes et OACI repris de la tablette ; TAF ajouté à `Metar` (`weather_taf_cache`). Vent observé : moyenne des stations pondérée par 1/distance ; prévisions : moyenne de l'aéroclub et des stations, pondérée par 1/distance du point de grille (2 km au moins), un point de grille en double compté une fois et plus redemandé. Direction en moyenne vectorielle, risques au maximum, temps = code le plus fréquent (le plus mauvais à égalité). Seulement aujourd'hui et demain (demande d'Alain), vent en km/h. Rose des vents avec pistes, flèche et manche à air (5 anneaux, pleine à 28 km/h), rouge `--danger` propre à chaque thème. Juste après minuit, le « jour 0 » de l'API est encore la veille : tout est rangé par date réelle. `nextHours` renvoyait la nuit des heures du lendemain soir seulement : le tableau « Prochaines heures » est alors caché. Hauteur des nuages (demandée par Alain) : Météo Concept ne la donne pas, elle vient du METAR (bloc « Ciel » : couches, plafond, visibilité, temps présent) et du TAF décodé (`Metar::decodeGroup`, `parseTaf`, `tafWindow`) : tableau par groupe et plafond prévu dans chaque période couverte par le TAF ; pastille orange sous 1500 ft ou 5 km, rouge sous 1000 ft ou 1,5 km (temporaire : orange au plus). Pictogrammes au trait, communs à Météo Concept et au METAR (code de temps équivalent).

### Modèle d'énergie (`src/Energy.php`)

- Grandeur en W : valeur × durée depuis la mesure précédente, trou maximal 15 min (`gauge_max_gap_minutes`), répartie sur les heures traversées.
- Index (compteur) : différence répartie sur le trou, jusqu'à 62 jours.
- Contrôle de plausibilité d'un index : jamais de baisse, au plus 36 kW × max(durée, 300 s). Un index incohérent est mis de côté (`setting.counter_candidate_<id>`) et devient la nouvelle base si le suivant le confirme dans les 6 h (compteur remplacé). Une erreur isolée n'est jamais confirmée.
- Énergie sur un intervalle (`/cost`, « Depuis minuit » de l'accueil) : une heure passée entamée compte au prorata, l'heure en cours compte entière (son énergie s'arrête déjà au dernier relevé ; avant le 10 oct. 2026, « Depuis minuit » la comptait au prorata et sous-estimait).
- Réception idempotente : clé primaire `sample (metric_id, ts)` + `Idempotency-Key` par lot (table `ingest_batch`), réception sérialisée par `GET_LOCK('consov2_ingest')`.

### Mesures connues

| Code | Source | Ancien site |
|---|---|---|
| `elec_index` (kWh, l'add-on envoie des Wh) | linky (EASF01) | Cindex |
| `elec_power` (VA), `elec_voltage` (V) | linky | – |
| `circuit_seche_serviettes`, `circuit_geothermie`, `circuit_prises_rdc`, `circuit_appoint_ecs`, `circuit_double_flux`, `circuit_cuisson`, `circuit_garage`, `circuit_lavage` | shelly | CPT1 à CPT8 = q1 à q8 = SHELLYEM1_0, 1_1, 2_0, 2_1, 3_0, 3_1, 4_0, 4_1 |
| `temp_living`, `temp_outdoor`, `temp_upstairs`, `humidity_living`, `humidity_outdoor`, `humidity_upstairs`, `co2_living`, `pressure_outdoor` | netatmo (via HA) | Sonde1, Sonde2, Sonde3, Humidite |
| `pac_*` (eau départ/retour, ballon ECS, pressions, consignes, zones) | arkteos | – |

### Ce qu'on sait des données

- Historique repris de l'ancien site (dump `conso-09-10-2026.sql`, table `releve`) par `bin/migrate-releve.php`. Totaux annuels attendus : 6221 kWh (2023), 6559 (2024), 6606 (2025), 4881 (janv.–sept. 2026). 532 index aberrants écartés (valeurs ×1000 ou tronquées).
- Ne plus lancer `bin/migrate-releve.php --reset` depuis que l'add-on envoie : il efface toutes les données des 13 mesures reprises (index, 8 circuits, sondes), y compris celles de l'add-on, et remet leur dernière valeur à celle de l'ancien site.
- Les Shelly ont envoyé des zéros à partir du 4 oct. 2026 à 05:35 (pannes aussi les 11–12 et 27–28 sept.) : les pages affichent alors « Shelly absents » au lieu d'un faux « Reste ».
- Appoint ECS (résistance du ballon) : ne devrait jamais s'allumer. 8 activations dans l'historique (3,12 kWh), la dernière le 27 août 2025. Seuil 500 W (`ecs_threshold_w`).
- PAC Arkteos Geotwin IV (géothermie), décodage d'après `cyrilpawelko/arkteos_reg3` (tableur `decoder.xlsx`, onglets de structures). L'octet 46 de la trame de 227 octets est le modèle de PAC (0x13), pas une pression : les anciens add-ons envoyaient donc 1,9 constant comme « pression extérieure ». La pression de l'eau de captage (dehors) est aux octets 94–95 de la trame de 163 octets (÷10), la pression primaire (dedans) à l'octet 62 de la trame de 227 : vérifié le 10 oct. 2026 avec l'écran de la PAC (1,3 et 1,8 bar). Valeurs 1,9 effacées par `sql/008_pression_captage.sql` (pas dans InfluxDB). Consignes de zone à 0 quand le chauffage est sur Off (19,6 sur On). La page Chauffage les ignore (paramètre `nonzero=1` de `/series` et `/summary`, calculé sur les mesures brutes) : la ligne pointillée s'interrompt quand le chauffage est arrêté. Eau retour plus chaude que départ : chauffage au sol arrêté, normal.
- Tarif EDF Base, pas de panneaux solaires, pas de climatisation (prévue en 2027).
- La base MySQL de production est déjà sauvegardée par Alain (dit le 10 oct. 2026) : inutile de le proposer.

## L'add-on (`HAPython/consov2`)

- Collecteurs : Linky (port série 9600 7E1, mode standard, checksum vérifié, index EASF01), PAC Arkteos (TCP 9641, trames de 163 et 227 octets, valeurs signées sur 16 bits), Shelly EM (MQTT `shellies/shellyemN/emeter/C/power`, valeur absolue comme avant), entités HA (API du Supervisor).
- Toutes les 5 min calées sur l'horloge : moyenne des grandeurs, dernier index ; file SQLite dans `/data` (ordre conservé, renvoi de 30 s à 15 min) ; envoi immédiat quand l'appoint ECS franchit le seuil.
- Sorties : API du site, InfluxDB (line protocol, mêmes noms et types que teleinfo/arkteos/getMqtt), MQTT (découverte HA, appareil « ConsoV2 », anciens sujets `arkteos/reg3/…`), et facultativement l'ancien `receiver.php` (`legacy_receiver_url`, vidé le 9 oct. 2026).
- Version 0.1.1 installée et démarrée sur le Home Assistant d'Alain le 9 oct. 2026 (Linky lu, MQTT connecté). La 0.1.1 corrige les types InfluxDB du Linky (ADSC en entier). La 0.1.2 (même jour) n'avertit plus à chaque échec de lecture de la PAC, seulement après 15 min sans réponse. La 0.1.3 lit la PAC comme l'ancien add-on : connexion réessayée toutes les 5 s pendant `timeout_seconds` (120 s), une lecture toutes les 300 s calée sur les envois (Alain doit mettre `interval_seconds: 300` dans sa configuration, l'ancienne valeur 60 n'est pas remplacée). La 0.1.4 écrit chaque envoi réussi dans le journal (« Envoyé au site : … ») : avant, seule la mise en file apparaissait et Alain croyait que rien ne partait. La 0.1.5 (10 oct.) lit la pression de l'eau de captage au bon endroit et écrit les trames brutes de la PAC en niveau `debug`.
- Le 9 oct. au soir, les 28 mesures arrivent sur conso.ctrl.ovh, dont la sonde de l'étage (module mezzanine : `temp_upstairs`, `humidity_upstairs`). Les premières mesures Netatmo, arrivées sous des codes inversés (`living_temp`…), ont été rattachées par `sql/005_netatmo_codes.sql`.
- Tests : `python3 -m unittest discover -s consov2/tests` dans HAPython.

## Développement local

- `docker compose up -d --build`, puis `docker compose exec php php bin/migrate.php` et `php tests/unit.php`. Sur le Mac d'Alain, `WEB_PORT=8081` (le 8080 est pris par un autre projet).
- Dans un conteneur cloud : démarrer Docker avec `dockerd` si besoin ; Docker Hub limite les téléchargements (erreur 429), passer par `mirror.gcr.io/library/<image>` puis `docker tag`. Les registres ghcr.io et deb.debian.org y sont bloqués : l'image de l'add-on ne peut pas y être construite, la tester avec un venv (`pyserial`, `paho-mqtt`).
- Le Docker local d'Alain ne reçoit rien de l'add-on (branché sur la production) : `php bin/fake-data.php --days=30` le remplit de fausses mesures, `--live` en ajoute toutes les 5 min, `--ecs` simule une activation de l'appoint. Refusé sans `APP_DEBUG=1`.
- Chat sans clé ni dépense : `php -S 127.0.0.1:9999 tests/mock-claude.php` dans le conteneur php, avec `ANTHROPIC_API_KEY=test-key` et `ANTHROPIC_BASE_URL=http://127.0.0.1:9999` dans `.env` (voir l'en-tête du fichier pour les cas simulés).
- Un pseudo-terminal (pty) ne se rouvre pas après fermeture (erreur 22) : c'est un artefact des tests, pas un bug du lecteur Linky.
- Pousser depuis le Mac : son dépôt utilise SSH, inaccessible depuis Claude. Faire le commit dans la copie du Mac (git y demande l'autorisation de suppression, pour ses fichiers `.lock`), l'apporter au conteneur avec `git bundle`, puis pousser ce même commit depuis le conteneur : le Mac reste aligné sur `main`.

## Reste à faire

- PAC : environ une lecture sur deux échouait avec la 0.1.2 (délai dépassé, « No route to host ») ; le 10 oct., la 0.1.5 a encore des « No route to host » avant de se connecter.
- Pression atmosphérique : entité Netatmo à relayer en `pressure_outdoor` avec `unit: hPa` (Netatmo annonce des mbar, unité que le site refuse).
- Page tablette en production : `TABLET_LEGACY_PATH` dans le `.env`, nginx qui passe les `.php` au site, section Tablette de l'admin remplie. Le toucher du METAR qui montrait la caméra du hangar n'est pas repris (adresse en http).
- Chat : essai avec une vraie clé d'API (`ANTHROPIC_API_KEY` dans le `.env`, migration 006, `fastcgi_read_timeout 300s` dans le nginx de production).
- Connecteur MCP sur les données.
- Météo en production : migration 011, `METEO_CONCEPT_TOKEN` dans le `.env`, position de l'aéroclub et stations dans l'admin. Revoir en journée si `nextHours` donne bien les 12 prochaines heures.

<!-- rtk-instructions v2 -->
# Command output

Command output here is condensed to save tokens, keeping every signal and
dropping costly noise. Treat it as the complete result: run commands
normally, and batch related commands into one call to avoid extra turns.
Truncated results state their recovery path in their own output. Re-run a
command as `rtk proxy <cmd>` only when its result is unusable: empty when
output was clearly expected, contradicting its exit code, or garbled.
<!-- /rtk-instructions -->