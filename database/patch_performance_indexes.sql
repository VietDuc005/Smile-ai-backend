-- Patch: Add performance indexes for common filters, sorting and joins
-- Safe to re-run: each index is created only if it does not already exist.

SET @schema_name = DATABASE();

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Orders'
              AND index_name = 'idx_orders_user_created_at'
        ),
        'SELECT ''skip idx_orders_user_created_at''',
        'CREATE INDEX idx_orders_user_created_at ON Orders(user_id, created_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Orders'
              AND index_name = 'idx_orders_status_created_at'
        ),
        'SELECT ''skip idx_orders_status_created_at''',
        'CREATE INDEX idx_orders_status_created_at ON Orders(status, created_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Orders'
              AND index_name = 'idx_orders_status_paid_at'
        ),
        'SELECT ''skip idx_orders_status_paid_at''',
        'CREATE INDEX idx_orders_status_paid_at ON Orders(status, paid_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Orders'
              AND index_name = 'idx_orders_paid_at'
        ),
        'SELECT ''skip idx_orders_paid_at''',
        'CREATE INDEX idx_orders_paid_at ON Orders(paid_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Order_Items'
              AND index_name = 'idx_order_items_product_id'
        ),
        'SELECT ''skip idx_order_items_product_id''',
        'CREATE INDEX idx_order_items_product_id ON Order_Items(product_id)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Order_Items'
              AND index_name = 'idx_order_items_expires_at'
        ),
        'SELECT ''skip idx_order_items_expires_at''',
        'CREATE INDEX idx_order_items_expires_at ON Order_Items(expires_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Order_Items'
              AND index_name = 'idx_order_items_order_created_at'
        ),
        'SELECT ''skip idx_order_items_order_created_at''',
        'CREATE INDEX idx_order_items_order_created_at ON Order_Items(order_id, created_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Support_Tickets'
              AND index_name = 'idx_support_tickets_user_created_at'
        ),
        'SELECT ''skip idx_support_tickets_user_created_at''',
        'CREATE INDEX idx_support_tickets_user_created_at ON Support_Tickets(user_id, created_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Support_Tickets'
              AND index_name = 'idx_support_tickets_status_created_at'
        ),
        'SELECT ''skip idx_support_tickets_status_created_at''',
        'CREATE INDEX idx_support_tickets_status_created_at ON Support_Tickets(status, created_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Support_Ticket_Messages'
              AND index_name = 'idx_support_ticket_messages_ticket_created_at'
        ),
        'SELECT ''skip idx_support_ticket_messages_ticket_created_at''',
        'CREATE INDEX idx_support_ticket_messages_ticket_created_at ON Support_Ticket_Messages(ticket_id, created_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Notifications'
              AND index_name = 'idx_notifications_user_created_at'
        ),
        'SELECT ''skip idx_notifications_user_created_at''',
        'CREATE INDEX idx_notifications_user_created_at ON Notifications(user_id, created_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Notifications'
              AND index_name = 'idx_notifications_user_is_read_created_at'
        ),
        'SELECT ''skip idx_notifications_user_is_read_created_at''',
        'CREATE INDEX idx_notifications_user_is_read_created_at ON Notifications(user_id, is_read, created_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Users'
              AND index_name = 'idx_users_role_created_at'
        ),
        'SELECT ''skip idx_users_role_created_at''',
        'CREATE INDEX idx_users_role_created_at ON Users(role, created_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Users'
              AND index_name = 'idx_users_status_created_at'
        ),
        'SELECT ''skip idx_users_status_created_at''',
        'CREATE INDEX idx_users_status_created_at ON Users(status, created_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Digital_Accounts'
              AND index_name = 'idx_digital_accounts_product_status_added_at'
        ),
        'SELECT ''skip idx_digital_accounts_product_status_added_at''',
        'CREATE INDEX idx_digital_accounts_product_status_added_at ON Digital_Accounts(product_id, status, added_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Digital_Accounts'
              AND index_name = 'idx_digital_accounts_product_expires_at'
        ),
        'SELECT ''skip idx_digital_accounts_product_expires_at''',
        'CREATE INDEX idx_digital_accounts_product_expires_at ON Digital_Accounts(product_id, expires_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = @schema_name
              AND table_name = 'Products'
              AND index_name = 'idx_products_is_active_created_at'
        ),
        'SELECT ''skip idx_products_is_active_created_at''',
        'CREATE INDEX idx_products_is_active_created_at ON Products(is_active, created_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
