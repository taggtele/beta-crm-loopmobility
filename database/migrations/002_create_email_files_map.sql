-- Optional MinIO mapping table for email inline images and attachments.
-- This does not alter email_logs/email_inbox_log/email_outbox_log.
-- This MinIO + compression pipeline is currently for local testing. Production enable/disable controlled via config flag USE_MINIO.

CREATE TABLE IF NOT EXISTS email_files_map (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(20) NOT NULL,
    email_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    file_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(20) NOT NULL DEFAULT 'attachment',
    mime_type VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    file_hash CHAR(64) NOT NULL,
    storage_type VARCHAR(20) NOT NULL DEFAULT 'minio',
    minio_url VARCHAR(1000) NOT NULL,
    object_key VARCHAR(700) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_email_files_source_email (source, email_log_id),
    KEY idx_email_files_hash (file_hash),
    UNIQUE KEY uq_email_files_map_item (source, email_log_id, file_hash, file_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
