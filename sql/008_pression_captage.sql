-- pac_externe_pression recevait l'octet 46 de la trame Arkteos de 227 octets, qui est le modèle
-- de PAC (0x13 = Geotwin IV) : 1,9 constant. L'add-on 0.1.5 lit la vraie pression de l'eau de
-- captage. On efface les valeurs à 1,9 reçues depuis la mise en route de l'add-on (9 oct. 2026)
-- et on recalcule les agrégats de ces jours (heure d'été : UTC+2).
SET @m := (SELECT id FROM metric WHERE code = 'pac_externe_pression');
UPDATE metric SET label = 'PAC pression eau captage' WHERE id = @m AND label = 'PAC pression eau extérieure';
DELETE FROM sample WHERE metric_id = @m AND ts >= '2026-10-08 22:00:00' AND value BETWEEN 1.89 AND 1.91;
DELETE FROM sample_hourly WHERE metric_id = @m AND hour >= '2026-10-08 22:00:00';
DELETE FROM sample_daily WHERE metric_id = @m AND day >= '2026-10-09';
INSERT INTO sample_hourly (metric_id, hour, n, v_sum, v_min, v_max, energy_wh)
  SELECT @m, DATE_FORMAT(ts, '%Y-%m-%d %H:00:00'), COUNT(*), SUM(value), MIN(value), MAX(value), 0
  FROM sample WHERE metric_id = @m AND ts >= '2026-10-08 22:00:00'
  GROUP BY DATE_FORMAT(ts, '%Y-%m-%d %H:00:00');
INSERT INTO sample_daily (metric_id, day, n, v_sum, v_min, v_max, energy_wh)
  SELECT @m, DATE(CONVERT_TZ(ts, '+00:00', '+02:00')), COUNT(*), SUM(value), MIN(value), MAX(value), 0
  FROM sample WHERE metric_id = @m AND ts >= '2026-10-08 22:00:00'
  GROUP BY DATE(CONVERT_TZ(ts, '+00:00', '+02:00'));
DELETE FROM sample_latest WHERE metric_id = @m AND value BETWEEN 1.89 AND 1.91;
