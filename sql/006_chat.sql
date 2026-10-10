-- Étape 6 : discussion avec Claude (bouton « Demander à Claude »).
SET NAMES utf8mb4;

-- Une ligne par question : réponse, outils appelés, jetons et coût estimé.
CREATE TABLE IF NOT EXISTS chat_log (
  id                 INT UNSIGNED      NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at         DATETIME          NOT NULL,
  question           TEXT              NOT NULL,
  answer             MEDIUMTEXT        NULL,
  tools              TEXT              NULL,
  status             VARCHAR(16)       NOT NULL,
  error              VARCHAR(500)      NULL,
  model              VARCHAR(64)       NULL,
  turns              TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  input_tokens       INT UNSIGNED      NOT NULL DEFAULT 0,
  output_tokens      INT UNSIGNED      NOT NULL DEFAULT 0,
  cache_read_tokens  INT UNSIGNED      NOT NULL DEFAULT 0,
  cache_write_tokens INT UNSIGNED      NOT NULL DEFAULT 0,
  cost_usd           DECIMAL(10,5)     NOT NULL DEFAULT 0,
  duration_ms        INT UNSIGNED      NOT NULL DEFAULT 0,
  KEY ix_chat_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Réglages de l'administration. Le contexte est envoyé à Claude avec chaque question :
-- jamais de nom de lieu, d'adresse ni de coordonnées.
INSERT IGNORE INTO setting (name, value) VALUES
  ('chat_enabled', '1'),
  ('chat_daily_limit', '30'),
  ('chat_effort', 'low'),
  ('chat_context', 'Maison chauffée par une pompe à chaleur géothermique Arkteos (circuit_geothermie, mesures pac_*), qui produit aussi l''eau chaude sanitaire. La résistance d''appoint du ballon (circuit_appoint_ecs) ne devrait jamais s''allumer.\nTarif EDF Base, pas de panneaux solaires, pas de climatisation (prévue en 2027).\nHistorique repris de l''ancien site depuis 2023 (compteur, 8 circuits, sondes) ; l''add-on Home Assistant envoie toutes les mesures depuis le 9 octobre 2026.\nLes Shelly des circuits ont eu des pannes (zéros ou trous), notamment les 11-12 et 27-28 septembre 2026 et du 4 au 9 octobre 2026 : sur ces jours, le « reste » (compteur moins circuits) n''est pas fiable.');
