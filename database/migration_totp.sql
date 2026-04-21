-- Migration: Add TOTP secret to Digital_Accounts + create rate-limit log table

ALTER TABLE Digital_Accounts ADD COLUMN totp_secret TEXT NULL;

CREATE TABLE IF NOT EXISTS Totp_Access_Log (
    id            CHAR(36)     PRIMARY KEY,
    order_item_id CHAR(36)     NOT NULL,
    ip_address    VARCHAR(45)  NOT NULL,
    accessed_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_totp_log_item_time ON Totp_Access_Log(order_item_id, accessed_at);
CREATE INDEX IF NOT EXISTS idx_totp_log_ip_time   ON Totp_Access_Log(ip_address, accessed_at);
