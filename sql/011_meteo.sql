-- Page Météo : observations et prévisions de Météo Concept, gardées en cache (500 appels par jour au plus).
SET NAMES utf8mb4;

-- Réponses de l'API déjà réduites à ce que la page affiche (observations, prévisions), et compteur d'appels
-- du jour renvoyé par Météo Concept (ligne « quota »).
CREATE TABLE IF NOT EXISTS weather_cache (
  name       VARCHAR(64)  NOT NULL PRIMARY KEY,
  fetched_at INT UNSIGNED NOT NULL DEFAULT 0,
  tried_at   INT UNSIGNED NOT NULL DEFAULT 0,
  data       MEDIUMTEXT   NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seuils en km/h. La position de l'aéroclub (weather_center) se saisit dans l'administration :
-- jamais de coordonnées dans le dépôt.
INSERT IGNORE INTO setting (name, value) VALUES
  ('weather_cross_warn', '18'),
  ('weather_cross_max', '25'),
  ('weather_gust_warn', '37'),
  ('weather_gust_max', '46');
