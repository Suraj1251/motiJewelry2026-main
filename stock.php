<?php
session_start();
require_once 'config/database.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Handle add/edit/delete product
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    if(isset($_POST['add_product'])) {
        $serial_no = mysqli_real_escape_string($conn, $_POST['serial_no']);
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $item_name_raw = $_POST['item_name'] ?? '';
        if($item_name_raw === 'Other' && !empty($_POST['item_name_custom'])) {
            $item_name_raw = $_POST['item_name_custom'];
        }
        $item_name = mysqli_real_escape_string($conn, $item_name_raw);
        $category = mysqli_real_escape_string($conn, $_POST['category']);
        $weight = mysqli_real_escape_string($conn, $_POST['weight']);
        $price = $_POST['price'];
        $quantity = $_POST['quantity'];

        $chk = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'item_name'");
        if(mysqli_num_rows($chk) == 0) {
            mysqli_query($conn, "ALTER TABLE products ADD COLUMN item_name VARCHAR(255) DEFAULT '' AFTER name");
        }
        $chk2 = mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'created_at'");
        if(mysqli_num_rows($chk2) == 0) {
            mysqli_query($conn, "ALTER TABLE products ADD COLUMN created_at DATETIME DEFAULT NULL");
        }

        $query = "INSERT INTO products (serial_no, name, item_name, category, weight, price, quantity, created_at) VALUES ('$serial_no', '$name', '$item_name', '$category', '$weight', '$price', '$quantity', NOW())";
        if(mysqli_query($conn, $query)) {
            $success = "✨ Product added successfully! ✨";
        } else {
            $error = "Error adding product: " . mysqli_error($conn);
        }
    }
    elseif(isset($_POST['update_quantity'])) {
        $id = $_POST['product_id'];
        $quantity = $_POST['quantity'];
        if(mysqli_query($conn, "UPDATE products SET quantity = quantity + $quantity WHERE id = $id")) {
            $success = "📦 Stock updated successfully!";
        } else {
            $error = "Error updating stock: " . mysqli_error($conn);
        }
    }
    elseif(isset($_POST['update_product'])) {
        $id = $_POST['product_id'];
        $serial_no = mysqli_real_escape_string($conn, $_POST['serial_no']);
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $item_name_raw2 = $_POST['item_name'] ?? '';
        if($item_name_raw2 === 'Other' && !empty($_POST['item_name_custom'])) { $item_name_raw2 = $_POST['item_name_custom']; }
        $item_name = mysqli_real_escape_string($conn, $item_name_raw2);
        $category = mysqli_real_escape_string($conn, $_POST['category']);
        $weight = mysqli_real_escape_string($conn, $_POST['weight']);
        $price = $_POST['price'];
        $quantity = $_POST['quantity'];

        $query = "UPDATE products SET serial_no='$serial_no', name='$name', item_name='$item_name', category='$category', weight='$weight', price='$price', quantity='$quantity' WHERE id=$id";
        if(mysqli_query($conn, $query)) {
            $success = "💎 Product updated successfully! 💎";
        } else {
            $error = "Error updating product: " . mysqli_error($conn);
        }
    }
    elseif(isset($_POST['delete_product'])) {
        $id = $_POST['product_id'];
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
        mysqli_query($conn, "DELETE FROM invoice_items WHERE product_id = $id");
        if(mysqli_query($conn, "DELETE FROM products WHERE id = $id")) {
            $success = "🗑️ Product deleted successfully!";
        } else {
            $error = "Error deleting product: " . mysqli_error($conn);
        }
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
    }
}

$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
if(!empty($search)) {
    $products = mysqli_query($conn, "SELECT * FROM products WHERE serial_no LIKE '%$search%' OR name LIKE '%$search%' ORDER BY id");
} else {
    $products = mysqli_query($conn, "SELECT * FROM products ORDER BY id");
}
$low_stock = mysqli_query($conn, "SELECT * FROM products WHERE quantity < 5 ORDER BY quantity ASC");
$logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Live Stock - Maa Gouri Jewellers</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');

        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3,.gold-font { font-family: 'Playfair Display', serif; }

        /* ========== SIDEBAR (identical to index.php) ========== */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            width: 240px;
            height: 100vh;
            background: linear-gradient(180deg, #7a4e0a 0%, #b5730e 40%, #d68b16 100%);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            box-shadow: 4px 0 24px rgba(0,0,0,0.25);
            transition: transform 0.35s cubic-bezier(.4,0,.2,1);
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }

        .sidebar-logo {
            padding: 22px 18px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.18);
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }

        .sidebar-logo img {
            width: 44px; height: 44px;
            object-fit: contain;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
            padding: 3px;
            flex-shrink: 0;
        }

        .sidebar-logo-text h2 {
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.3;
            font-family: 'Playfair Display', serif;
            letter-spacing: 0.5px;
        }

        .sidebar-logo-text p {
            color: rgba(255,255,255,0.65);
            font-size: 10px;
            margin-top: 1px;
        }

        .sidebar-nav { flex: 1; padding: 10px 0; }

        .sidebar-section-label {
            padding: 10px 20px 4px;
            color: rgba(255,255,255,0.45);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 20px;
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
            letter-spacing: 0.3px;
            position: relative;
        }

        .sidebar-nav a:hover {
            background: rgba(255,255,255,0.13);
            color: #fff;
            border-left-color: rgba(255,255,255,0.8);
            padding-left: 26px;
        }

        .sidebar-nav a.active {
            background: rgba(255,255,255,0.22);
            color: #fff;
            border-left-color: #fff;
            font-weight: 700;
        }

        .sidebar-nav a.active::after {
            content: '';
            position: absolute;
            right: 0; top: 50%;
            transform: translateY(-50%);
            width: 4px; height: 60%;
            background: #fff;
            border-radius: 4px 0 0 4px;
        }

        .sidebar-nav a i {
            width: 18px;
            text-align: center;
            font-size: 14px;
            flex-shrink: 0;
            opacity: 0.9;
        }

        .sidebar-divider {
            height: 1px;
            background: rgba(255,255,255,0.12);
            margin: 6px 16px;
        }

        .sidebar-user {
            padding: 14px 16px 18px;
            border-top: 1px solid rgba(255,255,255,0.18);
            background: rgba(0,0,0,0.12);
            flex-shrink: 0;
        }

        .sidebar-user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .sidebar-user-info i { color: rgba(255,255,255,0.9); font-size: 26px; flex-shrink: 0; }
        .sidebar-user-info .user-details p { color: #fff; font-size: 12px; font-weight: 600; line-height: 1.3; }
        .sidebar-user-info .user-details span { color: rgba(255,255,255,0.55); font-size: 10px; }

        .sidebar-logout {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 14px;
            background: rgba(239,68,68,0.75);
            color: #fff;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.2s;
            letter-spacing: 0.5px;
            border: 1px solid rgba(239,68,68,0.4);
        }

        .sidebar-logout:hover { background: #ef4444; color: #fff; }

        /* Sidebar overlay (mobile) */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            backdrop-filter: blur(2px);
        }
        .sidebar-overlay.active { display: block; }

        /* ========== MAIN LAYOUT ========== */
        .page-wrapper {
            margin-left: 240px;
            min-height: 100vh;
            transition: margin-left 0.35s ease;
        }

        /* ========== TOP NAVBAR ========== */
        nav.nav-gold {
            background: linear-gradient(135deg, #b5730e, #d68b16) !important;
        }

        /* ========== BURGER MENU ========== */
        .burger-menu {
            width: 28px; height: 20px;
            position: relative;
            cursor: pointer;
        }

        .burger-menu span {
            display: block;
            position: absolute;
            height: 3px; width: 100%;
            background: #ffffff;
            border-radius: 3px;
            transition: all 0.3s ease;
        }

        .burger-menu span:nth-child(1) { top: 0px; }
        .burger-menu span:nth-child(2) { top: 9px; }
        .burger-menu span:nth-child(3) { top: 18px; }

        .burger-menu.active span:nth-child(1) { top: 9px; transform: rotate(135deg); }
        .burger-menu.active span:nth-child(2) { opacity: 0; left: -20px; }
        .burger-menu.active span:nth-child(3) { top: 9px; transform: rotate(-135deg); }

        /* ========== MOBILE RESPONSIVE ========== */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .page-wrapper { margin-left: 0 !important; }
            .mobile-burger { display: block !important; }
            nav.nav-gold { margin-left: 0 !important; }
        }

        @media (min-width: 769px) {
            .mobile-burger { display: none !important; }
        }

        /* ========== PAGE BODY ========== */
        body { background: #F5F5F5; margin: 0; padding: 0; }

        /* ========== STOCK PAGE STYLES ========== */

        /* Jewel Card */
        .jewel-card {
            background: #fff;
            border: 1px solid rgba(181,115,14,0.2);
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(181,115,14,0.08);
            transition: all 0.3s ease;
        }

        .jewel-card:hover {
            border-color: #d68b16;
            box-shadow: 0 8px 30px rgba(181,115,14,0.15);
        }

        /* Form Inputs */
        .jewel-input {
            background: #fdf6e3;
            border: 1px solid rgba(181,115,14,0.3);
            color: #4a3000;
            border-radius: 10px;
            transition: all 0.3s ease;
            padding: 8px 12px;
            width: 100%;
        }

        .jewel-input:focus {
            border-color: #d68b16;
            box-shadow: 0 0 0 3px rgba(214,139,22,0.15);
            outline: none;
        }

        .jewel-input::placeholder { color: rgba(122,78,10,0.4); }
        select.jewel-input option { background: #fff; color: #4a3000; }
        select.jewel-input optgroup { background: #fdf6e3; color: #800020; font-weight: bold; font-style: normal; }

        .custom-item-input { display: none; }
        .custom-item-input.show { display: block; }

        label { color: #7a4e0a; font-weight: 500; font-size: 13px; }

        /* Table */
        .jewel-table { width: 100%; border-collapse: collapse; }
        .jewel-table th {
            background: linear-gradient(135deg, #7a4e0a, #d68b16);
            color: #fff;
            font-weight: 600;
            padding: 12px 10px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .jewel-table td {
            border-bottom: 1px solid rgba(181,115,14,0.1);
            padding: 10px;
            color: #3a2800;
            font-size: 13px;
        }
        .jewel-table tbody tr:hover { background: #fdf6e3; }

        .serial-number-col {
            background: rgba(181,115,14,0.08);
            font-weight: 600;
            color: #7a4e0a;
            text-align: center;
        }

        /* Status badges */
        .status-low    { background: #FEE2E2; color: #991B1B; }
        .status-medium { background: #FEF3C7; color: #92400E; }
        .status-high   { background: #D1FAE5; color: #065F46; }

        /* Buttons */
        .btn-jewel {
            background: linear-gradient(135deg, #800020, #d68b16);
            border: none;
            border-radius: 50px;
            padding: 10px 22px;
            font-weight: 700;
            color: #fff;
            transition: all 0.3s ease;
            display: inline-block;
            text-decoration: none;
            font-size: 13px;
            cursor: pointer;
        }
        .btn-jewel:hover {
            transform: scale(1.04);
            box-shadow: 0 8px 24px rgba(214,139,22,0.35);
            color: #fff;
        }

        .btn-edit {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: #fff;
            border: 1px solid rgba(59,130,246,0.6);
            border-radius: 10px;
            padding: 8px 14px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 8px rgba(59,130,246,0.3);
            letter-spacing: 0.3px;
        }
        .btn-edit:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 6px 16px rgba(59,130,246,0.5);
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
        }
        .btn-edit:active { transform: translateY(0); }

        .btn-addstock {
            background: linear-gradient(135deg, #d68b16, #a85a0a);
            color: #fff;
            border: 1px solid rgba(214,139,22,0.6);
            border-radius: 10px;
            padding: 8px 14px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 8px rgba(214,139,22,0.3);
            letter-spacing: 0.3px;
        }
        .btn-addstock:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 6px 16px rgba(214,139,22,0.5);
            background: linear-gradient(135deg, #e89917, #d68b16);
        }
        .btn-addstock:active { transform: translateY(0); }

        .btn-delete {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #fff;
            border: 1px solid rgba(239,68,68,0.6);
            border-radius: 10px;
            padding: 8px 14px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 8px rgba(239,68,68,0.3);
            letter-spacing: 0.3px;
        }
        .btn-delete:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 6px 16px rgba(239,68,68,0.5);
            background: linear-gradient(135deg, #f87171, #ef4444);
        }
        .btn-delete:active { transform: translateY(0); }

        .action-btns { display: flex; gap: 8px; justify-content: center; align-items: center; flex-wrap: nowrap; }

        .search-highlight { background: #fffbeb !important; border-left: 3px solid #d68b16; }

        /* Modal */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-overlay.flex { display: flex; }

        .modal-content {
            background: linear-gradient(145deg, #fdf6e3, #fff);
            border: 1px solid rgba(181,115,14,0.35);
            border-radius: 20px;
            padding: 28px;
            width: 100%;
            max-width: 460px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }

        .modal-content h3 { color: #800020; margin-bottom: 18px; }
        .modal-content label { color: #7a4e0a; display: block; margin-bottom: 4px; font-size: 12px; font-weight: 600; }

        /* Search box */
        .search-wrapper { position: relative; }
        .search-wrapper .search-icon {
            position: absolute;
            left: 10px; top: 50%;
            transform: translateY(-50%);
            color: #d68b16;
            font-size: 13px;
        }
        .search-wrapper input { padding-left: 32px; }

        /* Page heading */
        .page-heading {
            background: linear-gradient(135deg, #fdf6e3, #f5ead0);
            border-bottom: 2px solid rgba(181,115,14,0.2);
            padding: 20px 28px;
        }
        .page-heading h1 { color: #800020; font-size: 1.6rem; }
        .page-heading p { color: #7a4e0a; font-size: 13px; margin-top: 2px; }

        /* Responsive */
        @media (max-width: 640px) {
            .stock-grid { grid-template-columns: 1fr !important; }
            .table-container { overflow-x: auto; }
            .jewel-table { min-width: 680px; }
            .action-btns { flex-wrap: wrap; gap: 6px; }
            .action-btns button { flex: 1; min-width: 70px; padding: 6px 8px; font-size: 10px; }
        }

        /* ========== SPARKLES & LOADING OVERLAY ========== */
        .jewel-sparkle { position: fixed; border-radius: 50%; pointer-events: none; z-index: 0; animation: sparkleFloat linear infinite; }
        @keyframes sparkleFloat { 0% { transform: translateY(100vh) scale(0); opacity: 0; } 10% { opacity: 1; } 90% { opacity: 0.5; } 100% { transform: translateY(-10vh) scale(1); opacity: 0; } }
        @keyframes ornFloat { 0%,100%{transform:rotate(0deg) scale(1);opacity:0.15} 50%{transform:rotate(20deg) scale(1.15);opacity:0.28} }
        @keyframes haloPulse { 0%,100%{opacity:0.3;transform:scale(1)} 50%{opacity:1;transform:scale(1.1)} }
        @keyframes gemGlowPulse { 0%,100%{filter:drop-shadow(0 0 8px #d68b16)} 50%{filter:drop-shadow(0 0 22px #ff9900)} }
        @keyframes titleGold { from{color:#d68b16} to{color:#f5c842} }
        @keyframes barSlide { 0%{transform:translateX(-100%)} 100%{transform:translateX(480%)} }
        @keyframes dotBounce { 0%,100%{opacity:0.3;transform:scale(0.7)} 50%{opacity:1;transform:scale(1.2)} }
        @keyframes starFade { 0%{opacity:0;transform:scale(0)} 50%{opacity:1} 100%{opacity:0;transform:scale(1)} }
        @keyframes ringExpand { 0%{opacity:0.7;transform:scale(0.2)} 100%{opacity:0;transform:scale(2)} }
    </style>
</head>
<body>

<script>
    function createJewelSparkles() {
        const colors = ['#d68b16','#b5730e','#800020','#c9a96e','#f5c842'];
        document.querySelectorAll('.jewel-sparkle').forEach(s => s.remove());
        for(let i = 0; i < 50; i++) {
            const s = document.createElement('div');
            s.className = 'jewel-sparkle';
            s.style.left = Math.random() * 100 + '%';
            s.style.animationDelay = Math.random() * 8 + 's';
            s.style.animationDuration = (4 + Math.random() * 6) + 's';
            const sz = (Math.random() * 7 + 2) + 'px';
            s.style.width = sz; s.style.height = sz;
            s.style.background = `radial-gradient(circle, ${colors[Math.floor(Math.random()*colors.length)]}, transparent)`;
            document.body.appendChild(s);
        }
    }

    const texts = ["MAA GOURI JEWELLERS"];
    let textIndex = 0, charIndex = 0, isDeleting = false, typingSpeed = 100;

    function typeEffect() {
        const el = document.getElementById('typingText');
        if(!el) return;
        const cur = texts[textIndex];
        if(isDeleting) { el.innerHTML = cur.substring(0, charIndex - 1); charIndex--; typingSpeed = 50; }
        else { el.innerHTML = cur.substring(0, charIndex + 1); charIndex++; typingSpeed = 100; }
        if(!isDeleting && charIndex === cur.length) { isDeleting = true; typingSpeed = 2000; }
        else if(isDeleting && charIndex === 0) { isDeleting = false; textIndex = 0; typingSpeed = 500; }
        setTimeout(typeEffect, typingSpeed);
    }

    function toggleSidebar() {
        const sidebar = document.getElementById('mainSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const burger  = document.getElementById('burgerMenu');
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
        burger.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    }

    function closeSidebar() {
        document.getElementById('mainSidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
        document.getElementById('burgerMenu').classList.remove('active');
        document.body.style.overflow = '';
    }

    window.addEventListener('load', function() {
        createJewelSparkles();
        setTimeout(typeEffect, 600);
        setTimeout(function() {
            const ov = document.getElementById('loadingOverlay');
            if(ov) { ov.style.opacity = '0'; ov.style.visibility = 'hidden'; setTimeout(()=>ov.style.display='none', 500); }
        }, 2000);
    });
</script>

<!-- Loading Overlay -->
<div id="loadingOverlay" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999;display:flex;justify-content:center;align-items:center;overflow:hidden;transition:opacity 0.6s ease,visibility 0.6s ease;background:radial-gradient(ellipse at 50% 60%, #1a0a00 0%, #0d0500 100%);">
    <div style="position:absolute;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 3px,rgba(214,139,22,0.015) 3px,rgba(214,139,22,0.015) 4px);pointer-events:none;z-index:1;"></div>
    <div style="position:absolute;top:28px;left:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite;">✦</div>
    <div style="position:absolute;top:28px;right:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 1s;">✦</div>
    <div style="position:absolute;bottom:28px;left:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 2s;">✦</div>
    <div style="position:absolute;bottom:28px;right:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 3s;">✦</div>
    <div id="loaderStars" style="position:absolute;inset:0;pointer-events:none;z-index:2;"></div>
    <div id="loaderRings" style="position:absolute;inset:0;pointer-events:none;z-index:2;display:flex;align-items:center;justify-content:center;"></div>
    <div style="position:relative;z-index:10;text-align:center;">
        <div style="position:relative;width:110px;height:110px;margin:0 auto 28px;">
            <div style="position:absolute;inset:-12px;border-radius:50%;border:2px solid rgba(214,139,22,0.4);animation:haloPulse 1.5s ease-in-out infinite;"></div>
            <div style="position:absolute;inset:-24px;border-radius:50%;border:1px solid rgba(214,139,22,0.2);animation:haloPulse 1.5s ease-in-out infinite 0.5s;"></div>
            <svg viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg" style="width:90px;height:90px;position:absolute;top:10px;left:10px;animation:gemGlowPulse 2s ease-in-out infinite;">
                <defs>
                    <linearGradient id="lg1" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" style="stop-color:#ff9900"/>
                        <stop offset="45%" style="stop-color:#d68b16"/>
                        <stop offset="100%" style="stop-color:#800020"/>
                    </linearGradient>
                    <linearGradient id="lg2" x1="100%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" style="stop-color:#f5c842;stop-opacity:0.9"/>
                        <stop offset="100%" style="stop-color:#b5730e;stop-opacity:0.9"/>
                    </linearGradient>
                </defs>
                <polygon points="40,2 76,22 76,58 40,78 4,58 4,22" fill="url(#lg1)" stroke="#f5c842" stroke-width="1.5"/>
                <polygon points="40,2 76,22 40,40" fill="url(#lg2)" opacity="0.7"/>
                <polygon points="76,22 76,58 40,40" fill="#800020" opacity="0.5"/>
                <polygon points="76,58 40,78 40,40" fill="#b5730e" opacity="0.6"/>
                <polygon points="40,78 4,58 40,40" fill="#d68b16" opacity="0.4"/>
                <polygon points="4,58 4,22 40,40" fill="#ff9900" opacity="0.35"/>
                <polygon points="4,22 40,2 40,40" fill="url(#lg2)" opacity="0.55"/>
                <polygon points="40,14 68,28 68,52 40,66 12,52 12,28" fill="none" stroke="rgba(255,255,255,0.25)" stroke-width="0.8"/>
            </svg>
        </div>
        <div style="color:#d68b16;font-size:22px;letter-spacing:6px;font-family:'Playfair Display',serif;margin-bottom:6px;animation:titleGold 2s ease infinite alternate;">MAA GOURI JEWELLERS</div>
        <p style="color:rgba(201,169,110,0.7);font-size:10px;letter-spacing:4px;text-transform:uppercase;margin-bottom:24px;">Crafting Timeless Elegance</p>
        <div style="width:200px;height:3px;background:rgba(255,255,255,0.08);border-radius:3px;margin:0 auto 16px;overflow:hidden;">
            <div style="height:100%;width:35%;background:linear-gradient(90deg,#7a4e0a,#d68b16,#f5c842);border-radius:3px;animation:barSlide 1.8s ease-in-out infinite;"></div>
        </div>
        <div style="display:flex;gap:9px;justify-content:center;">
            <div style="width:6px;height:6px;border-radius:50%;background:#d68b16;animation:dotBounce 1.2s ease-in-out infinite;"></div>
            <div style="width:6px;height:6px;border-radius:50%;background:#d68b16;animation:dotBounce 1.2s ease-in-out infinite 0.2s;"></div>
            <div style="width:6px;height:6px;border-radius:50%;background:#d68b16;animation:dotBounce 1.2s ease-in-out infinite 0.4s;"></div>
        </div>
    </div>
</div>

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ========== SIDEBAR ========== -->
<div class="sidebar" id="mainSidebar">

    <!-- Logo -->
    <div class="sidebar-logo">
        <?php
        $logo_found = false;
        foreach($logo_paths as $path) {
            if(file_exists($path)) {
                echo '<img src="'.$path.'" alt="Maa Gouri Jewellers Logo">';
                $logo_found = true; break;
            }
        }
        if(!$logo_found) echo '<i class="fas fa-gem" style="color:#fff;font-size:30px;flex-shrink:0;"></i>';
        ?>
        <div class="sidebar-logo-text">
            <h2>MAA GOURI JEWELLERS</h2>
            <p>Premium Since 2026</p>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
        <div class="sidebar-section-label">Main Menu</div>

        <a href="index.php">
            <i class="fas fa-home"></i> HOME
        </a>
        <a href="billing.php">
            <i class="fas fa-receipt"></i> BILLING
        </a>
        <a href="stock.php" class="active">
            <i class="fas fa-boxes"></i> STOCK
        </a>
        <a href="customers.php">
            <i class="fas fa-users"></i> CUSTOMERS
        </a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Analytics</div>

        <a href="reports.php">
            <i class="fas fa-chart-bar"></i> REPORTS
        </a>
        <a href="income_expenses.php">
            <i class="fas fa-chart-line"></i> INCOME &amp; EXP
        </a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Tools</div>

        <a href="whatsapp_automation.php">
            <i class="fab fa-whatsapp"></i> WHATSAPP
        </a>
        <a href="sbook.php">
            <i class="fas fa-book"></i> karigori
        </a>
    </nav>

    <!-- User Info + Logout -->
    <div class="sidebar-user">
        <div class="sidebar-user-info">
            <i class="fas fa-user-circle"></i>
            <div class="user-details">
                <p><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                <span><?php echo htmlspecialchars($_SESSION['user_mobile'] ?? 'Admin'); ?></span>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout">
            <i class="fas fa-sign-out-alt"></i> LOGOUT
        </a>
    </div>
</div>
<!-- ========== END SIDEBAR ========== -->

<!-- ========== TOP NAVBAR ========== -->
<nav class="nav-gold shadow-lg sticky top-0 z-50" style="margin-left:240px;">
    <div class="container mx-auto px-4 sm:px-6 py-3 sm:py-4">
        <div class="flex justify-between items-center">
            <div class="ml-auto flex items-center gap-4">
                <span class="text-sm font-medium text-white hidden sm:inline">
                    <i class="fas fa-user mr-1"></i>
                    <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                </span>
                <!-- Mobile burger -->
                <div class="mobile-burger" style="display:none;">
                    <div class="burger-menu" id="burgerMenu" onclick="toggleSidebar()">
                        <span></span><span></span><span></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- ========== PAGE WRAPPER ========== -->
<div class="page-wrapper">

    <!-- Page Heading -->
    <div class="page-heading">
        <h1 class="gold-font"><i class="fas fa-boxes mr-2"></i> Live Stock Management</h1>
        <p>Manage your jewellery inventory in real-time</p>
    </div>

    <div class="container mx-auto px-4 sm:px-6 py-6">

        <!-- Success/Error Messages -->
        <?php if(isset($success)): ?>
            <div class="bg-green-50 border border-green-400 text-green-800 px-4 py-3 rounded-lg mb-4 text-sm">
                <i class="fas fa-check-circle mr-2 text-green-500"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>
        <?php if(isset($error)): ?>
            <div class="bg-red-50 border border-red-400 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm">
                <i class="fas fa-exclamation-circle mr-2 text-red-500"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Low Stock Alert -->
        <?php if(mysqli_num_rows($low_stock) > 0): ?>
        <div class="mb-5 p-4 rounded-lg" style="background:#FEF2F2;border-left:4px solid #EF4444;">
            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                <i class="fas fa-exclamation-triangle text-red-500 text-xl flex-shrink-0"></i>
                <div>
                    <p class="font-bold text-red-700 text-sm">⚠️ Low Stock Alert!</p>
                    <p class="text-red-600 text-xs mb-2">Following products have low stock (less than 5 units):</p>
                    <div class="flex flex-wrap gap-2">
                        <?php
                        mysqli_data_seek($low_stock, 0);
                        while($item = mysqli_fetch_assoc($low_stock)): ?>
                            <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold" style="background:#FEE2E2;color:#991B1B;">
                                💎 <?php echo htmlspecialchars($item['name']); ?>: <?php echo $item['quantity']; ?> left
                            </span>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Main Grid -->
        <div class="stock-grid grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Add Product Form -->
            <div>
                <div class="jewel-card p-5">
                    <h2 class="text-lg font-bold mb-4 gold-font" style="color:#800020;">
                        <i class="fas fa-plus-circle mr-2" style="color:#d68b16;"></i> Add New Product
                    </h2>
                    <form method="POST">

                        <div class="mb-3">
                            <label>💎 Product Name</label>
                            <input list="productNameList" type="text" id="addProductName" name="name" placeholder="Enter product name or choose Others" required class="jewel-input" onchange="onAddProductNameChange()">
                            <datalist id="productNameList">
                                <option value="Others"></option>
                            </datalist>
                        </div>

                        <div class="mb-3">
                            <label>✨ Category</label>
                            <select name="category" id="addCategorySelect" required class="jewel-input" onchange="updateItemTypes('addCategorySelect','addItemSelect','addCustomItem')">
                                <option value="">-- Select Category --</option>
                                <optgroup label="🥇 Gold">
                                    <option value="Gold 22K">Gold 22K</option>
                                    <option value="Gold 18K">Gold 18K</option>
                                </optgroup>
                                <optgroup label="🥈 Silver">
                                    <option value="Silver">Silver</option>
                                </optgroup>
                                <optgroup label="💎 Stone">
                                    <option value="Stone">Stone</option>
                                </optgroup>
                                <optgroup label="💎 Diamond">
                                    <option value="Diamond">Diamond</option>
                                </optgroup>
                                <optgroup label="🟤 Other">
                                    <option value="Other">Other</option>
                                </optgroup>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label>🏷️ Item Type</label>
                            <select name="item_name" id="addItemSelect" required class="jewel-input" onchange="toggleCustomItem('addCustomItem', this.value)">
                                <option value="">-- Select Category First --</option>
                            </select>
                            <div class="custom-item-input mt-2" id="addCustomItem">
                                <input type="text" name="item_name_custom" placeholder="Custom item name..." class="jewel-input">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label>🔢 Serial Number</label>
                            <input type="text" name="serial_no" placeholder="Unique serial number" required class="jewel-input">
                        </div>

                        <div class="mb-3">
                            <label>⚖️ Weight (grams)</label>
                            <input type="text" name="weight" placeholder="e.g. 12.5" class="jewel-input">
                        </div>

                        <input type="hidden" name="price" value="0">

                        <div class="mb-4">
                            <label>📦 Quantity</label>
                            <input type="number" name="quantity" placeholder="Enter quantity" required class="jewel-input">
                        </div>

                        <button type="submit" name="add_product" class="btn-jewel w-full text-center">
                            <i class="fas fa-save mr-1"></i> Add Product
                        </button>
                    </form>
                </div>
            </div>

            <!-- Products List -->
            <div class="lg:col-span-2">
                <div class="jewel-card p-5">
                    <div class="flex flex-wrap justify-between items-center mb-4 gap-3">
                        <h2 class="text-lg font-bold gold-font" style="color:#800020;">
                            <i class="fas fa-list mr-2" style="color:#d68b16;"></i> Current Stock
                        </h2>

                        <!-- Search -->
                        <form method="GET" class="flex flex-wrap gap-2">
                            <div class="search-wrapper">
                                <i class="fas fa-search search-icon"></i>
                                <input type="text" name="search" placeholder="Search by Serial No / Name"
                                    value="<?php echo htmlspecialchars($search); ?>"
                                    class="jewel-input" style="padding-left:32px;width:220px;">
                            </div>
                            <button type="submit" class="btn-jewel" style="padding:8px 18px;font-size:12px;">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <?php if(!empty($search)): ?>
                                <a href="stock.php" class="btn-jewel" style="padding:8px 18px;font-size:12px;background:linear-gradient(135deg,#6b7280,#4b5563);">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <?php if(!empty($search) && mysqli_num_rows($products) == 0): ?>
                        <div class="text-center py-4 text-sm" style="color:#7a4e0a;background:#fdf6e3;border-radius:10px;border:1px solid rgba(181,115,14,0.2);">
                            <i class="fas fa-search mr-2" style="color:#d68b16;"></i>
                            No product found matching "<strong><?php echo htmlspecialchars($search); ?></strong>"
                        </div>
                    <?php endif; ?>

                    <div class="table-container overflow-x-auto rounded-xl" style="border:1px solid rgba(181,115,14,0.15);">
                        <table class="jewel-table">
                            <thead>
                                <tr>
                                    <th class="text-left" style="border-radius:12px 0 0 0;">SL</th>
                                    <th class="text-left">Product Details</th>
                                    <th class="text-left">Item Name</th>
                                    <th class="text-left">Weight</th>
                                    <th class="text-left">Added On</th>
                                    <th class="text-center">Stock</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center" style="border-radius:0 12px 0 0;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $serial = 1;
                                mysqli_data_seek($products, 0);
                                while($product = mysqli_fetch_assoc($products)):
                                    $qty = $product['quantity'];
                                    $status_class = $qty < 5 ? 'status-low' : ($qty < 20 ? 'status-medium' : 'status-high');
                                    $status_text  = $qty < 5 ? 'Critical' : ($qty < 20 ? 'Low' : 'Good');
                                    $highlight = (!empty($search) && ($product['serial_no'] == $search || stripos($product['name'], $search) !== false)) ? 'search-highlight' : '';
                                ?>
                                <tr class="<?php echo $highlight; ?>">
                                    <td class="serial-number-col"><?php echo $serial++; ?></td>
                                    <td>
                                        <div class="font-semibold" style="color:#800020;"><?php echo htmlspecialchars($product['name']); ?></div>
                                        <div class="text-xs" style="color:#7a4e0a;"><?php echo htmlspecialchars($product['category'] ?? ''); ?></div>
                                    </td>
                                    <td>
                                        <div class="font-medium text-sm" style="color:#b5730e;"><?php echo htmlspecialchars($product['item_name'] ?? '—'); ?></div>
                                        <div class="text-xs" style="color:#9ca3af;">SN: <?php echo htmlspecialchars($product['serial_no']); ?></div>
                                    </td>
                                    <td class="text-sm">
                                        <?php echo htmlspecialchars($product['weight'] ?? 'N/A'); ?>
                                        <span class="text-xs" style="color:#9ca3af;">g</span>
                                    </td>
                                    <td class="text-xs" style="color:#6b7280;">
                                        <?php if(!empty($product['created_at'])): ?>
                                            <span class="font-medium"><?php echo date('d M Y', strtotime($product['created_at'])); ?></span>
                                        <?php else: ?>
                                            <span style="color:#d1d5db;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center font-bold text-sm" style="color:#800020;"><?php echo $qty; ?></td>
                                    <td class="text-center">
                                        <span class="px-2 py-1 rounded-full text-xs font-semibold <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="action-btns flex flex-wrap gap-1 justify-center">
                                            <button onclick="openEditModal(<?php echo $product['id']; ?>,'<?php echo addslashes($product['serial_no']); ?>','<?php echo addslashes($product['name']); ?>','<?php echo addslashes($product['item_name'] ?? ''); ?>','<?php echo $product['category']; ?>','<?php echo addslashes($product['weight'] ?? ''); ?>',<?php echo $product['price']; ?>,<?php echo $product['quantity']; ?>)" class="btn-edit">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button onclick="openUpdateModal(<?php echo $product['id']; ?>,'<?php echo addslashes($product['name']); ?>')" class="btn-addstock">
                                                <i class="fas fa-plus"></i> Stock
                                            </button>
                                            <button onclick="openDeleteModal(<?php echo $product['id']; ?>,'<?php echo addslashes($product['name']); ?>')" class="btn-delete">
                                                <i class="fas fa-trash"></i> Del
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php if(mysqli_num_rows($products) == 0 && empty($search)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-10" style="color:#7a4e0a;">
                                        <i class="fas fa-gem text-3xl mb-3 block" style="color:#d68b16;opacity:0.4;"></i>
                                        No products found. Please add some products.
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer style="background:linear-gradient(0deg,#f5e6c8,#fdf6e3);border-top:2px solid #d68b16;padding:20px;margin-top:40px;text-align:center;">
        <p class="text-xs" style="color:#7a4e0a;">
            &copy; 2026 MAA GOURI JEWELLERS &nbsp;|&nbsp; CRAFTED WITH ELEGANCE &nbsp;|&nbsp;
            Developed by <a href="https://saamparktechnologyresearch.in/" target="_blank" style="text-decoration:underline;color:#800020;">STR</a>
        </p>
    </footer>
</div><!-- end .page-wrapper -->

<!-- ========== MODALS ========== -->

<!-- Edit Product Modal -->
<div id="editModal" class="modal-overlay">
    <div class="modal-content">
        <h3 class="text-xl font-bold gold-font"><i class="fas fa-edit mr-2" style="color:#d68b16;"></i> Edit Product</h3>
        <form method="POST">
            <input type="hidden" name="product_id" id="editProductId">

            <div class="mb-3">
                <label>🔢 Serial Number</label>
                <input type="text" name="serial_no" id="editProductSerial" required class="jewel-input">
            </div>
            <div class="mb-3">
                <label>💎 Product Name</label>
                <input type="text" name="name" id="editProductName" required class="jewel-input">
            </div>
            <div class="mb-3">
                <label>✨ Category</label>
                <select name="category" id="editProductCategory" required class="jewel-input" onchange="updateItemTypes('editProductCategory','editProductItemName','editCustomItem')">
                    <option value="">-- Select Category --</option>
                    <optgroup label="🥇 Gold">
                        <option value="Gold 22K">Gold 22K</option>
                        <option value="Gold 18K">Gold 18K</option>
                    </optgroup>
                    <optgroup label="🥈 Silver">
                        <option value="Silver">Silver</option>
                    </optgroup>
                    <optgroup label="💎 Stone">
                        <option value="Stone">Stone</option>
                    </optgroup>
                    <optgroup label="💎 Diamond">
                        <option value="Diamond">Diamond</option>
                    </optgroup>
                </select>
            </div>
            <div class="mb-3">
                <label>🏷️ Item Name</label>
                <select name="item_name" id="editProductItemName" required class="jewel-input" onchange="toggleCustomItem('editCustomItem', this.value)">
                    <option value="">-- Select Item --</option>
                </select>
                <div class="custom-item-input mt-2" id="editCustomItem">
                    <input type="text" name="item_name_custom" id="editItemCustomInput" placeholder="Custom item name..." class="jewel-input">
                </div>
            </div>
            <div class="mb-3">
                <label>⚖️ Weight (grams)</label>
                <input type="text" name="weight" id="editProductWeight" class="jewel-input">
            </div>
            <div class="mb-3">
                <label>💰 Price (₹)</label>
                <input type="number" step="0.01" name="price" id="editProductPrice" required class="jewel-input">
            </div>
            <div class="mb-4">
                <label>📦 Quantity</label>
                <input type="number" name="quantity" id="editProductQuantity" required class="jewel-input">
            </div>
            <div class="flex gap-3">
                <button type="submit" name="update_product" class="btn-jewel flex-1 text-center">💾 Update</button>
                <button type="button" onclick="closeEditModal()" class="flex-1 py-2 rounded-lg text-sm font-semibold" style="background:#e5e7eb;color:#374151;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Stock Modal -->
<div id="updateModal" class="modal-overlay">
    <div class="modal-content">
        <h3 class="text-xl font-bold gold-font"><i class="fas fa-plus-circle mr-2" style="color:#d68b16;"></i> Add Stock</h3>
        <form method="POST">
            <input type="hidden" name="product_id" id="updateProductId">
            <p class="mb-3 text-sm" style="color:#7a4e0a;">Product: <strong id="updateProductName" style="color:#800020;"></strong></p>
            <div class="mb-4">
                <label>➕ Add Quantity</label>
                <input type="number" name="quantity" required class="jewel-input" placeholder="Enter quantity to add">
            </div>
            <div class="flex gap-3">
                <button type="submit" name="update_quantity" class="btn-jewel flex-1 text-center">➕ Add Stock</button>
                <button type="button" onclick="closeUpdateModal()" class="flex-1 py-2 rounded-lg text-sm font-semibold" style="background:#e5e7eb;color:#374151;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-content text-center">
        <i class="fas fa-exclamation-triangle text-5xl mb-4" style="color:#ef4444;"></i>
        <h3 class="text-xl font-bold gold-font">⚠️ Delete Product</h3>
        <p class="my-3 text-sm" style="color:#4b5563;">Are you sure you want to delete <strong id="deleteProductName" style="color:#800020;"></strong>?</p>
        <p class="mb-4 text-xs" style="color:#d97706;">⚠️ Related invoice records may also be removed.</p>
        <form method="POST">
            <input type="hidden" name="product_id" id="deleteProductId">
            <div class="flex gap-3">
                <button type="submit" name="delete_product" class="flex-1 py-2 rounded-lg text-sm font-semibold text-white" style="background:linear-gradient(135deg,#ef4444,#dc2626);">🗑️ Yes, Delete</button>
                <button type="button" onclick="closeDeleteModal()" class="flex-1 py-2 rounded-lg text-sm font-semibold" style="background:#e5e7eb;color:#374151;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ========== SCRIPTS ========== -->
<script>
    /* ---------- Sidebar ---------- */
    function toggleSidebar() {
        const sidebar = document.getElementById('mainSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const burger  = document.getElementById('burgerMenu');
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
        burger.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    }

    function closeSidebar() {
        document.getElementById('mainSidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('active');
        const b = document.getElementById('burgerMenu');
        if(b) b.classList.remove('active');
        document.body.style.overflow = '';
    }

    /* ---------- Item types ---------- */
    const itemsByCategory = {
        'Gold 22K': ['Chur','Bala','Churi','Necklace','Chain','Jhumka','Jhumkolol','Tops','Ladies Ring','Gents Ring','Chokey','Breslet','Ladies Breslet','Tika','Takti','Mantasa','Loket','Mangal Sutra','Moti Chokey','Nosepin','Sankha','Pola','Baby Ring','Bali','Pitaring','Breslet Nova','Steu Nova','Other'],
        'Gold 18K': ['Chur','Bala','Churi','Necklace','Chain','Jhumka','Jhumkolol','Tops','Ladies Ring','Gents Ring','Chokey','Breslet','Ladies Breslet','Tika','Takti','Mantasa','Loket','Mangal Sutra','Baby Ring','Bali','Pitaring','Other'],
        'Silver':   ['Chur','Bala','Churi','Necklace','Chain','Jhumka','Tops','Ladies Ring','Gents Ring','Breslet','Tika','Loket','Mankha','Payal','Bichiya','Nosering','Baby Ring','Pat (Gross)','S- (Gross)','Nosepin (Gross)','Sankha','Pola','Other'],
        'Stone':    ['Natural Pearl','Gomed','Red Coral','Nila','Panna','Jerkon','Amethist','Cats Eye','Other'],
        'Diamond':  ['Ladies Ring','Gents Ring','Tops','Mangal Sutra','Nose pin','Necklace','Other'],
        'Other':    ['Chur','Bala','Churi','Necklace','Chain','Jhumka','Jhumkolol','Tops','Ladies Ring','Gents Ring','Chokey','Breslet','Ladies Breslet','Tika','Takti','Mantasa','Loket','Mangal Sutra','Moti Chokey','Nosepin','Sankha','Pola','Baby Ring','Bali','Pitaring','Breslet Nova','Steu Nova','Shankha','Pala','Mala','Moti Mala','Trasel','Branch Fram','Braslate Pala','parl Mala','Gala','Reparing','Stamp Charg','Other']
    };

    function updateItemTypes(catSelectId, itemSelectId, customDivId) {
        const cat = document.getElementById(catSelectId).value;
        const itemSel = document.getElementById(itemSelectId);
        const customDiv = document.getElementById(customDivId);

        itemSel.innerHTML = '';
        customDiv.classList.remove('show');
        const inp = customDiv.querySelector('input');
        if(inp) inp.value = '';

        if(!cat || !itemsByCategory[cat]) {
            itemSel.innerHTML = '<option value="">-- Select Category First --</option>';
            const addName = document.getElementById('addProductName');
            if(addName && catSelectId === 'addCategorySelect') addName.value = '';
            return;
        }

        itemSel.innerHTML = '<option value="">-- Select Item Type --</option>';

        const addName = document.getElementById('addProductName');
        if(addName && catSelectId === 'addCategorySelect') addName.value = cat;

        itemsByCategory[cat].forEach(function(item) {
            const opt = document.createElement('option');
            opt.value = item;
            opt.textContent = item === 'Other' ? '➕ Other (Custom)' : item;
            itemSel.appendChild(opt);
        });
    }

    function onAddProductNameChange() {
        const name = document.getElementById('addProductName').value.trim().toLowerCase();
        if(name === 'others' || name === 'other') {
            const categorySelect = document.getElementById('addCategorySelect');
            if(categorySelect) {
                categorySelect.value = 'Other';
                updateItemTypes('addCategorySelect','addItemSelect','addCustomItem');
            }
        }
    }

    function toggleCustomItem(divId, value) {
        const div = document.getElementById(divId);
        if(value === 'Other') { div.classList.add('show'); div.querySelector('input').focus(); }
        else { div.classList.remove('show'); div.querySelector('input').value = ''; }
    }

    /* ---------- Modals ---------- */
    function openEditModal(id, serial_no, name, item_name, category, weight, price, quantity) {
        document.getElementById('editProductId').value = id;
        document.getElementById('editProductSerial').value = serial_no;
        document.getElementById('editProductName').value = name;
        document.getElementById('editProductWeight').value = weight;
        document.getElementById('editProductPrice').value = price;
        document.getElementById('editProductQuantity').value = quantity;

        const catSel = document.getElementById('editProductCategory');
        catSel.value = category;
        updateItemTypes('editProductCategory', 'editProductItemName', 'editCustomItem');

        const itemSel = document.getElementById('editProductItemName');
        let matched = false;
        for(let opt of itemSel.options) { if(opt.value === item_name) { matched = true; break; } }
        if(matched) {
            itemSel.value = item_name;
            document.getElementById('editCustomItem').classList.remove('show');
        } else if(item_name) {
            itemSel.value = 'Other';
            document.getElementById('editCustomItem').classList.add('show');
            document.getElementById('editItemCustomInput').value = item_name;
        }

        document.getElementById('editModal').classList.add('flex');
    }

    function closeEditModal() { document.getElementById('editModal').classList.remove('flex'); }

    function openUpdateModal(id, name) {
        document.getElementById('updateProductId').value = id;
        document.getElementById('updateProductName').innerText = name;
        document.getElementById('updateModal').classList.add('flex');
    }

    function closeUpdateModal() { document.getElementById('updateModal').classList.remove('flex'); }

    function openDeleteModal(id, name) {
        document.getElementById('deleteProductId').value = id;
        document.getElementById('deleteProductName').innerText = name;
        document.getElementById('deleteModal').classList.add('flex');
    }

    function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('flex'); }

    /* Close modal on outside click */
    ['editModal','updateModal','deleteModal'].forEach(function(id) {
        document.getElementById(id).addEventListener('click', function(e) {
            if(e.target === this) this.classList.remove('flex');
        });
    });
</script>

<style>
@media (max-width: 768px) {
    nav.nav-gold { margin-left: 0 !important; }
}
</style>

</body>
</html>