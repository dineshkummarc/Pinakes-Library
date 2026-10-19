-- Pinakes 0.8.6: custom roles and a digital-only grant (#480).
-- Additive and idempotent on MySQL and MariaDB. Existing memberships are preserved.
CREATE TABLE IF NOT EXISTS `ruoli` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `permessi` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ruoli_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utenti' AND COLUMN_NAME = 'ruolo_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE utenti ADD COLUMN ruolo_id int DEFAULT NULL, ADD KEY idx_utenti_ruolo (ruolo_id), ADD CONSTRAINT fk_utenti_ruolo FOREIGN KEY (ruolo_id) REFERENCES ruoli(id) ON DELETE RESTRICT', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utenti' AND COLUMN_NAME = 'accesso_digitale');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE utenti ADD COLUMN accesso_digitale tinyint(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO ruoli (nome, permessi) VALUES
('Catalogatore', '["backend.access","catalog.manage"]'),
('Addetto prestiti', '["backend.access","loans.manage"]'),
('Archivista', '["backend.access","catalog.manage","digital.manage","digital.access"]');
