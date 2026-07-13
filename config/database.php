<?php
$host = 'localhost';
$user = 'gouriUser';
$password = 'prc@1234';
$database = 'gouribilling';

    $conn = mysqli_connect($host, $user, $password, $database);

    if(!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }

    // Set timezone
    date_default_timezone_set('Asia/Kolkata');

    // Create tables if not exist
    $create_users = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        mobile VARCHAR(15) UNIQUE NOT NULL,
        email VARCHAR(100),
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    mysqli_query($conn, $create_users);

    $create_products = "CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        category VARCHAR(50),
        price DECIMAL(10,2) NOT NULL,
        quantity INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    mysqli_query($conn, $create_products);

    $create_customers = "CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        mobile VARCHAR(15) UNIQUE NOT NULL,
        email VARCHAR(100),
        address VARCHAR(255) DEFAULT '',
        gst_number VARCHAR(20) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    mysqli_query($conn, $create_customers);

    $chk_address = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'address'");
    if($chk_address && mysqli_num_rows($chk_address) == 0) {
        mysqli_query($conn, "ALTER TABLE customers ADD COLUMN address VARCHAR(255) DEFAULT '' AFTER email");
    }

    $chk_gst = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'gst_number'");
    if($chk_gst && mysqli_num_rows($chk_gst) == 0) {
        mysqli_query($conn, "ALTER TABLE customers ADD COLUMN gst_number VARCHAR(20) DEFAULT '' AFTER address");
    }

    $create_invoices = "CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_no VARCHAR(50) UNIQUE NOT NULL,
        customer_name VARCHAR(100) NOT NULL,
        customer_mobile VARCHAR(15) NOT NULL,
        gst_type ENUM('gst_3', 'gst_18', 'non_gst') DEFAULT 'non_gst',
        subtotal DECIMAL(10,2) DEFAULT 0,
        gst_amount DECIMAL(10,2) DEFAULT 0,
        total_amount DECIMAL(10,2) DEFAULT 0,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    mysqli_query($conn, $create_invoices);

    // Ensure account_paid column exists for NEFT tracking
    $chk_account = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'account_paid'");
    if($chk_account && mysqli_num_rows($chk_account) == 0) {
        mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN account_paid DECIMAL(10,2) DEFAULT 0");
    }

    // Update gst_type ENUM to include gst_3 and gst_18 (migration from old schema)
    $chk_gst = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'gst_type'");
    if($chk_gst && mysqli_num_rows($chk_gst) > 0) {
        $gst_col = mysqli_fetch_assoc($chk_gst);
        if($gst_col && strpos($gst_col['Type'], "'gst_3'") === false) {
            mysqli_query($conn, "ALTER TABLE invoices MODIFY COLUMN gst_type ENUM('gst_3', 'gst_18', 'non_gst') DEFAULT 'non_gst'");
        }
    }

    $create_invoice_items = "CREATE TABLE IF NOT EXISTS invoice_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT,
        product_id INT,
        quantity INT,
        price DECIMAL(10,2),
        total DECIMAL(10,2)
    )";
    mysqli_query($conn, $create_invoice_items);

    // Insert admin user if empty
    $check_admin = mysqli_query($conn, "SELECT id FROM users WHERE mobile = '96472 91299'");
    if(mysqli_num_rows($check_admin) == 0) {
        $hash = password_hash('123456', PASSWORD_DEFAULT);
        mysqli_query($conn, "INSERT INTO users (name, mobile, email, password) VALUES ('Admin User', '96472 91299', 'admin@gourijewellers.com', '$hash')");
    }

    // Set session user if needed
    if(isset($_SESSION['user_id'])) {
        $check = mysqli_query($conn, "SELECT id FROM users WHERE id = '{$_SESSION['user_id']}'");
        if(mysqli_num_rows($check) == 0) {
            $admin = mysqli_query($conn, "SELECT id FROM users WHERE mobile = '96472 91299'");
            $admin_row = mysqli_fetch_assoc($admin);
            $_SESSION['user_id'] = $admin_row['id'];
            $_SESSION['user_name'] = 'Admin User';
            $_SESSION['user_mobile'] = '96472 91299';
        }
    }
    ?>
