ALTER TABLE Products
    ADD COLUMN requires_inventory BOOLEAN NOT NULL DEFAULT TRUE AFTER image_url,
    ADD COLUMN stock_quantity INT NOT NULL DEFAULT 0 AFTER requires_inventory;
