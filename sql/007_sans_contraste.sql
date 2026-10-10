-- Le thème « Contraste élevé » est retiré : le site et la tablette qui l'utilisaient passent au thème Sombre.
UPDATE setting SET value = 'sombre' WHERE name IN ('theme_site', 'theme_tablet') AND value = 'contraste';
