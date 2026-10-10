-- Sessions du site dans MySQL (src/SessionStore.php) : elles ne disparaissent plus
-- quand le conteneur php redémarre. Une session dure 30 jours après la dernière visite.
CREATE TABLE IF NOT EXISTS web_session (
  id         VARCHAR(128) NOT NULL PRIMARY KEY,
  data       MEDIUMBLOB   NOT NULL,
  updated_at DATETIME     NOT NULL,
  KEY idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
