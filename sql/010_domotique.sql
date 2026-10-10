-- Le thème LCD est remplacé par le thème Domotique (verre fumé sombre, cadres blancs arrondis).
UPDATE setting SET value = 'domotique' WHERE name IN ('theme_site', 'theme_tablet') AND value = 'lcd';
