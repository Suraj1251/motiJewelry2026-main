<?php
session_start();
require_once 'config/database.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Handle Add Customer
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_customer'])) {
    $name    = mysqli_real_escape_string($conn, trim($_POST['name']));
    $mobile  = mysqli_real_escape_string($conn, trim($_POST['mobile']));
    $email   = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
    $address = mysqli_real_escape_string($conn, trim($_POST['address'] ?? ''));

    $chk = mysqli_query($conn, "SELECT id FROM customers WHERE mobile = '$mobile'");
    if(mysqli_num_rows($chk) > 0) {
        echo "<script>alert('⚠️ Customer with this mobile number already exists!'); window.location.href='customers.php';<\/script>";
        exit();
    }

    $gst = mysqli_real_escape_string($conn, strtoupper(trim($_POST['gst'] ?? '')));

    $chk_gst = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'gst_number'");
    if(mysqli_num_rows($chk_gst) == 0) {
        mysqli_query($conn, "ALTER TABLE customers ADD COLUMN gst_number VARCHAR(20) DEFAULT '' AFTER address");
    }

    if(mysqli_query($conn, "INSERT INTO customers (name, mobile, email, address, gst_number, created_at) VALUES ('$name', '$mobile', '$email', '$address', '$gst', NOW())")) {
        echo "<script>alert('✅ Customer added successfully!'); window.location.href='customers.php';<\/script>";
        exit();
    } else {
        $error_msg = "Error adding customer: " . mysqli_error($conn);
    }
}

// Handle Update Customer
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_customer'])) {
    $id      = mysqli_real_escape_string($conn, $_POST['customer_id']);
    $name    = mysqli_real_escape_string($conn, $_POST['name']);
    $mobile  = mysqli_real_escape_string($conn, $_POST['mobile']);
    $email   = mysqli_real_escape_string($conn, $_POST['email']);
    $address = mysqli_real_escape_string($conn, $_POST['address'] ?? '');
    $gst     = mysqli_real_escape_string($conn, strtoupper(trim($_POST['gst'] ?? '')));

    if(mysqli_query($conn, "UPDATE customers SET name='$name', mobile='$mobile', email='$email', address='$address', gst_number='$gst' WHERE id=$id")) {
        echo "<script>alert('✅ Customer updated successfully!'); window.location.href='customers.php';</script>";
        exit();
    } else {
        $error_msg = "Error updating customer: " . mysqli_error($conn);
    }
}

// Handle Delete Customer
if(isset($_GET['delete_id'])) {
    $delete_id = mysqli_real_escape_string($conn, $_GET['delete_id']);
    $result = mysqli_query($conn, "SELECT mobile FROM customers WHERE id = $delete_id");
    if($result && mysqli_num_rows($result) > 0) {
        $customer = mysqli_fetch_assoc($result);
        mysqli_query($conn, "DELETE FROM invoices WHERE customer_mobile = '{$customer['mobile']}'");
    }
    mysqli_query($conn, "DELETE FROM customers WHERE id = $delete_id");
    echo "<script>alert('🗑️ Customer deleted successfully!'); window.location.href='customers.php';</script>";
    exit();
}

$filter      = isset($_GET['filter'])      ? $_GET['filter']      : 'all';
$search      = isset($_GET['search'])      ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : '';

$query = "SELECT c.* FROM customers c WHERE 1=1";
if($filter == 'last_month')    $query .= " AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
elseif($filter == 'this_month') $query .= " AND MONTH(c.created_at)=MONTH(CURRENT_DATE()) AND YEAR(c.created_at)=YEAR(CURRENT_DATE())";
elseif($filter == 'this_year')  $query .= " AND YEAR(c.created_at)=YEAR(CURRENT_DATE())";
if(!empty($search))      $query .= " AND (c.name LIKE '%$search%' OR c.mobile LIKE '%$search%' OR c.email LIKE '%$search%' OR c.id LIKE '%$search%')";
if(!empty($date_filter)) $query .= " AND DATE(c.created_at)='$date_filter'";
$query .= " ORDER BY c.created_at DESC";

$customers_result = mysqli_query($conn, $query);
$total_customers  = $customers_result ? mysqli_num_rows($customers_result) : 0;
$customers = [];
if($customers_result) while($row = mysqli_fetch_assoc($customers_result)) $customers[] = $row;

$error_message = isset($error_msg) ? $error_msg : '';
$logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Customers - Maa Gouri Jewellers</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');

        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3,.gold-font { font-family: 'Playfair Display', serif; }

        /* ========== SIDEBAR ========== */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: 240px; height: 100vh;
            background: linear-gradient(180deg, #7a4e0a 0%, #b5730e 40%, #d68b16 100%);
            z-index: 1000;
            display: flex; flex-direction: column;
            box-shadow: 4px 0 24px rgba(0,0,0,0.25);
            transition: transform 0.35s cubic-bezier(.4,0,.2,1);
            overflow-y: auto; overflow-x: hidden;
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }

        .sidebar-logo {
            padding: 22px 18px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.18);
            display: flex; align-items: center; gap: 12px; flex-shrink: 0;
        }
        .sidebar-logo img {
            width: 44px; height: 44px; object-fit: contain;
            border-radius: 50%; background: rgba(255,255,255,0.1); padding: 3px; flex-shrink: 0;
        }
        .sidebar-logo-text h2 {
            color: #fff; font-size: 13px; font-weight: 700; line-height: 1.3;
            font-family: 'Playfair Display', serif; letter-spacing: 0.5px;
        }
        .sidebar-logo-text p { color: rgba(255,255,255,0.65); font-size: 10px; margin-top: 1px; }

        .sidebar-nav { flex: 1; padding: 10px 0; }

        .sidebar-section-label {
            padding: 10px 20px 4px;
            color: rgba(255,255,255,0.45);
            font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase;
        }

        .sidebar-nav a {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 20px;
            color: rgba(255,255,255,0.85);
            text-decoration: none; font-size: 13px; font-weight: 500;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
            letter-spacing: 0.3px; position: relative;
        }
        .sidebar-nav a:hover {
            background: rgba(255,255,255,0.13); color: #fff;
            border-left-color: rgba(255,255,255,0.8); padding-left: 26px;
        }
        .sidebar-nav a.active {
            background: rgba(255,255,255,0.22); color: #fff;
            border-left-color: #fff; font-weight: 700;
        }
        .sidebar-nav a.active::after {
            content: ''; position: absolute; right: 0; top: 50%;
            transform: translateY(-50%); width: 4px; height: 60%;
            background: #fff; border-radius: 4px 0 0 4px;
        }
        .sidebar-nav a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; opacity: 0.9; }

        .sidebar-divider { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 16px; }

        .sidebar-user {
            padding: 14px 16px 18px;
            border-top: 1px solid rgba(255,255,255,0.18);
            background: rgba(0,0,0,0.12); flex-shrink: 0;
        }
        .sidebar-user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .sidebar-user-info i { color: rgba(255,255,255,0.9); font-size: 26px; flex-shrink: 0; }
        .sidebar-user-info .user-details p { color: #fff; font-size: 12px; font-weight: 600; line-height: 1.3; }
        .sidebar-user-info .user-details span { color: rgba(255,255,255,0.55); font-size: 10px; }

        .sidebar-logout {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 9px 14px;
            background: rgba(239,68,68,0.75); color: #fff;
            border-radius: 8px; font-size: 12px; font-weight: 600;
            text-decoration: none; transition: background 0.2s;
            border: 1px solid rgba(239,68,68,0.4);
        }
        .sidebar-logout:hover { background: #ef4444; color: #fff; }

        /* Sidebar overlay (mobile) */
        .sidebar-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); z-index: 999; backdrop-filter: blur(2px);
        }
        .sidebar-overlay.active { display: block; }

        /* ========== LAYOUT ========== */
        .page-wrapper { margin-left: 240px; min-height: 100vh; transition: margin-left 0.35s ease; }

        /* ========== NAVBAR ========== */
        nav.nav-gold { background: linear-gradient(135deg, #b5730e, #d68b16) !important; }

        /* ========== BURGER ========== */
        .burger-menu { width: 28px; height: 20px; position: relative; cursor: pointer; }
        .burger-menu span {
            display: block; position: absolute;
            height: 3px; width: 100%; background: #fff; border-radius: 3px; transition: all 0.3s ease;
        }
        .burger-menu span:nth-child(1) { top: 0; }
        .burger-menu span:nth-child(2) { top: 9px; }
        .burger-menu span:nth-child(3) { top: 18px; }
        .burger-menu.active span:nth-child(1) { top: 9px; transform: rotate(135deg); }
        .burger-menu.active span:nth-child(2) { opacity: 0; left: -20px; }
        .burger-menu.active span:nth-child(3) { top: 9px; transform: rotate(-135deg); }

        /* ========== MOBILE ========== */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .page-wrapper { margin-left: 0 !important; }
            .mobile-burger { display: block !important; }
            nav.nav-gold { margin-left: 0 !important; }
        }
        @media (min-width: 769px) { .mobile-burger { display: none !important; } }

        /* ========== PAGE STYLES ========== */
        body { background: #F5F5F5; margin: 0; padding: 0; }

        .page-heading {
            background: linear-gradient(135deg, #fdf6e3, #f5ead0);
            border-bottom: 2px solid rgba(181,115,14,0.2);
            padding: 20px 28px;
        }
        .page-heading h1 { color: #800020; font-size: 1.6rem; }
        .page-heading p  { color: #7a4e0a; font-size: 13px; margin-top: 2px; }

        .jewel-card {
            background: #fff;
            border: 1px solid rgba(181,115,14,0.2);
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(181,115,14,0.08);
        }

        .jewel-input {
            background: #fdf6e3;
            border: 1px solid rgba(181,115,14,0.3);
            color: #4a3000;
            border-radius: 10px;
            padding: 8px 12px;
            transition: all 0.3s ease;
            width: 100%;
            font-size: 13px;
        }
        .jewel-input:focus {
            border-color: #d68b16;
            box-shadow: 0 0 0 3px rgba(214,139,22,0.15);
            outline: none;
        }
        .jewel-input::placeholder { color: rgba(122,78,10,0.4); }

        /* Table */
        .jewel-table { width: 100%; border-collapse: collapse; }
        .jewel-table th {
            background: linear-gradient(135deg, #7a4e0a, #d68b16);
            color: #fff; font-weight: 600;
            padding: 12px 10px; font-size: 12px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .jewel-table td {
            border-bottom: 1px solid rgba(181,115,14,0.1);
            padding: 10px 10px; color: #3a2800; font-size: 13px;
        }
        .jewel-table tbody tr:hover { background: #fdf6e3; }

        /* Buttons */
        .btn-jewel {
            background: linear-gradient(135deg, #800020, #d68b16);
            border: none; border-radius: 50px;
            padding: 9px 22px; font-weight: 700; color: #fff;
            transition: all 0.3s ease; display: inline-flex; align-items: center;
            gap: 6px; text-decoration: none; font-size: 13px; cursor: pointer;
        }
        .btn-jewel:hover { transform: scale(1.04); box-shadow: 0 8px 24px rgba(214,139,22,0.35); color: #fff; }

        .btn-filter {
            padding: 7px 16px; border-radius: 8px; font-size: 12px; font-weight: 600;
            cursor: pointer; text-decoration: none; transition: all 0.2s;
            border: 1.5px solid rgba(181,115,14,0.4); color: #7a4e0a; background: #fdf6e3;
        }
        .btn-filter:hover, .btn-filter.active {
            background: linear-gradient(135deg, #800020, #d68b16);
            color: #fff; border-color: transparent;
        }

        .btn-edit {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff; border: none; border-radius: 8px;
            padding: 5px 12px; font-size: 11px; cursor: pointer; transition: all 0.2s;
        }
        .btn-edit:hover { transform: scale(1.04); box-shadow: 0 4px 12px rgba(59,130,246,0.4); }

        .btn-delete {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #fff; border: none; border-radius: 8px;
            padding: 5px 12px; font-size: 11px; cursor: pointer; transition: all 0.2s;
            text-decoration: none; display: inline-block;
        }
        .btn-delete:hover { transform: scale(1.04); box-shadow: 0 4px 12px rgba(239,68,68,0.4); color: #fff; }

        /* Modal */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.55);
            z-index: 2000; display: none;
            align-items: center; justify-content: center; padding: 16px;
        }
        .modal-overlay.flex { display: flex; }
        .modal-content {
            background: linear-gradient(145deg, #fdf6e3, #fff);
            border: 1px solid rgba(181,115,14,0.35);
            border-radius: 20px; padding: 28px;
            width: 100%; max-width: 460px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .modal-content h3 { color: #800020; margin-bottom: 18px; }
        .modal-content label { color: #7a4e0a; display: block; margin-bottom: 4px; font-size: 12px; font-weight: 600; }

        /* Footer */
        .footer-jewel {
            background: linear-gradient(0deg, #f5e6c8, #fdf6e3);
            border-top: 2px solid #d68b16;
            padding: 20px; margin-top: 40px; text-align: center;
        }

        @media (max-width: 640px) {
            .filter-wrap { flex-direction: column; }
            .table-wrap { overflow-x: auto; }
            .jewel-table { min-width: 640px; }
        }
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

    <!-- Scanlines texture -->
    <div style="position:absolute;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 3px,rgba(214,139,22,0.015) 3px,rgba(214,139,22,0.015) 4px);pointer-events:none;z-index:1;"></div>

    <!-- Corner ornaments -->
    <div style="position:absolute;top:28px;left:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite;">✦</div>
    <div style="position:absolute;top:28px;right:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 1s;">✦</div>
    <div style="position:absolute;bottom:28px;left:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 2s;">✦</div>
    <div style="position:absolute;bottom:28px;right:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 3s;">✦</div>

    <!-- Stars / sparkles container -->
    <div id="loaderStars" style="position:absolute;inset:0;pointer-events:none;z-index:2;"></div>

    <!-- Expanding rings container -->
    <div id="loaderRings" style="position:absolute;inset:0;pointer-events:none;z-index:2;display:flex;align-items:center;justify-content:center;"></div>

    <!-- Center content -->
    <div style="position:relative;z-index:10;text-align:center;">

        <!-- Gem with halos -->
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

        <!-- Title -->
        <div style="color:#d68b16;font-size:22px;letter-spacing:6px;font-family:'Playfair Display',serif;margin-bottom:6px;animation:titleGold 2s ease infinite alternate;">MAA GOURI JEWELLERS</div>
        <p style="color:rgba(201,169,110,0.7);font-size:10px;letter-spacing:4px;text-transform:uppercase;margin-bottom:24px;">Crafting Timeless Elegance</p>

        <!-- Progress bar -->
        <div style="width:200px;height:3px;background:rgba(255,255,255,0.08);border-radius:3px;margin:0 auto 16px;overflow:hidden;">
            <div style="height:100%;width:35%;background:linear-gradient(90deg,#7a4e0a,#d68b16,#f5c842);border-radius:3px;animation:barSlide 1.8s ease-in-out infinite;"></div>
        </div>

        <!-- Dots -->
        <div style="display:flex;gap:9px;justify-content:center;">
            <div style="width:6px;height:6px;border-radius:50%;background:#d68b16;animation:dotBounce 1.2s ease-in-out infinite;"></div>
            <div style="width:6px;height:6px;border-radius:50%;background:#d68b16;animation:dotBounce 1.2s ease-in-out infinite 0.2s;"></div>
            <div style="width:6px;height:6px;border-radius:50%;background:#d68b16;animation:dotBounce 1.2s ease-in-out infinite 0.4s;"></div>
        </div>
    </div>

    <style>
        @keyframes ornFloat { 0%,100%{transform:rotate(0deg) scale(1);opacity:0.15} 50%{transform:rotate(20deg) scale(1.15);opacity:0.28} }
        @keyframes haloPulse { 0%,100%{opacity:0.3;transform:scale(1)} 50%{opacity:1;transform:scale(1.1)} }
        @keyframes gemGlowPulse { 0%,100%{filter:drop-shadow(0 0 8px #d68b16)} 50%{filter:drop-shadow(0 0 22px #ff9900)} }
        @keyframes titleGold { from{color:#d68b16} to{color:#f5c842} }
        @keyframes barSlide { 0%{transform:translateX(-100%)} 100%{transform:translateX(480%)} }
        @keyframes dotBounce { 0%,100%{opacity:0.3;transform:scale(0.7)} 50%{opacity:1;transform:scale(1.2)} }
        @keyframes starFade { 0%{opacity:0;transform:scale(0)} 50%{opacity:1} 100%{opacity:0;transform:scale(1)} }
        @keyframes ringExpand { 0%{opacity:0.7;transform:scale(0.2)} 100%{opacity:0;transform:scale(2)} }
    </style>

</div>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ========== SIDEBAR ========== -->
<div class="sidebar" id="mainSidebar">
    <div class="sidebar-logo">
        <?php
        $logo_found = false;
        foreach($logo_paths as $path) {
            if(file_exists($path)) { echo '<img src="'.$path.'" alt="Logo">'; $logo_found = true; break; }
        }
        if(!$logo_found) echo '<i class="fas fa-gem" style="color:#fff;font-size:30px;flex-shrink:0;"></i>';
        ?>
        <div class="sidebar-logo-text">
            <h2>MAA GOURI JEWELLERS</h2>
            <p>Premium Since 2026</p>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="sidebar-section-label">Main Menu</div>
        <a href="index.php"><i class="fas fa-home"></i> HOME</a>
        <a href="billing.php"><i class="fas fa-receipt"></i> BILLING</a>
        <a href="stock.php"><i class="fas fa-boxes"></i> STOCK</a>
        <a href="customers.php" class="active"><i class="fas fa-users"></i> CUSTOMERS</a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Analytics</div>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> REPORTS</a>
        <a href="income_expenses.php"><i class="fas fa-chart-line"></i> INCOME &amp; EXP</a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Tools</div>
        <a href="whatsapp_automation.php"><i class="fab fa-whatsapp"></i> WHATSAPP</a>
        <a href="sbook.php"><i class="fas fa-book"></i> karigori</a>
    </nav>

    <div class="sidebar-user">
        <div class="sidebar-user-info">
            <i class="fas fa-user-circle"></i>
            <div class="user-details">
                <p><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                <span><?php echo htmlspecialchars($_SESSION['user_mobile'] ?? 'Admin'); ?></span>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout"><i class="fas fa-sign-out-alt"></i> LOGOUT</a>
    </div>
</div>
<!-- ========== END SIDEBAR ========== -->

<!-- ========== NAVBAR ========== -->
<nav class="nav-gold shadow-lg sticky top-0 z-50" style="margin-left:240px;">
    <div class="container mx-auto px-4 sm:px-6 py-3 sm:py-4">
        <div class="flex justify-between items-center">
            <div class="ml-auto flex items-center gap-4">
                <span class="text-sm font-medium text-white hidden sm:inline">
                    <i class="fas fa-user mr-1"></i>
                    <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                </span>
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

    <div class="page-heading">
        <h1 class="gold-font"><i class="fas fa-users mr-2"></i> Customer Management</h1>
        <p>View and manage all your jewellery customers</p>
    </div>

    <div class="container mx-auto px-4 sm:px-6 py-6">

        <?php if(!empty($error_message)): ?>
            <div class="mb-4 px-4 py-3 rounded-lg text-sm" style="background:#FEF2F2;border:1px solid #EF4444;color:#991B1B;">
                <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Filter Bar -->
        <div class="jewel-card p-4 mb-5">
            <div class="filter-wrap flex flex-col md:flex-row justify-between items-center gap-4">
                <!-- Filter buttons -->
                <div class="flex flex-wrap gap-2">
                    <a href="?filter=all"        class="btn-filter <?php echo $filter=='all'        ? 'active' : ''; ?>">👑 All Customers</a>
                    <a href="?filter=this_month" class="btn-filter <?php echo $filter=='this_month' ? 'active' : ''; ?>">📅 This Month</a>
                    <a href="?filter=this_year"  class="btn-filter <?php echo $filter=='this_year'  ? 'active' : ''; ?>">🎯 This Year</a>
                </div>

                <!-- Search -->
                <form method="GET" class="flex flex-wrap gap-2">
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <input type="date" name="date_filter" value="<?php echo htmlspecialchars($date_filter); ?>" class="jewel-input" style="width:auto;">
                    <input type="text" name="search" placeholder="Search name / mobile / ID…" value="<?php echo htmlspecialchars($search); ?>" class="jewel-input" style="min-width:180px;flex:1;">
                    <button type="submit" class="btn-jewel" style="padding:8px 18px;font-size:12px;border-radius:10px;">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <?php if(!empty($search) || !empty($date_filter) || $filter != 'all'): ?>
                        <a href="customers.php" class="btn-jewel" style="padding:8px 18px;font-size:12px;border-radius:10px;background:linear-gradient(135deg,#6b7280,#4b5563);">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Add Customer Button -->
        <div class="flex justify-end mb-4">
            <button onclick="openAddModal()" class="btn-jewel">
                <i class="fas fa-user-plus"></i> Add Customer
            </button>
        </div>

        <!-- Customers Table -->
        <div class="jewel-card p-5">
            <h2 class="gold-font text-xl font-bold mb-4" style="color:#800020;">
                <i class="fas fa-user-friends mr-2" style="color:#d68b16;"></i>
                Royal Customers
                <span class="text-sm font-normal ml-2" style="color:#7a4e0a;">(Total: <?php echo $total_customers; ?>)</span>
            </h2>

            <div class="table-wrap overflow-x-auto rounded-xl" style="border:1px solid rgba(181,115,14,0.15);">
                <table class="jewel-table">
                    <thead>
                        <tr>
                            <th class="text-left">ID</th>
                            <th class="text-left">Customer Name</th>
                            <th class="text-left">Mobile</th>
                            <th class="text-left hidden sm:table-cell">Email</th>
                            <th class="text-left">Registered</th>
                            <th class="text-center">Purchases</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($total_customers > 0): foreach($customers as $customer):
                            $mob = $customer['mobile'];
                            $ocr = mysqli_query($conn, "SELECT COUNT(*) as c FROM invoices WHERE customer_mobile='$mob'");
                            $order_count = $ocr ? mysqli_fetch_assoc($ocr)['c'] : 0;
                            $tcr = mysqli_query($conn, "SELECT SUM(total_amount) as t FROM invoices WHERE customer_mobile='$mob'");
                            $total_amt = $tcr ? (mysqli_fetch_assoc($tcr)['t'] ?? 0) : 0;
                            $lcr = mysqli_query($conn, "SELECT MAX(created_at) as l FROM invoices WHERE customer_mobile='$mob'");
                            $last_order = $lcr ? (mysqli_fetch_assoc($lcr)['l'] ?? null) : null;
                        ?>
                        <tr>
                            <td class="text-sm font-semibold" style="color:#d68b16;">#<?php echo $customer['id']; ?></td>
                            <td>
                                <div class="font-semibold" style="color:#800020;">💎 <?php echo htmlspecialchars($customer['name']); ?></div>
                                <?php if(!empty($customer['gst_number'])): ?>
                                    <div class="text-xs mt-0.5" style="color:#6d28d9;">🏛️ GST: <?php echo htmlspecialchars($customer['gst_number']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-sm" style="color:#374151;">📱 <?php echo htmlspecialchars($mob); ?></td>
                            <td class="text-sm hidden sm:table-cell" style="color:#6b7280;"><?php echo htmlspecialchars($customer['email'] ?? 'N/A'); ?></td>
                            <td class="text-sm" style="color:#374151;">📅 <?php echo date('d M Y', strtotime($customer['created_at'])); ?></td>
                            <td class="text-center">
                                <div class="font-bold text-sm" style="color:#800020;"><?php echo $order_count; ?> orders</div>
                                <div class="text-xs" style="color:#059669;">₹<?php echo number_format($total_amt, 2); ?></div>
                                <?php if($last_order): ?>
                                    <div class="text-xs" style="color:#9ca3af;">Last: <?php echo date('d M Y', strtotime($last_order)); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="flex gap-1 justify-center flex-wrap">
                                    <button onclick='openEditModal(<?php echo htmlspecialchars(json_encode($customer), ENT_QUOTES, "UTF-8"); ?>)' class="btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <a href="?delete_id=<?php echo $customer['id']; ?>" onclick="return confirm('⚠️ Delete this customer?')" class="btn-delete">
                                        <i class="fas fa-trash"></i> Del
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-10" style="color:#7a4e0a;">
                                <i class="fas fa-users text-3xl mb-3 block" style="color:#d68b16;opacity:0.4;"></i>
                                No customers found.
                                <?php echo !empty($search) ? 'Try different search criteria.' : 'Add customers from the billing page.'; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer-jewel">
        <p class="text-xs" style="color:#7a4e0a;">
            &copy; 2026 MAA GOURI JEWELLERS &nbsp;|&nbsp; CRAFTED WITH ELEGANCE &nbsp;|&nbsp;
            Developed by <a href="https://saamparktechnologyresearch.in/" target="_blank" style="text-decoration:underline;color:#800020;">STR</a>
        </p>
    </footer>
</div><!-- end .page-wrapper -->

<!-- ========== ADD CUSTOMER MODAL ========== -->
<div id="addModal" class="modal-overlay">
    <div class="modal-content">
        <h3 class="text-xl font-bold gold-font"><i class="fas fa-user-plus mr-2" style="color:#d68b16;"></i> Add New Customer</h3>
        <form method="POST">
            <div class="mb-3">
                <label>👑 Full Name <span style="color:#ef4444;">*</span></label>
                <input type="text" name="name" required placeholder="Customer name…" class="jewel-input">
            </div>
            <div class="mb-3">
                <label>📱 Mobile Number <span style="color:#ef4444;">*</span></label>
                <input type="tel" name="mobile" required placeholder="10-digit mobile…" class="jewel-input">
            </div>
            <div class="mb-3">
                <label>📧 Email Address</label>
                <input type="email" name="email" placeholder="email@example.com" class="jewel-input">
            </div>
            <div class="mb-3">
                <label>📍 Address</label>
                <textarea name="address" rows="2" placeholder="Customer address…" class="jewel-input"></textarea>
            </div>
            <div class="mb-4">
                <label>🏛️ GST Number <span style="color:#9ca3af;font-weight:400;">(Optional)</span></label>
                <input type="text" name="gst" placeholder="e.g. 22AAAAA0000A1Z5" maxlength="15" class="jewel-input" oninput="this.value=this.value.toUpperCase()">
                <p class="text-xs mt-1" style="color:#9ca3af;">15-character GST Identification Number</p>
            </div>
            <div class="flex gap-3">
                <button type="submit" name="add_customer" class="btn-jewel flex-1 justify-center">
                    <i class="fas fa-user-plus"></i> Add Customer
                </button>
                <button type="button" onclick="closeAddModal()" class="flex-1 py-2 rounded-lg text-sm font-semibold" style="background:#e5e7eb;color:#374151;">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========== EDIT CUSTOMER MODAL ========== -->
<div id="editModal" class="modal-overlay">
    <div class="modal-content">
        <h3 class="text-xl font-bold gold-font"><i class="fas fa-edit mr-2" style="color:#d68b16;"></i> Edit Customer</h3>
        <form method="POST">
            <input type="hidden" name="customer_id" id="editCustomerId">
            <div class="mb-3">
                <label>👑 Full Name <span style="color:#ef4444;">*</span></label>
                <input type="text" name="name" id="editName" required class="jewel-input">
            </div>
            <div class="mb-3">
                <label>📱 Mobile Number <span style="color:#ef4444;">*</span></label>
                <input type="tel" name="mobile" id="editMobile" required class="jewel-input">
            </div>
            <div class="mb-3">
                <label>📧 Email Address</label>
                <input type="email" name="email" id="editEmail" class="jewel-input">
            </div>
            <div class="mb-3">
                <label>📍 Address</label>
                <textarea name="address" id="editAddress" rows="2" class="jewel-input"></textarea>
            </div>
            <div class="mb-4">
                <label>🏛️ GST Number <span style="color:#9ca3af;font-weight:400;">(Optional)</span></label>
                <input type="text" name="gst" id="editGst" placeholder="e.g. 22AAAAA0000A1Z5" maxlength="15" class="jewel-input" oninput="this.value=this.value.toUpperCase()">
            </div>
            <div class="flex gap-3">
                <button type="submit" name="update_customer" class="btn-jewel flex-1 justify-center">
                    <i class="fas fa-save"></i> Update Customer
                </button>
                <button type="button" onclick="closeEditModal()" class="flex-1 py-2 rounded-lg text-sm font-semibold" style="background:#e5e7eb;color:#374151;">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<style>
@media (max-width: 768px) { nav.nav-gold { margin-left: 0 !important; } }
</style>

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

    /* ---------- Add Modal ---------- */
    function openAddModal() {
        document.getElementById('addModal').classList.add('flex');
        document.body.style.overflow = 'hidden';
    }
    function closeAddModal() {
        document.getElementById('addModal').classList.remove('flex');
        document.body.style.overflow = '';
    }

    /* ---------- Edit Modal ---------- */
    function openEditModal(c) {
        document.getElementById('editCustomerId').value = c.id;
        document.getElementById('editName').value        = c.name;
        document.getElementById('editMobile').value      = c.mobile;
        document.getElementById('editEmail').value       = c.email   || '';
        document.getElementById('editAddress').value     = c.address || '';
        document.getElementById('editGst').value         = c.gst_number || '';
        document.getElementById('editModal').classList.add('flex');
        document.body.style.overflow = 'hidden';
    }
    function closeEditModal() {
        document.getElementById('editModal').classList.remove('flex');
        document.body.style.overflow = '';
    }

    /* Outside-click & ESC close */
    ['addModal','editModal'].forEach(function(id) {
        document.getElementById(id).addEventListener('click', function(e) {
            if(e.target === this) {
                this.classList.remove('flex');
                document.body.style.overflow = '';
            }
        });
    });
    document.addEventListener('keydown', function(e) {
        if(e.key === 'Escape') { closeAddModal(); closeEditModal(); }
    });
</script>
</body>
</html>