-- Database setup for Smile AI backend

BEGIN;

-- 1. Users
CREATE TABLE Users (
    id CHAR(36) PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    role VARCHAR(50) DEFAULT 'user', -- 'admin' or 'user'
    status VARCHAR(50) DEFAULT 'active', -- 'active' or 'blocked'
    blocked_reason TEXT,
    blocked_at TIMESTAMP NULL,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Products
CREATE TABLE Products (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL, -- e.g. ChatGPT Plus 1 Month
    description TEXT,
    features TEXT, -- JSON encoded feature list
    price DECIMAL(10, 2) NOT NULL,
    duration_days INT NOT NULL,
    image_url VARCHAR(255),
    requires_inventory BOOLEAN DEFAULT TRUE,
    inventory_allocation_mode VARCHAR(20) DEFAULT 'exclusive', -- 'exclusive' or 'shared'
    requires_customer_email BOOLEAN DEFAULT FALSE,
    stock_quantity INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. Inventory of digital accounts
CREATE TABLE Digital_Accounts (
    id CHAR(36) PRIMARY KEY,
    product_id CHAR(36) REFERENCES Products(id),
    username VARCHAR(255) NOT NULL,
    password VARCHAR(512) NOT NULL, -- Encrypted password payload
    status VARCHAR(50) DEFAULT 'available', -- 'available', 'sold', 'banned'
    expires_at TIMESTAMP NULL,
    seat_capacity INT DEFAULT 1,
    seat_used INT DEFAULT 0,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. Orders
CREATE TABLE Orders (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) REFERENCES Users(id),
    customer_email VARCHAR(255) NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    transfer_syntax VARCHAR(50) UNIQUE NOT NULL,
    payment_provider VARCHAR(50) DEFAULT 'vietqr',
    payment_qr_url TEXT,
    payment_payload TEXT,
    status VARCHAR(50) DEFAULT 'pending', -- 'pending', 'completed', 'failed', 'cancelled'
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 5. Order items
CREATE TABLE Order_Items (
    id CHAR(36) PRIMARY KEY,
    order_id CHAR(36) REFERENCES Orders(id),
    product_id CHAR(36) REFERENCES Products(id),
    digital_account_id CHAR(36) REFERENCES Digital_Accounts(id),
    unit_price DECIMAL(10, 2) NOT NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. Support tickets
CREATE TABLE Support_Tickets (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) REFERENCES Users(id),
    order_item_id CHAR(36) NULL REFERENCES Order_Items(id),
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(50) DEFAULT 'open', -- 'open', 'in_progress', 'resolved'
    last_reply_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 7. Support ticket messages
CREATE TABLE Support_Ticket_Messages (
    id CHAR(36) PRIMARY KEY,
    ticket_id CHAR(36) REFERENCES Support_Tickets(id),
    sender_role VARCHAR(50) NOT NULL, -- 'user', 'admin', 'system'
    sender_user_id CHAR(36) NULL REFERENCES Users(id),
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 8. Payment webhooks
CREATE TABLE Payment_Webhooks (
    id CHAR(36) PRIMARY KEY,
    provider VARCHAR(50) NOT NULL,
    event_key CHAR(64) UNIQUE NOT NULL,
    external_id VARCHAR(191) NULL,
    direction VARCHAR(20) DEFAULT 'in',
    amount DECIMAL(10, 2) DEFAULT 0,
    transfer_content TEXT,
    transfer_syntax VARCHAR(50),
    status VARCHAR(50) DEFAULT 'received', -- 'received', 'matched', 'unmatched', 'ignored', 'failed'
    note TEXT,
    raw_payload TEXT,
    headers TEXT,
    matched_order_id CHAR(36) NULL REFERENCES Orders(id),
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 9. User activities
CREATE TABLE User_Activities (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) REFERENCES Users(id),
    action VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    metadata TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_orders_user_id ON Orders(user_id);
CREATE INDEX idx_orders_status ON Orders(status);
CREATE INDEX idx_digital_accounts_product_status ON Digital_Accounts(product_id, status);
CREATE INDEX idx_products_is_active ON Products(is_active);
CREATE INDEX idx_support_tickets_user_id ON Support_Tickets(user_id);
CREATE INDEX idx_support_tickets_status ON Support_Tickets(status);
CREATE INDEX idx_support_ticket_messages_ticket_id ON Support_Ticket_Messages(ticket_id);
CREATE INDEX idx_payment_webhooks_transfer_syntax ON Payment_Webhooks(transfer_syntax);
CREATE INDEX idx_payment_webhooks_status ON Payment_Webhooks(status);
CREATE INDEX idx_order_items_order_id ON Order_Items(order_id);
CREATE INDEX idx_order_items_digital_account_id ON Order_Items(digital_account_id);
CREATE INDEX idx_user_activities_user_id ON User_Activities(user_id);
CREATE INDEX idx_user_activities_created_at ON User_Activities(created_at);

COMMIT;
