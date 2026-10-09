-- Étape 2 : connexion au site et réglages des pages.
SET NAMES utf8mb4;

-- Tentatives de connexion échouées (limite par adresse IP).
CREATE TABLE IF NOT EXISTS login_attempt (
  ip VARCHAR(45) NOT NULL,
  ts DATETIME    NOT NULL,
  KEY ix_login_ip_ts (ip, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO setting (name, value) VALUES
  ('ecs_alert_days', '7'),
  ('heating_base_temp', '18');
