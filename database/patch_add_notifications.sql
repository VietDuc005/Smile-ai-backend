-- Patch: Add Notifications table for in-app user notifications

BEGIN;

CREATE TABLE IF NOT EXISTS Notifications (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) REFERENCES Users(id) ON DELETE CASCADE,
    type VARCHAR(50) NOT NULL,
    -- 'order_completed', 'account_granted', 'renewal_reminder'
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    metadata TEXT,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_notifications_user_id ON Notifications(user_id);
CREATE INDEX idx_notifications_user_is_read ON Notifications(user_id, is_read);
CREATE INDEX idx_notifications_created_at ON Notifications(created_at);

COMMIT;
