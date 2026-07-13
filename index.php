<?php
session_start();
require_once 'config/database.php';

$is_logged_in = isset($_SESSION['user_id']);
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

$daily_sales = [];
$top_products = [];
if($is_logged_in) {
    for($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $sales_query = mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0) as total FROM invoices WHERE DATE(created_at) = '$date'");
        $sales = mysqli_fetch_assoc($sales_query);
        $daily_sales[] = [
            'date' => date('d M', strtotime($date)),
            'total' => $sales['total'] ?? 0
        ];
    }

    $top_products_result = mysqli_query($conn, "SELECT p.name, SUM(ii.quantity) as sold FROM invoice_items ii JOIN products p ON ii.product_id = p.id GROUP BY ii.product_id ORDER BY sold DESC LIMIT 5");
    while($row = mysqli_fetch_assoc($top_products_result)) {
        $top_products[] = $row;
    }

    $monthly_sales = [];
    $daily_invoice_counts = [];
    for($m = 5; $m >= 0; $m--) {
        $month = date('Y-m', strtotime("-$m months"));
        $month_label = date('M', strtotime($month . '-01'));
        $sales_query = mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0) as total FROM invoices WHERE DATE_FORMAT(created_at, '%Y-%m') = '$month'");
        $sales_row = mysqli_fetch_assoc($sales_query);
        $monthly_sales[] = ['month' => $month_label, 'total' => $sales_row['total'] ?? 0];
    }

    for($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $customer_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM customers WHERE DATE(created_at) = '$date'");
        $customer_row = mysqli_fetch_assoc($customer_query);
        $customer_growth[] = ['date' => date('d M', strtotime($date)), 'total' => $customer_row['total'] ?? 0];

        $invoice_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM invoices WHERE DATE(created_at) = '$date'");
        $invoice_count_row = mysqli_fetch_assoc($invoice_count_query);
        $daily_invoice_counts[] = ['date' => date('d M', strtotime($date)), 'total' => $invoice_count_row['total'] ?? 0];
    }

    $invoice_pending_count_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM invoices WHERE balance_amount > 0"));
    $invoice_completed_count_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM invoices WHERE COALESCE(balance_amount, 0) = 0"));
    $invoice_pending_count = $invoice_pending_count_row['total'] ?? 0;
    $invoice_completed_count = $invoice_completed_count_row['total'] ?? 0;

    $category_sales = [];
    $category_sales_result = mysqli_query($conn, "SELECT COALESCE(p.category, 'Other') as category, COALESCE(SUM(ii.total), 0) as revenue FROM invoice_items ii JOIN products p ON ii.product_id = p.id GROUP BY p.category ORDER BY revenue DESC LIMIT 6");
    while($row = mysqli_fetch_assoc($category_sales_result)) {
        $category_sales[] = $row;
    }

    $category_stock = [];
    $category_stock_result = mysqli_query($conn, "SELECT COALESCE(category, 'Other') as category, COALESCE(SUM(quantity), 0) as total_qty FROM products GROUP BY category ORDER BY total_qty DESC LIMIT 6");
    while($row = mysqli_fetch_assoc($category_stock_result)) {
        $category_stock[] = $row;
    }

    $low_stock_items = [];
    $low_stock_result = mysqli_query($conn, "SELECT name, quantity FROM products WHERE quantity <= 5 ORDER BY quantity ASC, name ASC LIMIT 5");
    while($row = mysqli_fetch_assoc($low_stock_result)) {
        $low_stock_items[] = $row;
    }

    $pending_invoices = [];
    $pending_invoices_result = mysqli_query($conn, "SELECT invoice_no, customer_name, balance_amount FROM invoices WHERE balance_amount > 0 ORDER BY created_at DESC LIMIT 5");
    while($row = mysqli_fetch_assoc($pending_invoices_result)) {
        $pending_invoices[] = $row;
    }

    $current_month = date('Y-m');
    $monthly_income_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM income WHERE DATE_FORMAT(income_date, '%Y-%m') = '$current_month'"));
    $monthly_expense_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = '$current_month'"));
    $stock_items_count_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM products"));
    $stock_quantity_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(quantity), 0) as total FROM products"));
    $stock_value_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(price * quantity), 0) as total FROM products"));
    $customers_count_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM customers"));
    $total_income_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM income"));
    $total_expense_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) as total FROM expenses"));
    $total_due_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(balance_amount), 0) as total FROM invoices WHERE balance_amount > 0"));

    $monthly_income = $monthly_income_row['total'] ?? 0;
    $monthly_expense = $monthly_expense_row['total'] ?? 0;
    $stock_items_count = $stock_items_count_row['total'] ?? 0;
    $stock_quantity = $stock_quantity_row['total'] ?? 0;
    $stock_value = $stock_value_row['total'] ?? 0;
    $customers_count = $customers_count_row['total'] ?? 0;
    $total_income = $total_income_row['total'] ?? 0;
    $total_expense = $total_expense_row['total'] ?? 0;
    $total_due = $total_due_row['total'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="author" content="MANU GUPTA">
    <meta name="description" content="Gouri Jewellers - Premium Jewellery Management System">
    <title>Gouri Jewellers - Premium Jewellery Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');

        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3,.gold-font { font-family: 'Playfair Display', serif; }

        /* ========== TOP NAVBAR (fixed) ========== */
        nav.nav-gold {
            background: linear-gradient(135deg, #b5730e, #d68b16) !important;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            width: 100%;
        }

        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; backdrop-filter: blur(2px); }
        .sidebar-overlay.active { display: block; }
        .sidebar {
            position: fixed; top: 0; left: 0; width: 240px; height: 100vh;
            background: linear-gradient(180deg, #7a4e0a 0%, #b5730e 40%, #d68b16 100%);
            z-index: 1000; display: flex; flex-direction: column;
            box-shadow: 4px 0 24px rgba(0,0,0,0.25);
            transition: transform 0.35s cubic-bezier(.4,0,.2,1);
            overflow-y: auto; overflow-x: hidden;
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }
        .sidebar-logo { padding: 22px 18px 16px; border-bottom: 1px solid rgba(255,255,255,0.18); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .sidebar-logo img { width: 44px; height: 44px; object-fit: contain; border-radius: 50%; background: rgba(255,255,255,0.1); padding: 3px; }
        .sidebar-logo-text h2 { color: #fff; font-size: 13px; font-weight: 700; line-height: 1.3; font-family: 'Playfair Display', serif; }
        .sidebar-logo-text p { color: rgba(255,255,255,0.65); font-size: 10px; margin-top: 1px; }
        .sidebar-nav { flex: 1; padding: 10px 0; }
        .sidebar-section-label { padding: 10px 20px 4px; color: rgba(255,255,255,0.45); font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 11px 20px; color: rgba(255,255,255,0.85); text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s ease; border-left: 3px solid transparent; position: relative; }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.13); color: #fff; border-left-color: rgba(255,255,255,0.8); padding-left: 26px; }
        .sidebar-nav a.active { background: rgba(255,255,255,0.22); color: #fff; border-left-color: #fff; font-weight: 700; }
        .sidebar-nav a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }
        .sidebar-divider { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 16px; }
        .sidebar-user { padding: 14px 16px 18px; border-top: 1px solid rgba(255,255,255,0.18); background: rgba(0,0,0,0.12); flex-shrink: 0; }
        .sidebar-user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .sidebar-user-info i { color: rgba(255,255,255,0.9); font-size: 26px; }
        .sidebar-user-info .user-details p { color: #fff; font-size: 12px; font-weight: 600; }
        .sidebar-user-info .user-details span { color: rgba(255,255,255,0.55); font-size: 10px; }
        .sidebar-logout { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; background: rgba(239,68,68,0.75); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; transition: background 0.2s; }
        .sidebar-logout:hover { background: #ef4444; color: #fff; }
        .page-wrapper { transition: margin-left 0.35s ease; }
        .page-wrapper.logged-in-page { margin-left: 240px; min-height: 100vh; }
        .mobile-burger { display: none; width: 28px; height: 20px; position: relative; cursor: pointer; }
        .mobile-burger span { display: block; position: absolute; height: 3px; width: 100%; background: #fff; border-radius: 3px; transition: all 0.3s ease; }
        .mobile-burger span:nth-child(1) { top: 0; }
        .mobile-burger span:nth-child(2) { top: 9px; }
        .mobile-burger span:nth-child(3) { top: 18px; }
        .mobile-burger.active span:nth-child(1) { top: 9px; transform: rotate(135deg); }
        .mobile-burger.active span:nth-child(2) { opacity: 0; left: -20px; }
        .mobile-burger.active span:nth-child(3) { top: 9px; transform: rotate(-135deg); }

        #navSpacer { width: 100%; min-height: 72px; }
        @media (max-width: 480px) { #navSpacer { min-height: 64px; } }

        nav.nav-gold h1,
        nav.nav-gold p,
        nav.nav-gold span { color: #ffffff !important; }

        /* ========== NAVBAR LOGIN / USER ========== */
        .nav-login-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 20px;
            background: rgba(255,255,255,0.18);
            color: #fff;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid rgba(255,255,255,0.35);
            transition: all 0.2s ease;
            letter-spacing: 0.4px;
        }

        .nav-login-btn:hover {
            background: rgba(255,255,255,0.3);
            color: #fff;
        }

        .nav-user-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-logout-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 7px 16px;
            background: rgba(239,68,68,0.85);
            color: #fff;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid rgba(239,68,68,0.4);
            transition: background 0.2s;
        }

        .nav-logout-btn:hover { background: #ef4444; color: #fff; }

        /* ========== JEWEL SPARKLES ========== */
        .jewel-sparkle {
            position: fixed;
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
            animation: sparkleFloat linear infinite;
        }

        @keyframes sparkleFloat {
            0% { transform: translateY(100vh) scale(0); opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 0.5; }
            100% { transform: translateY(-10vh) scale(1); opacity: 0; }
        }

        /* ========== FLOATING LOGO ========== */
        .floating-logo {
            display: flex !important;
            justify-content: center !important;
            align-items: center !important;
            width: 100% !important;
            text-align: center !important;
            margin: 0 auto 20px auto !important;
            animation: logoFloat 3s ease-in-out infinite;
        }

        @keyframes logoFloat {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
        }

        .floating-logo img {
            width: 160px !important;
            height: 160px !important;
            object-fit: contain;
            display: block;
            margin: 0 auto !important;
            filter: drop-shadow(0 8px 24px rgba(181,115,14,0.4));
        }

        /* ========== HERO ========== */
        .hero-with-logo { text-align: center; }
        .dashboard-section { background: #fff; }
        .dashboard-chart, .top-products-card { background: #fff; border: 1px solid rgba(181,115,14,0.15); border-radius: 20px; box-shadow: 0 16px 35px rgba(0,0,0,0.06); }
        .dashboard-chart { padding: 24px; }
        .top-products-card { padding: 24px; }
        .dashboard-title { font-size: 1.1rem; font-weight: 700; color: #7a4e0a; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.75rem; }
        .top-products-card .top-product-item { padding: 14px 0; border-bottom: 1px solid rgba(181,115,14,0.12); }
        .top-products-card .top-product-item:last-child { border-bottom: none; }

        .typing-text {
            background: linear-gradient(135deg, #800020, #c9a96e, #d68b16);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-family: 'Playfair Display', serif;
        }

        .cursor {
            display: inline-block;
            width: 3px;
            height: 1em;
            background: #d68b16;
            margin-left: 4px;
            vertical-align: middle;
            animation: blink 0.8s infinite;
        }

        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }

        /* ========== STAT CARDS ========== */
        .stat-gem {
            background: linear-gradient(145deg, #fdf6e3, #f5ead0);
            border-radius: 16px;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid rgba(181,115,14,0.2);
        }

        .stat-gem:hover {
            transform: translateY(-8px);
            border-color: #d68b16;
            box-shadow: 0 15px 30px rgba(181,115,14,0.15);
        }

        .stat-gem i {
            font-size: 32px;
            background: linear-gradient(135deg, #800020, #d68b16);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-gem h3 { font-size: 1.8rem; font-weight: 700; color: #800020; }
        .stat-gem p { color: #7a4e0a; font-size: 11px; }

        /* ========== GEM CARDS ========== */
        .gem-card {
            background: linear-gradient(145deg, #fdf6e3, #f5ead0);
            border-radius: 20px;
            border: 1px solid rgba(181,115,14,0.15);
            transition: all 0.4s ease;
            cursor: pointer;
        }

        .gem-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(181,115,14,0.2);
            border-color: #d68b16;
        }

        .gem-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #800020, #d68b16, #7a4e0a);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: gemGlow 2.5s ease-in-out infinite;
        }

        @keyframes gemGlow {
            0%,100% { box-shadow: 0 0 10px rgba(214,139,22,0.4), 0 0 20px rgba(128,0,32,0.2); }
            50% { box-shadow: 0 0 25px rgba(214,139,22,0.7), 0 0 40px rgba(128,0,32,0.3); transform: scale(1.05); }
        }

        .gem-icon i { font-size: 28px; color: #fff; }
        .gem-card h3 { color: #800020; }
        .gem-card p { color: #6b5a3e; }

        /* ========== BUTTONS ========== */
        .btn-jewel {
            background: linear-gradient(135deg, #800020, #d68b16);
            border: none;
            border-radius: 50px;
            padding: 12px 30px;
            font-weight: 700;
            color: #fff;
            transition: all 0.3s ease;
            display: inline-block;
            text-decoration: none;
            font-size: 14px;
        }

        .btn-jewel:hover {
            transform: scale(1.05);
            box-shadow: 0 10px 30px rgba(214,139,22,0.4);
            color: #fff;
        }

        /* ========== FOOTER ========== */
        .footer-jewel {
            background: linear-gradient(0deg, #f5e6c8, #fdf6e3);
            border-top: 2px solid #d68b16;
        }

        .footer-jewel h4 { color: #800020; }
        .footer-jewel a { color: #6b5a3e; }
        .footer-jewel a:hover { color: #800020; }

        /* ========== LOADER ========== */
        .loader-necklace { text-align: center; }
        .necklace-chain { display: flex; justify-content: center; gap: 12px; margin-bottom: 24px; }

        .chain-link {
            width: 18px;
            height: 28px;
            border: 3px solid #d68b16;
            border-radius: 50%;
            animation: chainSwing 0.8s ease-in-out infinite alternate;
        }

        .chain-link:nth-child(1){animation-delay:0s}
        .chain-link:nth-child(2){animation-delay:0.1s}
        .chain-link:nth-child(3){animation-delay:0.2s}
        .chain-link:nth-child(4){animation-delay:0.3s}
        .chain-link:nth-child(5){animation-delay:0.4s}

        @keyframes chainSwing {
            0%{transform:rotate(0deg) translateY(0)}
            100%{transform:rotate(15deg) translateY(-5px)}
        }

        .pendant {
            width: 38px;
            height: 38px;
            background: linear-gradient(135deg, #800020, #d68b16);
            clip-path: polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%);
            margin: 0 auto 20px;
            animation: pendantGlow 1s ease-in-out infinite alternate;
        }

        @keyframes pendantGlow {
            from { box-shadow: 0 0 10px #d68b16; transform: scale(1); }
            to { box-shadow: 0 0 30px #d68b16; transform: scale(1.1); }
        }

        /* ========== THEME TOGGLE ========== */
        .theme-toggle {
            width: 52px;
            height: 26px;
            background: rgba(255,255,255,0.2);
            border-radius: 999px;
            position: relative;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 6px;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .theme-toggle .toggle-icon { font-size: 11px; color: rgba(255,255,255,0.8); z-index: 1; }
        .theme-toggle .toggle-ball {
            position: absolute;
            width: 20px;
            height: 20px;
            background: #fff;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: transform 0.3s ease;
        }

        body.dark-theme .theme-toggle .toggle-ball { transform: translateX(26px); }

        /* ========== FOOTER LOGO ========== */
        .footer-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .footer-logo img { width: 50px; height: 50px; object-fit: contain; }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 640px) {
            .stats-grid { grid-template-columns: repeat(2,1fr) !important; gap: 12px !important; }
            .hero-buttons { flex-direction: column; align-items: center; gap: 12px; }
            .hero-buttons a { width: 80%; text-align: center; }
        }

        @media (max-width: 768px) {
            .nav-user-wrap span.nav-user-name { display: none; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .page-wrapper.logged-in-page { margin-left: 0 !important; }
            .mobile-burger { display: block !important; }
            nav.nav-gold { margin-left: 0 !important; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr !important; }
            .floating-logo img { width: 110px !important; height: 110px !important; }
        }

        @media print { body * { visibility: visible; } }
    </style>
</head>
<body class="<?php echo $theme == 'light' ? 'light-theme' : 'dark-theme'; ?>" style="background:#F5F5F5; margin:0; padding:0;">

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

    function toggleTheme() {
        const body = document.body;
        const isLight = body.classList.contains('light-theme');
        body.classList.toggle('light-theme', !isLight);
        body.classList.toggle('dark-theme', isLight);
        document.cookie = "theme=" + (isLight ? 'dark' : 'light') + "; path=/; max-age=" + (365*24*60*60);
        createJewelSparkles();
    }

    function syncNavSpacer() {
        const nav = document.getElementById('mainNav');
        const spacer = document.getElementById('navSpacer');
        if(nav && spacer) {
            spacer.style.height = nav.offsetHeight + 'px';
        }
    }

    function toggleSidebar() {
        const sidebar = document.getElementById('mainSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const burger = document.getElementById('burgerMenu');
        if(sidebar && overlay) {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
            if(burger) burger.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
        }
    }

    function closeSidebar() {
        const sidebar = document.getElementById('mainSidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const burger = document.getElementById('burgerMenu');
        if(sidebar && overlay) {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
            if(burger) burger.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    window.addEventListener('load', function() {
        createJewelSparkles();
        setTimeout(typeEffect, 600);
        setTimeout(function() {
            const ov = document.getElementById('loadingOverlay');
            if(ov) { ov.style.opacity = '0'; ov.style.visibility = 'hidden'; setTimeout(()=>ov.style.display='none', 500); }
        }, 2000);
        syncNavSpacer();
        setTimeout(syncNavSpacer, 300);
    });

    window.addEventListener('resize', syncNavSpacer);
</script>

<!-- Loading Overlay -->
<div id="loadingOverlay" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999;display:flex;justify-content:center;align-items:center;overflow:hidden;transition:opacity 0.6s ease,visibility 0.6s ease;background:radial-gradient(ellipse at 50% 60%, #1a0a00 0%, #0d0500 100%);">

    <!-- Scanlines texture -->
    <div style="position:absolute;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 3px,rgba(214,139,22,0.015) 3px,rgba(214,139,22,0.015) 4px);pointer-events:none;z-index:1;"></div>

    <!-- Stars / sparkles container -->
    <div id="loaderStars" style="position:absolute;inset:0;pointer-events:none;z-index:2;"></div>

    <!-- Expanding rings container -->
    <div id="loaderRings" style="position:absolute;inset:0;pointer-events:none;z-index:2;display:flex;align-items:center;justify-content:center;"></div>

    <!-- Center content -->
    <div style="position:relative;z-index:10;text-align:center;">

        <!-- Logo -->
        <div style="position:relative;width:110px;height:110px;margin:0 auto 28px;display:flex;align-items:center;justify-content:center;">
            <img src="assets/images/moti-removebg-preview.png" alt="Logo" style="max-width:100%;max-height:100%;animation:gemGlowPulse 2s ease-in-out infinite;">
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

<?php if($is_logged_in): ?>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<div class="sidebar" id="mainSidebar">
    <div class="sidebar-logo">
        <?php
        $logo_found = false;
        foreach($logo_paths as $path) {
            if(file_exists($path)) {
                echo '<img src="'.$path.'" alt="Logo">';
                $logo_found = true; break;
            }
        }
        if(!$logo_found) echo '<img src="assets/images/moti-removebg-preview.png" alt="Moti Jewellers Logo" style="width:38px;height:38px;object-fit:contain;display:block;">';
        ?>
        <div class="sidebar-logo-text"><h2>GOURI JEWELLERS</h2><p>Premium Since 2026</p></div>
    </div>
    <nav class="sidebar-nav">
        <div class="sidebar-section-label">Main Menu</div>
        <a href="index.php" class="active"><i class="fas fa-home"></i> HOME</a>
        <a href="billing.php"><i class="fas fa-receipt"></i> BILLING</a>
        <a href="stock.php"><i class="fas fa-boxes"></i> STOCK</a>
        <a href="customers.php"><i class="fas fa-users"></i> CUSTOMERS</a>
        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Analytics</div>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> REPORTS</a>
        <a href="income_expenses.php"><i class="fas fa-chart-line"></i> INCOME & EXP</a>
        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Tools</div>
        <a href="whatsapp_automation.php"><i class="fab fa-whatsapp"></i> WHATSAPP</a>
        <a href="sbook.php"><i class="fas fa-book"></i> SANCHAY</a>
        <a href="purchase.php"><i class="fas fa-shopping-cart"></i> PURCHASE</a>
        <a href="accounts.php"><i class="fas fa-file-invoice-dollar"></i> ACCOUNTS</a>
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
<?php endif; ?>

<!-- ========== TOP NAVBAR ========== -->
<nav class="nav-gold shadow-lg z-50" id="mainNav" style="<?php echo $is_logged_in ? 'margin-left:240px;' : ''; ?>">
    <div class="container mx-auto px-4 sm:px-6 py-3">
        <div class="flex justify-between items-center">
            <div class="flex items-center gap-4">
                <?php if($is_logged_in): ?>
                <div class="mobile-burger" style="display:none;">
                    <div class="burger-menu" id="burgerMenu" onclick="toggleSidebar()">
                        <span></span><span></span><span></span>
                    </div>
                </div>
                <?php endif; ?>
                <img src="assets/images/moti-removebg-preview.png" alt="Logo" style="height:36px;width:auto;object-fit:contain;">
                <span class="font-bold text-white text-sm hidden sm:inline" style="font-family:'Playfair Display',serif;">
                    <i class="fas fa-home mr-2"></i>Dashboard
                </span>
            </div>
            <?php if($is_logged_in): ?>
            <span class="text-sm font-medium text-white">
                <i class="fas fa-user mr-1"></i>
                <?php echo htmlspecialchars($_SESSION['user_name']); ?>
            </span>
            <?php else: ?>
            <a href="login.php" class="nav-login-btn">
                <i class="fas fa-sign-in-alt"></i>
                Login
            </a>
            <?php endif; ?>
        </div>
    </div>
</nav>
<div id="navSpacer"></div>

<!-- ========== PAGE WRAPPER ========== -->
<div class="page-wrapper<?php echo $is_logged_in ? ' logged-in-page' : ''; ?>">

<?php if(!$is_logged_in): ?>
<!-- Hero Section -->
<section class="hero-with-logo py-8 sm:py-10 md:py-12 relative" style="background:linear-gradient(135deg, #fdf6e3 0%, #f5ead0 50%, #fdf6e3 100%);">
    <div class="container mx-auto px-4 sm:px-6 text-center">
        <div class="floating-logo mb-6">
            <?php
            $logo_found = false;
            foreach($logo_paths as $path) {
                if(file_exists($path)) {
                    echo '<img src="'.$path.'" alt="Moti Jewellers Logo">';
                    $logo_found = true; break;
                }
            }
            if(!$logo_found) echo '<img src="assets/images/moti-removebg-preview.png" alt="Moti Jewellers Logo" style="width:140px;height:auto;display:block;margin:0 auto;">';
            ?>
        </div>

        <h1 class="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-bold mt-4 mb-4" style="min-height:1.2em;">
            <span id="typingText" class="typing-text"></span><span class="cursor"></span>
        </h1>

        <p class="text-base sm:text-lg md:text-xl mb-8 max-w-2xl mx-auto" style="color:#7a4e0a;">
            Complete Billing, Stock &amp; Customer Management Solution for Premium Jewellery Businesses
        </p>

        <div class="hero-buttons flex flex-col sm:flex-row justify-center space-y-3 sm:space-y-0 sm:space-x-6">
            <a href="billing.php" class="btn-jewel"><i class="fas fa-receipt mr-2"></i> START BILLING</a>
            <a href="stock.php" class="btn-jewel" style="background:linear-gradient(135deg,#7a4e0a,#d68b16);"><i class="fas fa-boxes mr-2"></i> VIEW STOCK</a>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if($is_logged_in): ?>
<section class="py-8 sm:py-10 md:py-12" style="background: linear-gradient(135deg, #f8f1e4 0%, #fdf6e3 40%, #faf0d5 100%);">
    <div class="container mx-auto px-4 sm:px-6">
        <h2 class="text-3xl sm:text-4xl font-bold text-center mb-8" style="color:#7a4e0a;">Quick Business Dashboard</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6">
            <div class="stat-gem p-6 bg-white shadow-xl border border-yellow-200">
                <div class="text-4xl mb-4" style="color:#b87318;"><i class="fas fa-boxes"></i></div>
                <h3 class="text-3xl font-bold"><?php echo number_format($stock_items_count); ?></h3>
                <p class="uppercase tracking-wider mt-2">Stock Items</p>
                <p class="text-sm text-gray-500 mt-2">Total unique products in inventory.</p>
            </div>
            <div class="stat-gem p-6 bg-white shadow-xl border border-amber-200">
                <div class="text-4xl mb-4" style="color:#d68b16;"><i class="fas fa-weight-hanging"></i></div>
                <h3 class="text-3xl font-bold"><?php echo number_format($stock_quantity); ?></h3>
                <p class="uppercase tracking-wider mt-2">Stock Quantity</p>
                <p class="text-sm text-gray-500 mt-2">Total quantity across all stock items.</p>
            </div>
            <div class="stat-gem p-6 bg-white shadow-xl border border-yellow-300">
                <div class="text-4xl mb-4" style="color:#a16207;"><i class="fas fa-coins"></i></div>
                <h3 class="text-3xl font-bold">₹<?php echo number_format($stock_value, 0); ?></h3>
                <p class="uppercase tracking-wider mt-2">Stock Value</p>
                <p class="text-sm text-gray-500 mt-2">Estimated total value of inventory.</p>
            </div>
            <div class="stat-gem p-6 bg-white shadow-xl border border-emerald-200">
                <div class="text-4xl mb-4" style="color:#047857;"><i class="fas fa-users"></i></div>
                <h3 class="text-3xl font-bold"><?php echo number_format($customers_count); ?></h3>
                <p class="uppercase tracking-wider mt-2">Customers</p>
                <p class="text-sm text-gray-500 mt-2">Total registered customer records.</p>
            </div>
            <div class="stat-gem p-6 bg-white shadow-xl border border-green-200">
                <div class="text-4xl mb-4" style="color:#15803d;"><i class="fas fa-arrow-up-right-from-square"></i></div>
                <h3 class="text-3xl font-bold">₹<?php echo number_format($total_income, 0); ?></h3>
                <p class="uppercase tracking-wider mt-2">Total Income</p>
                <p class="text-sm text-gray-500 mt-2">All recorded income to date.</p>
            </div>
            <div class="stat-gem p-6 bg-white shadow-xl border border-rose-200">
                <div class="text-4xl mb-4" style="color:#b91c1c;"><i class="fas fa-wallet"></i></div>
                <h3 class="text-3xl font-bold">₹<?php echo number_format($total_expense, 0); ?></h3>
                <p class="uppercase tracking-wider mt-2">Total Expenses</p>
                <p class="text-sm text-gray-500 mt-2">All recorded business expenses.</p>
            </div>
            <div class="stat-gem p-6 bg-white shadow-xl border border-orange-200">
                <div class="text-4xl mb-4" style="color:#c2410c;"><i class="fas fa-calendar-check"></i></div>
                <h3 class="text-3xl font-bold">₹<?php echo number_format($total_due, 0); ?></h3>
                <p class="uppercase tracking-wider mt-2">Outstanding Due</p>
                <p class="text-sm text-gray-500 mt-2">Total unpaid invoice balance.</p>
            </div>
            <div class="stat-gem p-6 bg-white shadow-xl border border-sky-200">
                <div class="text-4xl mb-4" style="color:#0ea5e9;"><i class="fas fa-bell"></i></div>
                <h3 class="text-3xl font-bold"><?php echo number_format($invoice_pending_count); ?></h3>
                <p class="uppercase tracking-wider mt-2">Pending Invoices</p>
                <p class="text-sm text-gray-500 mt-2">Invoices requiring payment follow-up.</p>
            </div>
            <div class="stat-gem p-6 bg-white shadow-xl border border-cyan-200">
                <div class="text-4xl mb-4" style="color:#0891b2;"><i class="fas fa-calendar-alt"></i></div>
                <h3 class="text-3xl font-bold">₹<?php echo number_format($monthly_income, 0); ?></h3>
                <p class="uppercase tracking-wider mt-2">Monthly Income</p>
                <p class="text-sm text-gray-500 mt-2">Income recorded this month.</p>
            </div>
        </div>
    </div>
</section>
<section class="dashboard-section py-8 sm:py-10 md:py-12">
    <div class="container mx-auto px-4 sm:px-6">
        <div class="grid gap-6 lg:grid-cols-3 mb-8">
            <div class="dashboard-chart lg:col-span-2">
                <div class="dashboard-title"><i class="fas fa-chart-line"></i> Sales Last 7 Days</div>
                <div style="min-height:280px;">
                    <canvas id="salesChart" height="250"></canvas>
                </div>
            </div>
            <div class="top-products-card">
                <div class="dashboard-title"><i class="fas fa-star"></i> Top Products</div>
                <div class="top-products-list">
                    <?php if(empty($top_products)): ?>
                        <p class="text-sm text-gray-500">No product sales available yet.</p>
                    <?php else: ?>
                        <?php foreach($top_products as $product): ?>
                            <div class="top-product-item flex justify-between items-center">
                                <span class="font-semibold text-sm"><?php echo htmlspecialchars($product['name']); ?></span>
                                <span class="text-sm text-gray-600"><?php echo number_format($product['sold']); ?> sold</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="dashboard-chart">
            <div class="dashboard-title"><i class="fas fa-chart-pie"></i> Monthly Income vs Expenses</div>
            <div style="min-height:280px; max-width:520px; margin:0 auto;">
                <canvas id="incomeExpenseChart" height="250"></canvas>
            </div>
            <div class="mt-4 flex flex-wrap justify-center gap-3 text-sm text-gray-600">
                <span class="px-3 py-2 rounded-full bg-green-50 text-green-700">Income: ₹<?php echo number_format($monthly_income, 2); ?></span>
                <span class="px-3 py-2 rounded-full bg-red-50 text-red-700">Expenses: ₹<?php echo number_format($monthly_expense, 2); ?></span>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2 mt-8">
            <div class="dashboard-chart">
                <div class="dashboard-title"><i class="fas fa-chart-bar"></i> Monthly Sales Trend</div>
                <div style="min-height:280px; max-width:520px; margin:0 auto;">
                    <canvas id="monthlySalesChart" height="250"></canvas>
                </div>
            </div>
            <div class="dashboard-chart">
                <div class="dashboard-title"><i class="fas fa-user-plus"></i> Customer Growth</div>
                <div style="min-height:280px;">
                    <canvas id="customerTrendChart" height="250"></canvas>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2 mt-8">
            <div class="dashboard-chart">
                <div class="dashboard-title"><i class="fas fa-file-invoice"></i> Invoice Completion Rate</div>
                <div style="min-height:280px; max-width:520px; margin:0 auto;">
                    <canvas id="invoiceCompletionChart" height="250"></canvas>
                </div>
            </div>
            <div class="dashboard-chart">
                <div class="dashboard-title"><i class="fas fa-chart-pie"></i> Sales by Category</div>
                <div style="min-height:280px; max-width:520px; margin:0 auto;">
                    <canvas id="categorySalesChart" height="250"></canvas>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2 mt-8">
            <div class="dashboard-chart">
                <div class="dashboard-title"><i class="fas fa-file-invoice"></i> Invoice Count Last 7 Days</div>
                <div style="min-height:280px; max-width:520px; margin:0 auto;">
                    <canvas id="invoiceCountChart" height="250"></canvas>
                </div>
            </div>
            <div class="dashboard-chart">
                <div class="dashboard-title"><i class="fas fa-boxes"></i> Stock Quantity by Category</div>
                <div style="min-height:280px; max-width:520px; margin:0 auto;">
                    <canvas id="categoryStockChart" height="250"></canvas>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2 mt-8">
            <div class="dashboard-chart p-6">
                <div class="dashboard-title"><i class="fas fa-exclamation-triangle"></i> Low Stock Alerts</div>
                <?php if(empty($low_stock_items)): ?>
                    <p class="text-sm text-gray-500">No low-stock products currently.</p>
                <?php else: ?>
                    <ul class="space-y-3 mt-4">
                        <?php foreach($low_stock_items as $item): ?>
                            <li class="flex justify-between items-center border border-gray-100 rounded-xl p-3">
                                <span class="font-medium"><?php echo htmlspecialchars($item['name']); ?></span>
                                <span class="text-sm text-red-600 font-semibold"><?php echo number_format($item['quantity']); ?> pcs</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="dashboard-chart p-6">
                <div class="dashboard-title"><i class="fas fa-file-invoice-dollar"></i> Pending Invoices</div>
                <?php if(empty($pending_invoices)): ?>
                    <p class="text-sm text-gray-500">No pending invoices at the moment.</p>
                <?php else: ?>
                    <ul class="space-y-3 mt-4">
                        <?php foreach($pending_invoices as $invoice): ?>
                            <li class="border border-gray-100 rounded-xl p-3">
                                <div class="flex justify-between items-start gap-3">
                                    <div>
                                        <p class="font-semibold"><?php echo htmlspecialchars($invoice['invoice_no']); ?></p>
                                        <p class="text-sm text-gray-500"><?php echo htmlspecialchars($invoice['customer_name']); ?></p>
                                    </div>
                                    <span class="text-sm text-orange-700 font-semibold">₹<?php echo number_format($invoice['balance_amount'], 2); ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Stats Section -->
<div class="container mx-auto px-4 sm:px-6 py-8">
    <div class="stats-grid grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-5">
        <?php
        $products_count = $conn->query("SELECT COUNT(*) as total FROM products")->fetch_assoc();
        $stock_value    = $conn->query("SELECT SUM(price * quantity) as total FROM products")->fetch_assoc();
        $today_sales    = $conn->query("SELECT SUM(total_amount) as total FROM invoices WHERE DATE(created_at) = CURDATE()")->fetch_assoc();
        $customers_count = $conn->query("SELECT COUNT(*) as total FROM customers")->fetch_assoc();
        ?>
        <div class="stat-gem p-5">
            <i class="fas fa-gem mb-3 block"></i>
            <h3><?php echo $products_count['total'] ?? 0; ?></h3>
            <p class="uppercase tracking-wider mt-1">Total Products</p>
        </div>
        <div class="stat-gem p-5">
            <i class="fas fa-rupee-sign mb-3 block"></i>
            <h3>₹<?php echo number_format($stock_value['total'] ?? 0, 0); ?></h3>
            <p class="uppercase tracking-wider mt-1">Stock Value</p>
        </div>
        <div class="stat-gem p-5">
            <i class="fas fa-chart-line mb-3 block"></i>
            <h3>₹<?php echo number_format($today_sales['total'] ?? 0, 0); ?></h3>
            <p class="uppercase tracking-wider mt-1">Today's Sales</p>
        </div>
        <div class="stat-gem p-5">
            <i class="fas fa-users mb-3 block"></i>
            <h3><?php echo $customers_count['total'] ?? 0; ?></h3>
            <p class="uppercase tracking-wider mt-1">Happy Customers</p>
        </div>
    </div>
</div>

<!-- Features Section -->
<section class="py-12 md:py-20" style="background:#fdf6e3;">
    <div class="container mx-auto px-4 sm:px-6">
        <h2 class="text-3xl sm:text-4xl font-bold text-center mb-12" style="color:#800020;">
            ✦ EXCLUSIVE FEATURES ✦
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="gem-card p-8 text-center">
                <div class="gem-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                <h3 class="text-xl font-bold mb-3">GST Billing System</h3>
                <p>Create GST and Non-GST invoices with automatic tax calculation</p>
            </div>
            <div class="gem-card p-8 text-center">
                <div class="gem-icon"><i class="fas fa-chart-line"></i></div>
                <h3 class="text-xl font-bold mb-3">Live Stock Tracking</h3>
                <p>Real-time inventory management with low stock alerts</p>
            </div>
            <div class="gem-card p-8 text-center">
                <div class="gem-icon"><i class="fas fa-calculator"></i></div>
                <h3 class="text-xl font-bold mb-3">EMI Calculator</h3>
                <p>Calculate EMI options for your customers easily</p>
            </div>
        </div>
    </div>
</section>

<!-- Footer -->
<footer class="footer-jewel py-10 mt-4">
    <div class="container mx-auto px-4 sm:px-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
            <div>
                <div class="footer-logo">
                    <?php
                    $logo_found = false;
                    foreach($logo_paths as $path) {
                        if(file_exists($path)) { echo '<img src="'.$path.'" alt="Logo">'; $logo_found=true; break; }
                    }
                    if(!$logo_found) echo '<i class="fas fa-gem" style="color:#800020;font-size:28px;"></i>';
                    ?>
                    <h3 class="text-lg font-bold" style="color:#800020;">MAA GOURI JEWELLERS</h3>
                </div>
                <p class="text-sm" style="color:#6b5a3e;">Premium jewellery management system for royal businesses.</p>
            </div>
            <div>
                <h4 class="font-bold mb-4">QUICK LINKS</h4>
                <ul class="space-y-2 text-sm" style="color:#6b5a3e;">
                    <li><a href="index.php">Home</a></li>
                    <li><a href="billing.php">Billing</a></li>
                    <li><a href="stock.php">Stock</a></li>
                    <li><a href="reports.php">Reports</a></li>
                </ul>
            </div>
            <div>
                <h4 class="font-bold mb-4">CONTACT</h4>
                <ul class="space-y-2 text-sm" style="color:#6b5a3e;">
                    <li><i class="fas fa-phone mr-2" style="color:#d68b16;"></i> +91 96472 91299</li>
                    <li><i class="fas fa-envelope mr-2" style="color:#d68b16;"></i> santudhara157@gmail.com</li>
                    <li><i class="fab fa-whatsapp mr-2" style="color:#25D366;"></i> WhatsApp Concierge</li>
                </ul>
            </div>
            <div>
                <h4 class="font-bold mb-4">HOURS</h4>
                <p class="text-sm" style="color:#6b5a3e;">Monday - Sunday: 10AM - 8PM</p>
                <p class="text-sm mt-1" style="color:#6b5a3e;">Thursday: Royal Holiday</p>
            </div>
        </div>
        <div class="mt-8 pt-6 text-center" style="border-top:1px solid rgba(181,115,14,0.25);">
            <p class="text-xs" style="color:#7a4e0a;">
                &copy; 2026 MAA GOURI JEWELLERS &nbsp;|&nbsp; CRAFTED WITH ELEGANCE &nbsp;|&nbsp;
                Developed by <a href="https://saamparktechnologyresearch.in/" target="_blank" style="text-decoration:underline;color:#800020;">Saampark Technology</a>
            </p>
        </div>
    </div>
</footer>

</div><!-- end .page-wrapper -->

<?php if($is_logged_in): ?>
<script>
    const salesLabels = <?php echo json_encode(array_column($daily_sales, 'date')); ?>;
    const salesValues = <?php echo json_encode(array_column($daily_sales, 'total')); ?>;
    const salesCtx = document.getElementById('salesChart');
    if(salesCtx) {
        new Chart(salesCtx, {
            type: 'line',
            data: {
                labels: salesLabels,
                datasets: [{
                    label: 'Sales',
                    data: salesValues,
                    backgroundColor: 'rgba(214,139,22,0.12)',
                    borderColor: '#b5730e',
                    borderWidth: 2,
                    pointBackgroundColor: '#d68b16',
                    pointRadius: 4,
                    fill: true,
                    tension: 0.35
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) { return '₹' + value.toLocaleString(); }
                        }
                    },
                    x: {
                        grid: { display: false }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }

        const incomeExpenseCtx = document.getElementById('incomeExpenseChart');
        if(incomeExpenseCtx) {
            new Chart(incomeExpenseCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Income', 'Expenses'],
                    datasets: [{
                        data: [<?php echo json_encode($monthly_income); ?>, <?php echo json_encode($monthly_expense); ?>],
                        backgroundColor: ['#16a34a', '#dc2626'],
                        borderColor: ['#ffffff', '#ffffff'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { boxWidth: 12, padding: 16 }
                        }
                    }
                }
            });
        }

        const monthlySalesCtx = document.getElementById('monthlySalesChart');
        if(monthlySalesCtx) {
            new Chart(monthlySalesCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($monthly_sales, 'month')); ?>,
                    datasets: [{
                        label: 'Sales',
                        data: <?php echo json_encode(array_column($monthly_sales, 'total')); ?>,
                        backgroundColor: ['#2563eb', '#4f46e5', '#ec4899', '#f59e0b', '#10b981', '#ef4444'],
                        borderColor: '#1d4ed8',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { callback: function(value) { return '₹' + value.toLocaleString(); } } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        // <meta name="author" content="MANU GUPTA">
        const customerTrendCtx = document.getElementById('customerTrendChart');
        if(customerTrendCtx) {
            new Chart(customerTrendCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($customer_growth, 'date')); ?>,
                    datasets: [{
                        label: 'New Customers',
                        data: <?php echo json_encode(array_column($customer_growth, 'total')); ?>,
                        backgroundColor: 'rgba(16,185,129,0.12)',
                        borderColor: '#059669',
                        borderWidth: 2,
                        pointBackgroundColor: '#10b981',
                        pointRadius: 4,
                        tension: 0.35,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        const invoiceCompletionCtx = document.getElementById('invoiceCompletionChart');
        if(invoiceCompletionCtx) {
            new Chart(invoiceCompletionCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Completed', 'Pending'],
                    datasets: [{
                        data: [<?php echo json_encode($invoice_completed_count); ?>, <?php echo json_encode($invoice_pending_count); ?>],
                        backgroundColor: ['#14b8a6', '#f97316'],
                        borderColor: ['#ffffff', '#ffffff'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, padding: 16 } }
                    }
                }
            });
        }

        const categorySalesCtx = document.getElementById('categorySalesChart');
        if(categorySalesCtx) {
            new Chart(categorySalesCtx, {
                type: 'polarArea',
                data: {
                    labels: <?php echo json_encode(array_column($category_sales, 'category')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_map(function($row){ return (float)$row['revenue']; }, $category_sales)); ?>,
                        backgroundColor: ['#e11d48', '#6366f1', '#22c55e', '#f59e0b', '#0ea5e9', '#8b5cf6'],
                        borderColor: '#ffffff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, padding: 16 } }
                    }
                }
            });
        }

        const invoiceCountCtx = document.getElementById('invoiceCountChart');
        if(invoiceCountCtx) {
            new Chart(invoiceCountCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($daily_invoice_counts, 'date')); ?>,
                    datasets: [{
                        label: 'Invoices',
                        data: <?php echo json_encode(array_column($daily_invoice_counts, 'total')); ?>,
                        backgroundColor: 'rgba(14,165,233,0.08)',
                        borderColor: '#0ea5e9',
                        borderWidth: 3,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#0ea5e9',
                        pointRadius: 5,
                        pointStyle: 'rectRot',
                        stepped: 'middle',
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        const categoryStockCtx = document.getElementById('categoryStockChart');
        if(categoryStockCtx) {
            new Chart(categoryStockCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($category_stock, 'category')); ?>,
                    datasets: [{
                        label: 'Stock Quantity',
                        data: <?php echo json_encode(array_map(function($row){ return (float)$row['total_qty']; }, $category_stock)); ?>,
                        backgroundColor: 'rgba(59,130,246,0.12)',
                        borderColor: '#2563EB',
                        borderWidth: 2,
                        pointBackgroundColor: '#2563EB',
                        pointBorderColor: '#ffffff',
                        pointRadius: 4,
                        tension: 0.35,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }
    </script>
    <?php endif; ?>
</html>