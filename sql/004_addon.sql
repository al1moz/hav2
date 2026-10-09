-- Étape 3 : mesures envoyées par l'add-on Home Assistant.
SET NAMES utf8mb4;

-- Les anciennes Sonde1-3 et Humidite venaient de Netatmo (lues par receiver.php).
UPDATE metric SET source = 'netatmo' WHERE source = 'sonde';

-- Mesures nouvelles, déclarées d'avance pour avoir des libellés en français.
INSERT IGNORE INTO metric (code, source, label, unit, kind, energy_factor, sort, created_at) VALUES
  ('elec_voltage',                 'linky',   'Tension',                      'V',   'gauge', NULL, 12, UTC_TIMESTAMP()),
  ('humidity_upstairs',            'netatmo', 'Humidité étage',               '%',   'gauge', NULL, 51, UTC_TIMESTAMP()),
  ('humidity_outdoor',             'netatmo', 'Humidité extérieure',          '%',   'gauge', NULL, 52, UTC_TIMESTAMP()),
  ('co2_living',                   'netatmo', 'CO₂ salon',                    'ppm', 'gauge', NULL, 55, UTC_TIMESTAMP()),
  ('pressure_outdoor',             'netatmo', 'Pression atmosphérique',       'hPa', 'gauge', NULL, 56, UTC_TIMESTAMP()),
  ('pac_primaire_temp_eau_aller',  'arkteos', 'PAC eau départ',               '°C',  'gauge', NULL, 60, UTC_TIMESTAMP()),
  ('pac_primaire_temp_eau_retour', 'arkteos', 'PAC eau retour',               '°C',  'gauge', NULL, 61, UTC_TIMESTAMP()),
  ('pac_ecs_temp_eau_milieu',      'arkteos', 'Ballon ECS milieu',            '°C',  'gauge', NULL, 62, UTC_TIMESTAMP()),
  ('pac_ecs_temp_eau_bas',         'arkteos', 'Ballon ECS bas',               '°C',  'gauge', NULL, 63, UTC_TIMESTAMP()),
  ('pac_exterieur_temp',           'arkteos', 'PAC température extérieure',   '°C',  'gauge', NULL, 64, UTC_TIMESTAMP()),
  ('pac_zone1_temp_interieur',     'arkteos', 'PAC intérieur zone 1',         '°C',  'gauge', NULL, 65, UTC_TIMESTAMP()),
  ('pac_zone2_temp_interieur',     'arkteos', 'PAC intérieur zone 2',         '°C',  'gauge', NULL, 66, UTC_TIMESTAMP()),
  ('pac_zone1_consigne',           'arkteos', 'PAC consigne zone 1',          '°C',  'gauge', NULL, 67, UTC_TIMESTAMP()),
  ('pac_zone2_consigne',           'arkteos', 'PAC consigne zone 2',          '°C',  'gauge', NULL, 68, UTC_TIMESTAMP()),
  ('pac_primaire_pression',        'arkteos', 'PAC pression eau primaire',    'bar', 'gauge', NULL, 69, UTC_TIMESTAMP()),
  ('pac_externe_pression',         'arkteos', 'PAC pression eau extérieure',  'bar', 'gauge', NULL, 70, UTC_TIMESTAMP());
