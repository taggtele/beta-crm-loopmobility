-- notifications table (complete schema)
CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) NOT NULL,
    link_url VARCHAR(512) NULL,
    inbox_log_id BIGINT UNSIGNED NULL,
    ticket_id BIGINT NULL,
    meta_json JSON NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user_read_created (user_id, is_read, created_at),
    INDEX idx_notifications_type_created (type, created_at),
    INDEX idx_notifications_inbox_log (inbox_log_id, type, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification table column additions
SET @col_link_url := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'notifications' AND `COLUMN_NAME` = 'link_url');
SET @col_inbox_log_id := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'notifications' AND `COLUMN_NAME` = 'inbox_log_id');
SET @col_ticket_id := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'notifications' AND `COLUMN_NAME` = 'ticket_id');
SET @col_meta_json := (SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'notifications' AND `COLUMN_NAME` = 'meta_json');

-- We add columns via PHP in the migration runner if not present.
-- The raw DDL equivalent follows:
-- ALTER TABLE notifications ADD COLUMN IF NOT EXISTS link_url VARCHAR(512) NULL AFTER type;
-- ALTER TABLE notifications ADD COLUMN IF NOT EXISTS inbox_log_id BIGINT UNSIGNED NULL AFTER link_url;
-- ALTER TABLE notifications ADD COLUMN IF NOT EXISTS ticket_id BIGINT NULL AFTER inbox_log_id;
-- ALTER TABLE notifications ADD COLUMN IF NOT EXISTS meta_json JSON NULL AFTER ticket_id;
