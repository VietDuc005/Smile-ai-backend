ALTER TABLE Products
    ADD COLUMN inventory_allocation_mode VARCHAR(20) NOT NULL DEFAULT 'exclusive' AFTER requires_inventory,
    ADD COLUMN requires_customer_email BOOLEAN NOT NULL DEFAULT FALSE AFTER inventory_allocation_mode;

ALTER TABLE Digital_Accounts
    ADD COLUMN expires_at TIMESTAMP NULL AFTER status,
    ADD COLUMN seat_capacity INT NOT NULL DEFAULT 1 AFTER expires_at,
    ADD COLUMN seat_used INT NOT NULL DEFAULT 0 AFTER seat_capacity;

ALTER TABLE Orders
    ADD COLUMN customer_email VARCHAR(255) NULL AFTER user_id;
