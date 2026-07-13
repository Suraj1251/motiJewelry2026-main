
-- Create Database

-- Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    mobile VARCHAR(15) UNIQUE NOT NULL,
    email VARCHAR(100),
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Products Table
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(50),
    price DECIMAL(10,2) NOT NULL,
    quantity INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Customers Table
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    mobile VARCHAR(15) UNIQUE NOT NULL,
    email VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Invoices Table
CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(50) UNIQUE NOT NULL,
    customer_name VARCHAR(100),
    customer_mobile VARCHAR(15),
    gst_type ENUM('gst', 'non_gst') DEFAULT 'non_gst',
    subtotal DECIMAL(10,2),
    gst_amount DECIMAL(10,2),
    total_amount DECIMAL(10,2),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Invoice Items Table
CREATE TABLE invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT,
    product_id INT,
    quantity INT,
    price DECIMAL(10,2),
    total DECIMAL(10,2),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- Insert Sample Products
INSERT INTO products (name, category, price, quantity) VALUES
('Gold Necklace', 'Gold', 45000, 10),
('Diamond Ring', 'Diamond', 85000, 5),
('Silver Earrings', 'Silver', 3500, 25),
('Gold Bangles', 'Gold', 28000, 8),
('Platinum Chain', 'Platinum', 125000, 3),
('Ruby Pendant', 'Gemstone', 32000, 6),
('Gold Mangalsutra', 'Gold', 65000, 4);

-- Insert Sample User (Password: 123456)
INSERT INTO users (name, mobile, email, password) VALUES
('Admin User', '9876543210', 'admin@gourijewellers.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- Forgot password

-- Add email column to users table if not exists

-- Create password reset table
CREATE TABLE IF NOT EXISTS password_resets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(100) NOT NULL,
    otp VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    is_used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_otp (otp)
);




-- Add serial_no and weight columns to products table
ALTER TABLE products ADD COLUMN serial_no VARCHAR(50) UNIQUE AFTER id;
ALTER TABLE products ADD COLUMN weight VARCHAR(20) AFTER name;

-- Update existing products with serial numbers if needed
UPDATE products SET serial_no = CONCAT('SN', LPAD(id, 4, '0')) WHERE serial_no IS NULL;


-- Add missing columns to customers table

-- Create invoices table if not exists
CREATE TABLE IF NOT EXISTS invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_no VARCHAR(50) UNIQUE NOT NULL,
    customer_id INT,
    customer_name VARCHAR(100),
    customer_mobile VARCHAR(15),
    customer_address TEXT,
    gst_type ENUM('gst', 'non_gst') DEFAULT 'non_gst',
    subtotal DECIMAL(10,2),
    gst_amount DECIMAL(10,2),
    discount DECIMAL(10,2) DEFAULT 0,
    total_amount DECIMAL(10,2),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
);

-- Create invoice_items table if not exists
CREATE TABLE IF NOT EXISTS invoice_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT,
    product_id INT,
    product_name VARCHAR(200),
    quantity DECIMAL(10,3),
    price DECIMAL(10,2),
    total DECIMAL(10,2),
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);


-- Income & Expenses Tables for Moti Jewellers

-- Income table
CREATE TABLE IF NOT EXISTS income (
    id INT PRIMARY KEY AUTO_INCREMENT,
    income_date DATE NOT NULL,
    source VARCHAR(100) NOT NULL,
    category VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description TEXT,
    payment_method ENUM('cash', 'card', 'upi', 'bank') DEFAULT 'cash',
    invoice_no VARCHAR(50),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (income_date),
    INDEX idx_category (category)
);

-- Expenses table
CREATE TABLE IF NOT EXISTS expenses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    expense_date DATE NOT NULL,
    category VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description TEXT,
    payment_method ENUM('cash', 'card', 'upi', 'bank') DEFAULT 'cash',
    bill_no VARCHAR(50),
    vendor_name VARCHAR(100),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (expense_date),
    INDEX idx_category (category)
);

-- Income Categories table
CREATE TABLE IF NOT EXISTS income_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_name VARCHAR(50) UNIQUE NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active'
);

-- Expense Categories table
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_name VARCHAR(50) UNIQUE NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active'
);

-- Insert default income categories
INSERT INTO income_categories (category_name) VALUES 
('Sales Income'),
('Interest Income'),
('Rental Income'),
('Commission Income'),
('Other Income')
ON DUPLICATE KEY UPDATE category_name = category_name;

-- Insert default expense categories
INSERT INTO expense_categories (category_name) VALUES 
('Purchase'),
('Rent'),
('Electricity Bill'),
('Salary'),
('Marketing'),
('Maintenance'),
('Tax Payment'),
('Transportation'),
('Other Expenses')
ON DUPLICATE KEY UPDATE category_name = category_name;


-- First, add payment_status column to invoices table
ALTER TABLE invoices ADD COLUMN payment_status VARCHAR(20) DEFAULT 'pending';

-- WhatsApp Settings Table
CREATE TABLE IF NOT EXISTS whatsapp_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    api_type VARCHAR(50) DEFAULT 'greenapi',
    api_url VARCHAR(255),
    api_token VARCHAR(255),
    instance_id VARCHAR(100),
    phone_number_id VARCHAR(100),
    access_token TEXT,
    status VARCHAR(20) DEFAULT 'inactive',
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- WhatsApp Message Templates Table
CREATE TABLE IF NOT EXISTS whatsapp_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    template_name VARCHAR(100) NOT NULL,
    template_type VARCHAR(50) DEFAULT 'custom',
    message_content TEXT NOT NULL,
    variables TEXT,
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- WhatsApp Message Log Table
CREATE TABLE IF NOT EXISTS whatsapp_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    recipient_number VARCHAR(20) NOT NULL,
    recipient_name VARCHAR(100),
    message_type VARCHAR(50),
    message_content TEXT,
    status VARCHAR(20) DEFAULT 'pending',
    api_response TEXT,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recipient (recipient_number),
    INDEX idx_status (status)
);

-- Auto Reminder Settings Table
CREATE TABLE IF NOT EXISTS reminder_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    reminder_type VARCHAR(50) DEFAULT 'due',
    days_before INT DEFAULT 0,
    reminder_time TIME DEFAULT '10:00:00',
    is_active INT DEFAULT 1,
    template_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Customer Due Tracking View (simplified version)
CREATE OR REPLACE VIEW customer_due_summary AS
SELECT 
    c.id as customer_id,
    c.name as customer_name,
    c.mobile as customer_mobile,
    COUNT(i.id) as total_invoices,
    SUM(i.total_amount) as total_due,
    MAX(i.created_at) as last_invoice_date,
    DATEDIFF(CURDATE(), IFNULL(MAX(i.created_at), CURDATE())) as days_overdue
FROM customers c
LEFT JOIN invoices i ON c.mobile = i.customer_mobile
WHERE i.payment_status IS NULL OR i.payment_status = 'pending'
GROUP BY c.id, c.name, c.mobile
HAVING total_due > 0;

-- Insert default templates
INSERT INTO whatsapp_templates (template_name, template_type, message_content) VALUES
('Due Reminder - 7 Days', 'due_reminder', 'Dear {name}, this is a friendly reminder that you have a pending payment of ₹{amount} from Moti Jewellers. Please clear your dues within 7 days to avoid late fees. Thank you!'),
('Due Reminder - 3 Days', 'due_reminder', 'URGENT: Dear {name}, your payment of ₹{amount} at Moti Jewellers is due in 3 days. Late payment charges may apply. Please settle at your earliest convenience.'),
('Due Reminder - Overdue', 'due_reminder', 'OVERDUE ALERT: Dear {name}, your payment of ₹{amount} at Moti Jewellers is now OVERDUE by {days} days. Please make the payment immediately. Contact us for assistance.'),
('Payment Received', 'custom', 'Dear {name}, thank you for your payment of ₹{amount}. Your account is now up to date. Thank you for choosing Moti Jewellers!'),
('Festival Greeting', 'festival_greeting', 'Wishing you and your family a very Happy {festival}! Enjoy special discounts on your next purchase at Moti Jewellers.');

-- Insert default reminder settings
INSERT INTO reminder_settings (reminder_type, days_before, reminder_time, template_id) 
SELECT 'due', -7, '10:00:00', id FROM whatsapp_templates WHERE template_name = 'Due Reminder - 7 Days' LIMIT 1;

INSERT INTO reminder_settings (reminder_type, days_before, reminder_time, template_id) 
SELECT 'due', -3, '10:00:00', id FROM whatsapp_templates WHERE template_name = 'Due Reminder - 3 Days' LIMIT 1;

INSERT INTO reminder_settings (reminder_type, days_before, reminder_time, template_id) 
SELECT 'due', 0, '10:00:00', id FROM whatsapp_templates WHERE template_name = 'Due Reminder - Overdue' LIMIT 1;

-- Update existing invoices
UPDATE invoices SET payment_status = 'pending' WHERE payment_status IS NULL;


-- Create otp_logins table
CREATE TABLE IF NOT EXISTS otp_logins (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(100) NOT NULL,
    otp VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    is_used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_otp (otp)
);

-- Add email column to users table if not exists

-- Insert test user
INSERT INTO users (name, mobile, email, password) VALUES 
('Supriyo Das', '9876543210', 'supriyodas@gmail.com', '')
ON DUPLICATE KEY UPDATE email = 'supriyodas@gmail.com';