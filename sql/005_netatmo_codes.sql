-- Étape 3 : les premières mesures Netatmo de l'add-on sont arrivées sous des codes
-- inversés (living_temp au lieu de temp_living…), ce qui a créé 4 mesures en double.
-- On rattache leurs relevés et agrégats aux bonnes mesures, puis on les supprime.
-- (Colonnes renommées dans les sous-requêtes : sinon MySQL juge « n », « ts »… ambigus.)
SET NAMES utf8mb4;

CREATE TEMPORARY TABLE metric_merge (
  old_id SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
  new_id SMALLINT UNSIGNED NOT NULL
);

INSERT INTO metric_merge (old_id, new_id)
SELECT o.id, n.id
  FROM metric o
  JOIN metric n ON n.code = CASE o.code
         WHEN 'living_temp'      THEN 'temp_living'
         WHEN 'living_humidity'  THEN 'humidity_living'
         WHEN 'outdoor_temp'     THEN 'temp_outdoor'
         WHEN 'outdoor_humidity' THEN 'humidity_outdoor' END;

INSERT IGNORE INTO sample (metric_id, ts, value)
SELECT mm.new_id, s.ts, s.value
  FROM sample s JOIN metric_merge mm ON mm.old_id = s.metric_id;

INSERT INTO sample_hourly (metric_id, hour, n, v_sum, v_min, v_max, energy_wh)
SELECT * FROM (
  SELECT mm.new_id AS m_id, h.hour AS m_hour, h.n AS m_n, h.v_sum AS m_sum, h.v_min AS m_min, h.v_max AS m_max, h.energy_wh AS m_wh
    FROM sample_hourly h JOIN metric_merge mm ON mm.old_id = h.metric_id
) AS src
ON DUPLICATE KEY UPDATE
  n = n + VALUES(n),
  v_sum = v_sum + VALUES(v_sum),
  v_min = CASE WHEN v_min IS NULL THEN VALUES(v_min) WHEN VALUES(v_min) IS NULL THEN v_min ELSE LEAST(v_min, VALUES(v_min)) END,
  v_max = CASE WHEN v_max IS NULL THEN VALUES(v_max) WHEN VALUES(v_max) IS NULL THEN v_max ELSE GREATEST(v_max, VALUES(v_max)) END,
  energy_wh = energy_wh + VALUES(energy_wh);

INSERT INTO sample_daily (metric_id, day, n, v_sum, v_min, v_max, energy_wh)
SELECT * FROM (
  SELECT mm.new_id AS m_id, d.day AS m_day, d.n AS m_n, d.v_sum AS m_sum, d.v_min AS m_min, d.v_max AS m_max, d.energy_wh AS m_wh
    FROM sample_daily d JOIN metric_merge mm ON mm.old_id = d.metric_id
) AS src
ON DUPLICATE KEY UPDATE
  n = n + VALUES(n),
  v_sum = v_sum + VALUES(v_sum),
  v_min = CASE WHEN v_min IS NULL THEN VALUES(v_min) WHEN VALUES(v_min) IS NULL THEN v_min ELSE LEAST(v_min, VALUES(v_min)) END,
  v_max = CASE WHEN v_max IS NULL THEN VALUES(v_max) WHEN VALUES(v_max) IS NULL THEN v_max ELSE GREATEST(v_max, VALUES(v_max)) END,
  energy_wh = energy_wh + VALUES(energy_wh);

INSERT INTO sample_latest (metric_id, ts, value, received_at)
SELECT * FROM (
  SELECT mm.new_id AS m_id, l.ts AS m_ts, l.value AS m_value, l.received_at AS m_received
    FROM sample_latest l JOIN metric_merge mm ON mm.old_id = l.metric_id
) AS src
ON DUPLICATE KEY UPDATE
  value = IF(VALUES(ts) > ts, VALUES(value), value),
  received_at = IF(VALUES(ts) > ts, VALUES(received_at), received_at),
  ts = GREATEST(ts, VALUES(ts));

DELETE s FROM sample s JOIN metric_merge mm ON mm.old_id = s.metric_id;
DELETE h FROM sample_hourly h JOIN metric_merge mm ON mm.old_id = h.metric_id;
DELETE d FROM sample_daily d JOIN metric_merge mm ON mm.old_id = d.metric_id;
DELETE l FROM sample_latest l JOIN metric_merge mm ON mm.old_id = l.metric_id;
DELETE m FROM metric m JOIN metric_merge mm ON mm.old_id = m.id;

DROP TEMPORARY TABLE metric_merge;
