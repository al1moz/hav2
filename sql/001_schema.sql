-- ConsoV2 : schéma compatible MySQL 5.7 (InnoDB, utf8mb4, sans CTE ni fenêtrage).
-- Toutes les dates sont en UTC, sauf sample_daily.day qui est une date locale
-- (APP_TIMEZONE) pour que « une journée » corresponde à minuit-minuit chez soi.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS schema_version (
  version    INT UNSIGNED NOT NULL PRIMARY KEY,
  applied_at DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Une ligne par grandeur mesurée. Nouvelle mesure = nouvelle ligne, pas d'ALTER TABLE.
-- kind = gauge   : valeur instantanée ou moyenne (W, °C, %)
-- kind = counter : index qui ne fait que monter (kWh, Wh, m³)
-- energy_factor  : gauge   -> Wh par (unité x heure), 1 pour des W
--                  counter -> Wh par unité, 1000 pour des kWh
--                  NULL    -> la mesure ne produit pas d'énergie
CREATE TABLE IF NOT EXISTS metric (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(64)  NOT NULL,
  source        VARCHAR(32)  NOT NULL,
  label         VARCHAR(128) NOT NULL,
  unit          VARCHAR(16)  NOT NULL,
  kind          ENUM('gauge','counter') NOT NULL DEFAULT 'gauge',
  energy_factor DOUBLE       NULL,
  sort          SMALLINT     NOT NULL DEFAULT 0,
  visible       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL,
  UNIQUE KEY uq_metric_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mesures brutes (une toutes les 5 minutes environ).
CREATE TABLE IF NOT EXISTS sample (
  metric_id SMALLINT UNSIGNED NOT NULL,
  ts        DATETIME NOT NULL,
  value     DOUBLE   NOT NULL,
  PRIMARY KEY (metric_id, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Agrégats tenus à jour à l'insertion (INSERT ... ON DUPLICATE KEY UPDATE).
-- Moyenne = v_sum / n. energy_wh = énergie attribuée à l'heure / au jour.
CREATE TABLE IF NOT EXISTS sample_hourly (
  metric_id SMALLINT UNSIGNED NOT NULL,
  hour      DATETIME NOT NULL,
  n         INT UNSIGNED NOT NULL DEFAULT 0,
  v_sum     DOUBLE NOT NULL DEFAULT 0,
  v_min     DOUBLE NULL,
  v_max     DOUBLE NULL,
  energy_wh DOUBLE NOT NULL DEFAULT 0,
  PRIMARY KEY (metric_id, hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sample_daily (
  metric_id SMALLINT UNSIGNED NOT NULL,
  day       DATE NOT NULL,
  n         INT UNSIGNED NOT NULL DEFAULT 0,
  v_sum     DOUBLE NOT NULL DEFAULT 0,
  v_min     DOUBLE NULL,
  v_max     DOUBLE NULL,
  energy_wh DOUBLE NOT NULL DEFAULT 0,
  PRIMARY KEY (metric_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dernière valeur de chaque mesure (accueil, tablette, alerte d'absence de données).
CREATE TABLE IF NOT EXISTS sample_latest (
  metric_id   SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
  ts          DATETIME NOT NULL,
  value       DOUBLE   NOT NULL,
  received_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS setting (
  name  VARCHAR(64) NOT NULL PRIMARY KEY,
  value TEXT        NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Prix du kWh avec date de début de validité (une année passée est chiffrée au prix de l'époque).
CREATE TABLE IF NOT EXISTS price (
  valid_from         DATE          NOT NULL PRIMARY KEY,
  kwh_price          DECIMAL(8,5)  NOT NULL,
  subscription_month DECIMAL(8,2)  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Jetons d'API. Seule l'empreinte SHA-256 est stockée.
-- scopes : liste séparée par des virgules parmi ingest, read, tablet.
CREATE TABLE IF NOT EXISTS api_token (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(64) NOT NULL,
  token_hash   CHAR(64)    NOT NULL,
  scopes       VARCHAR(64) NOT NULL,
  created_at   DATETIME    NOT NULL,
  last_used_at DATETIME    NULL,
  revoked_at   DATETIME    NULL,
  UNIQUE KEY uq_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lots reçus (Idempotency-Key) : un lot renvoyé reçoit la même réponse.
CREATE TABLE IF NOT EXISTS ingest_batch (
  idem_key    VARCHAR(64)  NOT NULL PRIMARY KEY,
  token_id    INT UNSIGNED NOT NULL,
  received_at DATETIME     NOT NULL,
  response    TEXT         NOT NULL,
  KEY ix_ingest_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_version (version, applied_at) VALUES (1, UTC_TIMESTAMP());
