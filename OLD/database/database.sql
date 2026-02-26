-- =============================================
-- IT Shop.LK - Complete Database Schema
-- =============================================
-- ADMINS TABLE
-- =============================================
CREATE TABLE admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(200),
    email VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- USERS TABLE
-- =============================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    full_name VARCHAR(200) GENERATED ALWAYS AS (CONCAT(first_name, ' ', last_name)) STORED,
    phone VARCHAR(20) NOT NULL,
    address TEXT NOT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(100) NOT NULL,
    zipcode VARCHAR(20) NOT NULL,
    country VARCHAR(100) NOT NULL DEFAULT 'Sri Lanka',
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_email (email),
    INDEX idx_created_at (created_at),
    INDEX idx_full_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- CATEGORIES TABLE
-- =============================================
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- SETTINGS TABLE
-- =============================================
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- PRODUCTS TABLE
-- =============================================
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    brand VARCHAR(100) NOT NULL,
    category VARCHAR(100) NOT NULL,
    category_id INT,
    category_name VARCHAR(100),
    price DECIMAL(10, 2) NOT NULL,
    original_price DECIMAL(10, 2) DEFAULT NULL,
    image VARCHAR(255) DEFAULT NULL,
    rating DECIMAL(3, 2) DEFAULT 0.00,
    reviews INT DEFAULT 0,
    stock_count INT DEFAULT 0,
    in_stock TINYINT(1) DEFAULT 1,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_category (category),
    INDEX idx_category_id (category_id),
    INDEX idx_brand (brand),
    INDEX idx_price (price),
    INDEX idx_in_stock (in_stock),
    INDEX idx_stock_count (stock_count),
    INDEX idx_rating (rating),
    FULLTEXT idx_search (name, brand, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- PRODUCT SPECS TABLE
-- =============================================
CREATE TABLE product_specs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    spec_name VARCHAR(255) NOT NULL,
    spec_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- CART TABLE
-- =============================================
CREATE TABLE cart (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    price DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_product (user_id, product_id),
    INDEX idx_user_id (user_id),
    INDEX idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- ORDERS TABLE
-- =============================================
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_number VARCHAR(50) NOT NULL UNIQUE,
    status ENUM('pending', 'processing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
    
    -- Pricing
    subtotal DECIMAL(10, 2) NOT NULL,
    shipping_cost DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10, 2) NOT NULL,
    currency VARCHAR(10) DEFAULT 'LKR',
    
    -- Payment
    payment_method VARCHAR(50) NOT NULL,
    payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
    
    -- Billing Information
    billing_first_name VARCHAR(100) NOT NULL,
    billing_last_name VARCHAR(100) NOT NULL,
    billing_email VARCHAR(255) NOT NULL,
    billing_phone VARCHAR(20) NOT NULL,
    billing_address TEXT NOT NULL,
    billing_city VARCHAR(100) NOT NULL,
    billing_postal_code VARCHAR(20) NOT NULL,
    
    -- Shipping Information
    shipping_first_name VARCHAR(100),
    shipping_last_name VARCHAR(100),
    shipping_address TEXT,
    shipping_city VARCHAR(100),
    shipping_postal_code VARCHAR(20),
    
    -- Additional
    special_instructions TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_order_number (order_number),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- ORDER ITEMS TABLE
-- =============================================
CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    total DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    INDEX idx_order_id (order_id),
    INDEX idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- SAMPLE DATA - ADMINS
-- =============================================
-- Default password is 'admin123' for all admin accounts
INSERT INTO admins (username, password, full_name, email) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin User', 'admin@itshop.lk'),
('superadmin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Super Admin', 'superadmin@itshop.lk');

-- =============================================
-- SAMPLE DATA - USERS
-- =============================================
INSERT INTO users (first_name, last_name, phone, address, city, state, zipcode, country, email, password) VALUES
('John', 'Doe', '+94771234567', '123 Main Street', 'Colombo', 'Western', '00100', 'Sri Lanka', 'john@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('Jane', 'Smith', '+94779876543', '456 Park Avenue', 'Kandy', 'Central', '20000', 'Sri Lanka', 'jane@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- =============================================
-- SAMPLE DATA - CATEGORIES
-- =============================================
INSERT INTO categories (name, description) VALUES
('Laptops', 'Portable computers for work and gaming'),
('Desktops', 'Desktop computers and workstations'),
('Graphics Cards', 'GPU cards for gaming and graphics work'),
('Memory', 'RAM modules for computers'),
('Monitors', 'Computer displays and screens'),
('Motherboards', 'Computer motherboards'),
('Processors', 'CPU processors'),
('Storage', 'Hard drives and SSDs'),
('Power Supply', 'Power supply units'),
('Peripherals', 'Keyboards, mice, and accessories'),
('Audio', 'Speakers, headphones, and audio devices'),
('Casings', 'Computer cases and chassis'),
('Cooling', 'Cooling systems and fans');

-- =============================================
-- SAMPLE DATA - PRODUCTS
-- =============================================
INSERT INTO products (name, brand, category, category_id, price, original_price, image, rating, reviews, stock_count, in_stock, description) VALUES
-- Laptops
('ROG Strix G15 Gaming Laptop', 'ASUS', 'laptops', 1, 285000.00, 320000.00, 'uploads/laptop1.jpg', 4.5, 42, 8, 1, 'High-performance gaming laptop with RTX 3060'),
('ThinkPad X1 Carbon Gen 10', 'Lenovo', 'laptops', 1, 245000.00, 280000.00, 'uploads/laptop2.jpg', 4.7, 35, 12, 1, 'Business ultrabook with 12th Gen Intel'),
('MacBook Air M2', 'Apple', 'laptops', 1, 325000.00, 350000.00, 'uploads/laptop3.jpg', 4.8, 67, 5, 1, 'Powerful and efficient with Apple M2 chip'),

-- Desktop PCs
('Gaming PC - RTX 4070', 'Custom Build', 'desktops', 2, 380000.00, 420000.00, 'uploads/desktop1.jpg', 4.6, 28, 4, 1, 'High-end gaming PC with latest components'),
('Office Workstation i7', 'Custom Build', 'desktops', 2, 165000.00, 185000.00, 'uploads/desktop2.jpg', 4.3, 19, 10, 1, 'Professional workstation for productivity'),

-- Graphics Cards
('GeForce RTX 4070 Ti', 'NVIDIA', 'graphics', 3, 185000.00, 210000.00, 'uploads/gpu1.jpg', 4.7, 52, 6, 1, 'Latest generation gaming GPU'),
('Radeon RX 7800 XT', 'AMD', 'graphics', 3, 145000.00, 165000.00, 'uploads/gpu2.jpg', 4.5, 38, 8, 1, 'High-performance AMD graphics card'),
('GeForce RTX 3060', 'NVIDIA', 'graphics', 3, 95000.00, 115000.00, 'uploads/gpu3.jpg', 4.4, 94, 15, 1, 'Mid-range gaming GPU with ray tracing'),

-- Memory (RAM)
('Corsair Vengeance 32GB DDR5', 'Corsair', 'memory', 4, 35000.00, 42000.00, 'uploads/ram1.jpg', 4.6, 76, 25, 1, '32GB DDR5 RAM kit for high performance'),
('G.Skill Trident Z5 64GB', 'G.Skill', 'memory', 4, 68000.00, 78000.00, 'uploads/ram2.jpg', 4.8, 45, 12, 1, '64GB DDR5 RGB RAM for enthusiasts'),
('Kingston Fury 16GB DDR4', 'Kingston', 'memory', 4, 18000.00, 22000.00, 'uploads/ram3.jpg', 4.3, 128, 40, 1, 'Reliable 16GB DDR4 memory kit'),

-- Monitors
('27" 4K Gaming Monitor', 'ASUS', 'monitors', 5, 95000.00, 115000.00, 'uploads/monitor1.jpg', 4.5, 63, 7, 1, '4K 144Hz gaming monitor with HDR'),
('32" Curved Ultrawide', 'Samsung', 'monitors', 5, 125000.00, 145000.00, 'uploads/monitor2.jpg', 4.6, 41, 5, 1, 'Immersive curved ultrawide display'),

-- Motherboards
('ROG Strix Z790-E', 'ASUS', 'motherboards', 6, 85000.00, 95000.00, 'uploads/mobo1.jpg', 4.7, 54, 9, 1, 'Premium Intel Z790 motherboard'),
('B650 AORUS Elite', 'Gigabyte', 'motherboards', 6, 45000.00, 52000.00, 'uploads/mobo2.jpg', 4.4, 38, 14, 1, 'AMD B650 motherboard for Ryzen 7000'),

-- Processors
('Intel Core i9-13900K', 'Intel', 'processors', 7, 145000.00, 165000.00, 'uploads/cpu1.jpg', 4.8, 72, 6, 1, 'Flagship Intel processor'),
('AMD Ryzen 9 7950X', 'AMD', 'processors', 7, 135000.00, 155000.00, 'uploads/cpu2.jpg', 4.7, 59, 8, 1, 'High-end AMD Ryzen processor'),
('Intel Core i5-13600K', 'Intel', 'processors', 7, 78000.00, 88000.00, 'uploads/cpu3.jpg', 4.6, 115, 18, 1, 'Mid-range gaming processor'),

-- Storage
('Samsung 990 PRO 2TB NVMe', 'Samsung', 'storage', 8, 45000.00, 52000.00, 'uploads/ssd1.jpg', 4.7, 89, 22, 1, 'Ultra-fast Gen4 NVMe SSD'),
('WD Black SN850X 1TB', 'Western Digital', 'storage', 8, 28000.00, 32000.00, 'uploads/ssd2.jpg', 4.5, 67, 30, 1, 'High-performance gaming SSD'),
('Crucial MX500 1TB SATA', 'Crucial', 'storage', 8, 15000.00, 18000.00, 'uploads/ssd3.jpg', 4.4, 156, 45, 1, 'Reliable SATA SSD'),

-- Power Supply
('EVGA SuperNOVA 850W', 'EVGA', 'power', 9, 35000.00, 42000.00, 'uploads/psu1.jpg', 4.6, 48, 16, 1, '80+ Gold modular PSU'),
('Corsair RM1000x 1000W', 'Corsair', 'power', 9, 48000.00, 55000.00, 'uploads/psu2.jpg', 4.8, 36, 10, 1, '80+ Gold high wattage PSU'),

-- Peripherals (Keyboards & Mouse)
('Logitech G Pro X Mechanical', 'Logitech', 'peripherals', 10, 28000.00, 32000.00, 'uploads/keyboard1.jpg', 4.5, 92, 20, 1, 'Professional gaming keyboard'),
('Razer DeathAdder V3', 'Razer', 'peripherals', 10, 18000.00, 22000.00, 'uploads/mouse1.jpg', 4.6, 134, 35, 1, 'Ergonomic gaming mouse'),
('Keychron K8 Pro Wireless', 'Keychron', 'peripherals', 10, 22000.00, 26000.00, 'uploads/keyboard2.jpg', 4.7, 78, 15, 1, 'Hot-swappable mechanical keyboard'),

-- Audio
('HyperX Cloud II Wireless', 'HyperX', 'audio', 11, 32000.00, 38000.00, 'uploads/headset1.jpg', 4.5, 112, 25, 1, 'Wireless gaming headset'),
('Logitech Z623 2.1 Speakers', 'Logitech', 'audio', 11, 28000.00, 35000.00, 'uploads/speakers1.jpg', 4.4, 67, 12, 1, 'THX-certified speaker system'),
('SteelSeries Arctis 7+', 'SteelSeries', 'audio', 11, 42000.00, 48000.00, 'uploads/headset2.jpg', 4.6, 89, 18, 1, 'Premium wireless gaming headset');

-- =============================================
-- SAMPLE DATA - SETTINGS
-- =============================================
INSERT INTO settings (setting_key, setting_value) VALUES
('site_name', 'IT Shop.LK'),
('site_email', 'admin@itshop.lk'),
('site_phone', '+94 077 900 5652'),
('site_address', 'Colombo, Sri Lanka'),
('smtp_host', 'smtp.gmail.com'),
('smtp_port', '587'),
('smtp_username', ''),
('smtp_password', ''),
('order_notification_email', 'orders@itshop.lk'),
('currency', 'LKR'),
('tax_rate', '0'),
('shipping_cost', '500');
-- =============================================
-- SAMPLE DATA - PRODUCT SPECS
-- =============================================
INSERT INTO product_specs (product_id, spec_name, spec_value) VALUES
-- ROG Strix G15 specs
(1, 'Processor', 'AMD Ryzen 9 6900HX'),
(1, 'Graphics', 'NVIDIA RTX 3060 6GB'),
(1, 'RAM', '16GB DDR5'),
(1, 'Storage', '1TB NVMe SSD'),
(1, 'Display', '15.6" FHD 165Hz'),

-- ThinkPad X1 Carbon specs
(2, 'Processor', 'Intel Core i7-1260P'),
(2, 'RAM', '16GB LPDDR5'),
(2, 'Storage', '512GB NVMe'),
(2, 'Display', '14" WUXGA'),
(2, 'Weight', '1.12kg'),

-- MacBook Air M2 specs
(3, 'Chip', 'Apple M2 8-core'),
(3, 'RAM', '16GB Unified Memory'),
(3, 'Storage', '512GB SSD'),
(3, 'Display', '13.6" Liquid Retina'),

-- RTX 4070 Ti specs
(6, 'Memory', '12GB GDDR6X'),
(6, 'Boost Clock', '2610 MHz'),
(6, 'CUDA Cores', '7680'),

-- Corsair RAM specs
(9, 'Capacity', '32GB (2x16GB)'),
(9, 'Speed', '6000MHz'),
(9, 'Latency', 'CL36'),
(9, 'RGB', 'Yes');

-- =============================================
-- INDEXES FOR OPTIMIZATION
-- =============================================
-- Already included in table definitions above

-- =============================================
-- VIEWS (OPTIONAL)
-- =============================================

-- View for products with full details (updated with category relationship)
CREATE OR REPLACE VIEW vw_products_full AS
SELECT 
    p.*,
    c.name as category_name,
    GROUP_CONCAT(CONCAT(ps.spec_name, ': ', COALESCE(ps.spec_value, '')) SEPARATOR ' | ') AS specifications,
    CASE 
        WHEN p.stock_count = 0 THEN 'Out of Stock'
        WHEN p.stock_count <= 5 THEN 'Low Stock'
        WHEN p.stock_count <= 20 THEN 'In Stock'
        ELSE 'Available'
    END AS stock_status,
    ROUND(((p.original_price - p.price) / p.original_price) * 100, 0) AS discount_percentage
FROM products p
LEFT JOIN categories c ON p.category_id = c.id
LEFT JOIN product_specs ps ON p.id = ps.product_id
GROUP BY p.id;

-- View for cart summary by user
CREATE OR REPLACE VIEW vw_cart_summary AS
SELECT 
    c.user_id,
    COUNT(DISTINCT c.product_id) AS item_count,
    SUM(c.quantity) AS total_quantity,
    SUM(c.price * c.quantity) AS cart_total,
    MAX(c.updated_at) AS last_updated
FROM cart c
GROUP BY c.user_id;

-- View for order details
CREATE OR REPLACE VIEW vw_order_details AS
SELECT 
    o.*,
    COUNT(oi.id) AS item_count,
    SUM(oi.quantity) AS total_items,
    CONCAT(o.billing_first_name, ' ', o.billing_last_name) AS customer_name
FROM orders o
LEFT JOIN order_items oi ON o.id = oi.order_id
GROUP BY o.id;

-- =============================================
-- STORED PROCEDURES (OPTIONAL)
-- =============================================

DELIMITER //

-- Procedure to add item to cart
CREATE PROCEDURE sp_add_to_cart(
    IN p_user_id INT,
    IN p_product_id INT,
    IN p_quantity INT
)
BEGIN
    DECLARE v_price DECIMAL(10,2);
    DECLARE v_stock INT;
    
    -- Get product price and stock
    SELECT price, stock_count INTO v_price, v_stock
    FROM products
    WHERE id = p_product_id AND in_stock = 1;
    
    IF v_stock >= p_quantity THEN
        INSERT INTO cart (user_id, product_id, quantity, price)
        VALUES (p_user_id, p_product_id, p_quantity, v_price)
        ON DUPLICATE KEY UPDATE 
            quantity = quantity + p_quantity,
            price = v_price,
            updated_at = CURRENT_TIMESTAMP;
        
        SELECT 'success' AS status, 'Item added to cart' AS message;
    ELSE
        SELECT 'error' AS status, 'Insufficient stock' AS message;
    END IF;
END //

-- Procedure to place order
CREATE PROCEDURE sp_place_order(
    IN p_user_id INT,
    IN p_order_number VARCHAR(50),
    IN p_payment_method VARCHAR(50),
    IN p_billing_data JSON,
    IN p_shipping_data JSON
)
BEGIN
    DECLARE v_order_id INT;
    DECLARE v_subtotal DECIMAL(10,2);
    DECLARE v_shipping DECIMAL(10,2) DEFAULT 500.00;
    
    -- Calculate cart total
    SELECT SUM(c.quantity * p.price) INTO v_subtotal
    FROM cart c
    JOIN products p ON c.product_id = p.id
    WHERE c.user_id = p_user_id;
    
    -- Start transaction
    START TRANSACTION;
    
    -- Create order
    INSERT INTO orders (
        user_id, order_number, subtotal, shipping_cost, 
        total_amount, currency, payment_method,
        billing_first_name, billing_last_name, billing_email,
        billing_phone, billing_address, billing_city, billing_postal_code
    ) VALUES (
        p_user_id, p_order_number, v_subtotal, v_shipping,
        v_subtotal + v_shipping, 'LKR', p_payment_method,
        JSON_UNQUOTE(JSON_EXTRACT(p_billing_data, '$.first_name')),
        JSON_UNQUOTE(JSON_EXTRACT(p_billing_data, '$.last_name')),
        JSON_UNQUOTE(JSON_EXTRACT(p_billing_data, '$.email')),
        JSON_UNQUOTE(JSON_EXTRACT(p_billing_data, '$.phone')),
        JSON_UNQUOTE(JSON_EXTRACT(p_billing_data, '$.address')),
        JSON_UNQUOTE(JSON_EXTRACT(p_billing_data, '$.city')),
        JSON_UNQUOTE(JSON_EXTRACT(p_billing_data, '$.postal_code'))
    );
    
    SET v_order_id = LAST_INSERT_ID();
    
    -- Insert order items
    INSERT INTO order_items (order_id, product_id, quantity, price, total)
    SELECT v_order_id, c.product_id, c.quantity, p.price, (c.quantity * p.price)
    FROM cart c
    JOIN products p ON c.product_id = p.id
    WHERE c.user_id = p_user_id;
    
    -- Update stock
    UPDATE products p
    JOIN cart c ON p.id = c.product_id
    SET p.stock_count = p.stock_count - c.quantity
    WHERE c.user_id = p_user_id AND p.stock_count >= c.quantity;
    
    -- Clear cart
    DELETE FROM cart WHERE user_id = p_user_id;
    
    COMMIT;
    
    SELECT 'success' AS status, v_order_id AS order_id, p_order_number AS order_number;
END //

DELIMITER ;

-- =============================================
-- TRIGGERS
-- =============================================

DELIMITER //

-- Trigger to update product stock status
CREATE TRIGGER trg_update_stock_status
BEFORE UPDATE ON products
FOR EACH ROW
BEGIN
    IF NEW.stock_count <= 0 THEN
        SET NEW.in_stock = 0;
    ELSE
        SET NEW.in_stock = 1;
    END IF;
END //

-- Trigger to prevent negative stock
CREATE TRIGGER trg_prevent_negative_stock
BEFORE UPDATE ON products
FOR EACH ROW
BEGIN
    IF NEW.stock_count < 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Stock count cannot be negative';
    END IF;
END //

DELIMITER ;

-- =============================================
-- GRANT PERMISSIONS (Adjust as needed)
-- =============================================
-- GRANT SELECT, INSERT, UPDATE, DELETE ON itshop.* TO 'itshop_user'@'localhost';
-- FLUSH PRIVILEGES;