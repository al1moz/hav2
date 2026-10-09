-- Mesures connues au départ (correspondance avec l'ancienne table releve en commentaire).
-- Les libellés et l'ordre se changeront dans l'administration.
SET NAMES utf8mb4;

INSERT IGNORE INTO metric (code, source, label, unit, kind, energy_factor, sort, created_at) VALUES
  ('elec_index',               'linky',  'Index Linky',           'kWh', 'counter', 1000, 10, UTC_TIMESTAMP()), -- Cindex
  ('elec_power',               'linky',  'Puissance apparente',   'VA',  'gauge',   NULL, 11, UTC_TIMESTAMP()),
  ('circuit_seche_serviettes', 'shelly', 'Sèche-serviettes',      'W',   'gauge',   1,    20, UTC_TIMESTAMP()), -- CPT1 SHELLYEM1_0
  ('circuit_geothermie',       'shelly', 'Géothermie',            'W',   'gauge',   1,    21, UTC_TIMESTAMP()), -- CPT2 SHELLYEM1_1
  ('circuit_prises_rdc',       'shelly', 'Prises RDC',            'W',   'gauge',   1,    22, UTC_TIMESTAMP()), -- CPT3 SHELLYEM2_0
  ('circuit_appoint_ecs',      'shelly', 'Appoint ECS',           'W',   'gauge',   1,    23, UTC_TIMESTAMP()), -- CPT4 SHELLYEM2_1
  ('circuit_double_flux',      'shelly', 'Double flux',           'W',   'gauge',   1,    24, UTC_TIMESTAMP()), -- CPT5 SHELLYEM3_0
  ('circuit_cuisson',          'shelly', 'Cuisson',               'W',   'gauge',   1,    25, UTC_TIMESTAMP()), -- CPT6 SHELLYEM3_1
  ('circuit_garage',           'shelly', 'Garage',                'W',   'gauge',   1,    26, UTC_TIMESTAMP()), -- CPT7 SHELLYEM4_0
  ('circuit_lavage',           'shelly', 'Lavage',                'W',   'gauge',   1,    27, UTC_TIMESTAMP()), -- CPT8 SHELLYEM4_1
  ('temp_living',              'sonde',  'Température salon',     '°C',  'gauge',   NULL, 40, UTC_TIMESTAMP()), -- Sonde1
  ('temp_outdoor',             'sonde',  'Température extérieure','°C',  'gauge',   NULL, 41, UTC_TIMESTAMP()), -- Sonde2
  ('temp_upstairs',            'sonde',  'Température étage',     '°C',  'gauge',   NULL, 42, UTC_TIMESTAMP()), -- Sonde3
  ('humidity_living',          'sonde',  'Humidité salon',        '%',   'gauge',   NULL, 50, UTC_TIMESTAMP()); -- Humidite

INSERT IGNORE INTO setting (name, value) VALUES
  ('site_name',            'Maison'),
  ('theme_site',           'clair'),
  ('theme_tablet',         'sombre'),
  ('stale_after_minutes',  '60'),
  ('gauge_max_gap_minutes','15'),
  ('counter_max_gap_days', '62'),
  ('counter_max_power_w',  '36000'),
  ('ecs_threshold_w',      '500');

-- Prix repris de l'ancien site (0,2276 €/kWh écrit en dur). À compléter dans l'admin avec l'historique réel.
INSERT IGNORE INTO price (valid_from, kwh_price) VALUES ('2022-01-01', 0.22760);
