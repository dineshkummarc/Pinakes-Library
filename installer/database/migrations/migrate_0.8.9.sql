-- Pinakes 0.8.9: pickup reminder before the pickup deadline passes.
-- prestiti.pickup_reminder_sent_for holds the deadline the reminder was sent for,
-- so it goes out once per deadline and a moved deadline re-arms it.
-- Additive and idempotent on MySQL and MariaDB (NotificationService also self-heals it).
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prestiti' AND COLUMN_NAME = 'pickup_reminder_sent_for');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE prestiti ADD COLUMN pickup_reminder_sent_for date DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
