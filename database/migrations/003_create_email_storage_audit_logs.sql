-- Append-only audit trail for MinIO/S3-compatible email storage actions.
-- Existing email log tables are not modified by this migration.
-- This MinIO + compression pipeline is currently for local testing. Production enable/disable controlled via config flag USE_MINIO.

CREATE TABLE IF NOT EXISTS email_storage_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(60) NOT NULL,
    source VARCHAR(20) NOT NULL DEFAULT '',
    email_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    file_type VARCHAR(20) NOT NULL DEFAULT '',
    file_hash CHAR(64) NULL,
    object_key VARCHAR(700) NULL,
    storage_type VARCHAR(20) NOT NULL DEFAULT 'minio',
    user_id VARCHAR(80) NULL,
    correlation_id VARCHAR(120) NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'success',
    error_message TEXT NULL,
    meta_json TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email_storage_audit_email (source, email_log_id, created_at),
    KEY idx_email_storage_audit_hash (file_hash),
    KEY idx_email_storage_audit_event (event_type, created_at),
    KEY idx_email_storage_audit_correlation (correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
