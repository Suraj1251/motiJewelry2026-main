<?php
session_start();
require_once 'config/database.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
// <meta name="author" content="MANU GUPTA">
// <meta name="description" content="Income and Expense Management for Gouri Jewellers">

// Handle Add Income
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_income'])) {
    $income_date    = mysqli_real_escape_string($conn, $_POST['income_date']);
    $source         = mysqli_real_escape_string($conn, $_POST['source']);
    $category       = mysqli_real_escape_string($conn, $_POST['category']);
    $amount         = floatval($_POST['amount']);
    $description    = mysqli_real_escape_string($conn, $_POST['description']);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);
    $invoice_no     = mysqli_real_escape_string($conn, $_POST['invoice_no'] ?? '');
    $created_by     = $_SESSION['user_id'];
    if(mysqli_query($conn, "INSERT INTO income (income_date, source, category, amount, description, payment_method, invoice_no, created_by) VALUES ('$income_date', '$source', '$category', $amount, '$description', '$payment_method', '$invoice_no', $created_by)")) {
        $success_income = "✅ Income added successfully!";
    } else {
        $error_income = "❌ Error: " . mysqli_error($conn);
    }
}

// Handle Add Expense
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_expense'])) {
    $expense_date   = mysqli_real_escape_string($conn, $_POST['expense_date']);
    $category       = mysqli_real_escape_string($conn, $_POST['category']);
    $amount         = floatval($_POST['amount']);
    $description    = mysqli_real_escape_string($conn, $_POST['description']);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method']);
    $bill_no        = mysqli_real_escape_string($conn, $_POST['bill_no'] ?? '');
    $vendor_name    = mysqli_real_escape_string($conn, $_POST['vendor_name'] ?? '');
    $created_by     = $_SESSION['user_id'];
    if(mysqli_query($conn, "INSERT INTO expenses (expense_date, category, amount, description, payment_method, bill_no, vendor_name, created_by) VALUES ('$expense_date', '$category', $amount, '$description', '$payment_method', '$bill_no', '$vendor_name', $created_by)")) {
        $success_expense = "✅ Expense added successfully!";
    } else {
        $error_expense = "❌ Error: " . mysqli_error($conn);
    }
}

// Handle Delete
if(isset($_GET['delete_income'])) {
    $id = intval($_GET['delete_income']);
    mysqli_query($conn, "DELETE FROM income WHERE id = $id");
    echo "<script>alert('Income deleted!'); window.location.href='income_expenses.php';</script>"; exit();
}
if(isset($_GET['delete_expense'])) {
    $id = intval($_GET['delete_expense']);
    mysqli_query($conn, "DELETE FROM expenses WHERE id = $id");
    echo "<script>alert('Expense deleted!'); window.location.href='income_expenses.php';</script>"; exit();
}

$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to   = isset($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');

$income_result = mysqli_query($conn, "SELECT * FROM income WHERE income_date BETWEEN '$date_from' AND '$date_to' ORDER BY income_date DESC");
$total_income = 0; $income_records = [];
while($row = mysqli_fetch_assoc($income_result)) { $total_income += $row['amount']; $income_records[] = $row; }

$expense_result = mysqli_query($conn, "SELECT * FROM expenses WHERE expense_date BETWEEN '$date_from' AND '$date_to' ORDER BY expense_date DESC");
$total_expense = 0; $expense_records = [];
while($row = mysqli_fetch_assoc($expense_result)) { $total_expense += $row['amount']; $expense_records[] = $row; }

$sales_data      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount),0) AS total_sales, COUNT(*) AS sales_count FROM invoices WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'"));
$total_sales      = floatval($sales_data['total_sales']);
$total_sales_count = intval($sales_data['sales_count']);
$net_profit       = $total_income - $total_expense;

$income_categories  = mysqli_query($conn, "SELECT category_name FROM income_categories WHERE status='active' ORDER BY category_name");
$expense_categories = mysqli_query($conn, "SELECT category_name FROM expense_categories WHERE status='active' ORDER BY category_name");

// Chart data
$income_by_cat = []; foreach($income_records as $r) { $income_by_cat[$r['category']] = ($income_by_cat[$r['category']] ?? 0) + $r['amount']; }
$expense_by_cat = []; foreach($expense_records as $r) { $expense_by_cat[$r['category']] = ($expense_by_cat[$r['category']] ?? 0) + $r['amount']; }

$logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="author" content="MANU GUPTA">
    <meta name="description" content="Income and Expense Management for Gouri Jewellers">
    <title>Income &amp; Expenses — Maa Gouri Jewellers</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            z-index: 1000; display: flex; flex-direction: column;
            box-shadow: 4px 0 24px rgba(0,0,0,0.25);
            transition: transform 0.35s cubic-bezier(.4,0,.2,1);
            overflow-y: auto; overflow-x: hidden;
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }

        .sidebar-logo { padding: 22px 18px 16px; border-bottom: 1px solid rgba(255,255,255,0.18); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .sidebar-logo img { width: 44px; height: 44px; object-fit: contain; border-radius: 50%; background: rgba(255,255,255,0.1); padding: 3px; flex-shrink: 0; }
        .sidebar-logo-text h2 { color: #fff; font-size: 13px; font-weight: 700; line-height: 1.3; font-family: 'Playfair Display', serif; letter-spacing: 0.5px; }
        .sidebar-logo-text p  { color: rgba(255,255,255,0.65); font-size: 10px; margin-top: 1px; }

        .sidebar-nav { flex: 1; padding: 10px 0; }
        .sidebar-section-label { padding: 10px 20px 4px; color: rgba(255,255,255,0.45); font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; }

        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 11px 20px; color: rgba(255,255,255,0.85); text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s ease; border-left: 3px solid transparent; letter-spacing: 0.3px; position: relative; }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.13); color: #fff; border-left-color: rgba(255,255,255,0.8); padding-left: 26px; }
        .sidebar-nav a.active { background: rgba(255,255,255,0.22); color: #fff; border-left-color: #fff; font-weight: 700; }
        .sidebar-nav a.active::after { content: ''; position: absolute; right: 0; top: 50%; transform: translateY(-50%); width: 4px; height: 60%; background: #fff; border-radius: 4px 0 0 4px; }
        .sidebar-nav a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; opacity: 0.9; }

        .sidebar-divider { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 16px; }

        .sidebar-user { padding: 14px 16px 18px; border-top: 1px solid rgba(255,255,255,0.18); background: rgba(0,0,0,0.12); flex-shrink: 0; }
        .sidebar-user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .sidebar-user-info i { color: rgba(255,255,255,0.9); font-size: 26px; flex-shrink: 0; }
        .sidebar-user-info .user-details p { color: #fff; font-size: 12px; font-weight: 600; line-height: 1.3; }
        .sidebar-user-info .user-details span { color: rgba(255,255,255,0.55); font-size: 10px; }

        .sidebar-logout { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; background: rgba(239,68,68,0.75); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; transition: background 0.2s; border: 1px solid rgba(239,68,68,0.4); }
        .sidebar-logout:hover { background: #ef4444; color: #fff; }

        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; backdrop-filter: blur(2px); }
        .sidebar-overlay.active { display: block; }

        /* ========== LAYOUT ========== */
        .page-wrapper { margin-left: 240px; min-height: 100vh; background: #F5F5F5; transition: margin-left 0.35s ease; }
        nav.nav-gold { background: linear-gradient(135deg, #b5730e, #d68b16) !important; }

        .burger-menu { width: 28px; height: 20px; position: relative; cursor: pointer; }
        .burger-menu span { display: block; position: absolute; height: 3px; width: 100%; background: #fff; border-radius: 3px; transition: all 0.3s ease; }
        .burger-menu span:nth-child(1) { top: 0; }
        .burger-menu span:nth-child(2) { top: 9px; }
        .burger-menu span:nth-child(3) { top: 18px; }
        .burger-menu.active span:nth-child(1) { top: 9px; transform: rotate(135deg); }
        .burger-menu.active span:nth-child(2) { opacity: 0; left: -20px; }
        .burger-menu.active span:nth-child(3) { top: 9px; transform: rotate(-135deg); }

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

        .page-heading { background: linear-gradient(135deg, #fdf6e3, #f5ead0); border-bottom: 2px solid rgba(181,115,14,0.2); padding: 20px 28px; }
        .page-heading h1 { color: #800020; font-size: 1.6rem; }
        .page-heading p  { color: #7a4e0a; font-size: 13px; margin-top: 2px; }

        .jewel-card { background: #fff; border: 1px solid rgba(181,115,14,0.2); border-radius: 20px; box-shadow: 0 4px 20px rgba(181,115,14,0.08); }

        /* Stat cards */
        .stat-income  { background: linear-gradient(135deg, #166534, #15803d); }
        .stat-expense { background: linear-gradient(135deg, #9f1239, #be123c); }
        .stat-sales   { background: linear-gradient(135deg, #7a4e0a, #d68b16); }
        .stat-profit  { background: linear-gradient(135deg, #1e3a5f, #2563eb); }
        .stat-count   { background: linear-gradient(135deg, #4c1d95, #7c3aed); }

        .stat-card { border-radius: 16px; padding: 18px; color: #fff; transition: transform 0.3s ease; }
        .stat-card:hover { transform: translateY(-4px); }
        .stat-card .stat-val { font-size: 1.6rem; font-weight: 700; line-height: 1; margin-top: 4px; }
        .stat-card .stat-lbl { font-size: 12px; opacity: 0.85; margin-bottom: 4px; }
        .stat-card .stat-sub { font-size: 10px; opacity: 0.7; margin-top: 4px; }
        .stat-card i { font-size: 2rem; opacity: 0.3; }

        /* Inputs */
        .jewel-input { background: #fdf6e3; border: 1.5px solid rgba(181,115,14,0.3); color: #4a3000; border-radius: 10px; padding: 8px 12px; font-size: 13px; transition: all 0.25s; width: 100%; font-family: 'Poppins', sans-serif; outline: none; }
        .jewel-input:focus { border-color: #d68b16; box-shadow: 0 0 0 3px rgba(214,139,22,0.15); background: #fffdf5; }
        .jewel-input::placeholder { color: rgba(122,78,10,0.4); }
        select.jewel-input option { background: #fff; color: #4a3000; }

        label.field-label { display: block; font-size: 11px; font-weight: 600; color: #7a4e0a; margin-bottom: 5px; }

        /* Buttons */
        .btn-jewel { background: linear-gradient(135deg, #800020, #d68b16); border: none; border-radius: 10px; padding: 10px 22px; font-weight: 700; color: #fff; transition: all 0.3s; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; font-family: 'Poppins', sans-serif; }
        .btn-jewel:hover { transform: scale(1.04); box-shadow: 0 8px 24px rgba(214,139,22,0.35); }

        .btn-income  { background: linear-gradient(135deg, #166534, #16a34a); color: #fff; border: none; border-radius: 10px; padding: 10px; width: 100%; font-weight: 700; font-size: 13px; cursor: pointer; transition: all 0.3s; font-family: 'Poppins', sans-serif; }
        .btn-income:hover  { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(22,163,74,0.35); }
        .btn-expense { background: linear-gradient(135deg, #9f1239, #dc2626); color: #fff; border: none; border-radius: 10px; padding: 10px; width: 100%; font-weight: 700; font-size: 13px; cursor: pointer; transition: all 0.3s; font-family: 'Poppins', sans-serif; }
        .btn-expense:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(220,38,38,0.35); }

        .btn-filter { background: linear-gradient(135deg, #800020, #d68b16); color: #fff; border: none; border-radius: 10px; padding: 9px 18px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.25s; font-family: 'Poppins', sans-serif; display: inline-flex; align-items: center; gap: 6px; }
        .btn-filter:hover { transform: scale(1.04); }

        .btn-clear { background: #e5e7eb; color: #374151; border: none; border-radius: 10px; padding: 9px 18px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; font-family: 'Poppins', sans-serif; transition: background 0.2s; }
        .btn-clear:hover { background: #d1d5db; }

        .btn-delete { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; border: none; border-radius: 8px; padding: 4px 10px; font-size: 11px; cursor: pointer; text-decoration: none; display: inline-block; transition: all 0.2s; }
        .btn-delete:hover { transform: scale(1.04); box-shadow: 0 4px 12px rgba(239,68,68,0.4); color: #fff; }

        /* Table */
        .jewel-table { width: 100%; border-collapse: collapse; }
        .jewel-table th { background: linear-gradient(135deg, #7a4e0a, #d68b16); color: #fff; font-weight: 600; padding: 10px 10px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .jewel-table td { padding: 9px 10px; border-bottom: 1px solid rgba(181,115,14,0.1); color: #3a2800; font-size: 12px; }
        .jewel-table tbody tr:hover { background: #fdf6e3; }
        .jewel-table tbody tr:nth-child(even) { background: #fffbf0; }
        .jewel-table tbody tr:nth-child(even):hover { background: #fdf6e3; }
        .jewel-table tfoot td { font-weight: 700; font-size: 13px; background: rgba(181,115,14,0.06); padding: 10px; }

        /* Section titles */
        .income-title  { color: #166534; }
        .expense-title { color: #9f1239; }

        /* Filter card */
        .filter-card { background: #fff; border: 1px solid rgba(181,115,14,0.2); border-radius: 16px; padding: 16px 20px; }

        /* Form card accent bars */
        .income-card  { border-top: 3px solid #16a34a; }
        .expense-card { border-top: 3px solid #dc2626; }

        /* Footer */
        footer { background: linear-gradient(0deg, #f5e6c8, #fdf6e3); border-top: 2px solid #d68b16; padding: 20px; text-align: center; margin-top: 40px; }

        @media (max-width: 640px) {
            .stats-row { grid-template-columns: repeat(2,1fr) !important; }
            .table-wrap { overflow-x: auto; }
            .jewel-table { min-width: 560px; }
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
    <!-- background diamonds removed to keep only central gem -->

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

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ========== SIDEBAR ========== -->
<div class="sidebar" id="mainSidebar">
    <div class="sidebar-logo">
        <?php
        $logo_found = false;
        foreach($logo_paths as $path) {
            if(file_exists($path)) { echo '<img src="'.$path.'" alt="Logo">'; $logo_found=true; break; }
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
        <a href="customers.php"><i class="fas fa-users"></i> CUSTOMERS</a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Analytics</div>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> REPORTS</a>
        <a href="income_expenses.php" class="active"><i class="fas fa-chart-line"></i> INCOME &amp; EXP</a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Tools</div>
        <a href="whatsapp_automation.php"><i class="fab fa-whatsapp"></i> WHATSAPP</a>
        <a href="sbook.php"><i class="fas fa-book"></i> KARIGORI</a>
         <a href="purchase.php">
            <i class="fas fa-book"></i>PURCHASE
        </a>
        <a href="account.php">
            <i class="fas fa-book"></i> ACCOUNT
        </a>
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
                    <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($_SESSION['user_name']); ?>
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
        <h1 class="gold-font"><i class="fas fa-chart-line mr-2"></i> Income &amp; Expenses</h1>
        <p>Track your business income, expenses and net profit</p>
    </div>

    <div class="container mx-auto px-4 sm:px-6 py-6">

        <!-- Alerts -->
        <?php if(isset($success_income)): ?>
            <div class="mb-4 px-4 py-3 rounded-lg text-sm" style="background:#f0fdf4;border:1px solid #86efac;color:#166534;"><i class="fas fa-check-circle mr-2"></i><?php echo $success_income; ?></div>
        <?php endif; ?>
        <?php if(isset($success_expense)): ?>
            <div class="mb-4 px-4 py-3 rounded-lg text-sm" style="background:#f0fdf4;border:1px solid #86efac;color:#166534;"><i class="fas fa-check-circle mr-2"></i><?php echo $success_expense; ?></div>
        <?php endif; ?>
        <?php if(isset($error_income)): ?>
            <div class="mb-4 px-4 py-3 rounded-lg text-sm" style="background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;"><i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_income; ?></div>
        <?php endif; ?>
        <?php if(isset($error_expense)): ?>
            <div class="mb-4 px-4 py-3 rounded-lg text-sm" style="background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;"><i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_expense; ?></div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="stats-row grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
            <div class="stat-card stat-income">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="stat-lbl">💰 Total Income</p>
                        <p class="stat-val">₹<?php echo number_format($total_income, 2); ?></p>
                    </div>
                    <i class="fas fa-arrow-up"></i>
                </div>
            </div>
            <div class="stat-card stat-expense">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="stat-lbl">💸 Total Expenses</p>
                        <p class="stat-val">₹<?php echo number_format($total_expense, 2); ?></p>
                    </div>
                    <i class="fas fa-arrow-down"></i>
                </div>
            </div>
            <div class="stat-card stat-sales">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="stat-lbl">📈 Total Sales</p>
                        <p class="stat-val">₹<?php echo number_format($total_sales, 2); ?></p>
                        <p class="stat-sub"><?php echo $total_sales_count; ?> invoices</p>
                    </div>
                    <i class="fas fa-receipt"></i>
                </div>
            </div>
            <div class="stat-card stat-profit">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="stat-lbl">👑 Net Profit/Loss</p>
                        <p class="stat-val" style="color:<?php echo $net_profit>=0?'#86efac':'#fca5a5'; ?>">₹<?php echo number_format($net_profit, 2); ?></p>
                    </div>
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
            <div class="stat-card stat-count">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="stat-lbl">✨ Transactions</p>
                        <p class="stat-val"><?php echo count($income_records) + count($expense_records); ?></p>
                    </div>
                    <i class="fas fa-exchange-alt"></i>
                </div>
            </div>
        </div>

        <!-- Date Filter -->
        <div class="filter-card mb-6">
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="field-label">📅 From Date</label>
                    <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="jewel-input">
                </div>
                <div>
                    <label class="field-label">📅 To Date</label>
                    <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="jewel-input">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Filter</button>
                    <a href="income_expenses.php" class="btn-clear"><i class="fas fa-times"></i> Reset</a>
                </div>
            </form>
        </div>

        <!-- Add Forms -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

            <!-- Add Income -->
            <div class="jewel-card income-card p-5">
                <h2 class="gold-font text-lg font-bold mb-4 income-title"><i class="fas fa-plus-circle mr-2"></i>Add Income</h2>
                <form method="POST">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="field-label">📅 Date *</label>
                            <input type="date" name="income_date" value="<?php echo date('Y-m-d'); ?>" required class="jewel-input">
                        </div>
                        <div>
                            <label class="field-label">🏷️ Source *</label>
                            <input type="text" name="source" required placeholder="e.g. Customer Name" class="jewel-input">
                        </div>
                        <div>
                            <label class="field-label">📂 Category *</label>
                            <select name="category" required class="jewel-input">
                                <option value="">— Select Category —</option>
                                <?php mysqli_data_seek($income_categories, 0); while($cat = mysqli_fetch_assoc($income_categories)): ?>
                                    <option value="<?php echo htmlspecialchars($cat['category_name']); ?>">💰 <?php echo htmlspecialchars($cat['category_name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div>
                            <label class="field-label">💵 Amount (₹) *</label>
                            <input type="number" step="0.01" name="amount" required placeholder="0.00" class="jewel-input">
                        </div>
                        <div>
                            <label class="field-label">💳 Payment Method</label>
                            <select name="payment_method" class="jewel-input">
                                <option value="cash">💵 Cash</option>
                                <option value="card">💳 Card</option>
                                <option value="upi">📱 UPI</option>
                                <option value="bank">🏦 Bank Transfer</option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label">📄 Invoice No</label>
                            <input type="text" name="invoice_no" placeholder="INV-001" class="jewel-input">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="field-label">📝 Description</label>
                            <textarea name="description" rows="2" placeholder="Additional details…" class="jewel-input"></textarea>
                        </div>
                    </div>
                    <button type="submit" name="add_income" class="btn-income mt-4">
                        <i class="fas fa-save mr-1"></i> Add Income
                    </button>
                </form>
            </div>

            <!-- Add Expense -->
            <div class="jewel-card expense-card p-5">
                <h2 class="gold-font text-lg font-bold mb-4 expense-title"><i class="fas fa-minus-circle mr-2"></i>Add Expense</h2>
                <form method="POST">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="field-label">📅 Date *</label>
                            <input type="date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" required class="jewel-input">
                        </div>
                        <div>
                            <label class="field-label">📂 Category *</label>
                            <select name="category" required class="jewel-input">
                                <option value="">— Select Category —</option>
                                <?php mysqli_data_seek($expense_categories, 0); while($cat = mysqli_fetch_assoc($expense_categories)): ?>
                                    <option value="<?php echo htmlspecialchars($cat['category_name']); ?>">💸 <?php echo htmlspecialchars($cat['category_name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div>
                            <label class="field-label">💵 Amount (₹) *</label>
                            <input type="number" step="0.01" name="amount" required placeholder="0.00" class="jewel-input">
                        </div>
                        <div>
                            <label class="field-label">💳 Payment Method</label>
                            <select name="payment_method" class="jewel-input">
                                <option value="cash">💵 Cash</option>
                                <option value="card">💳 Card</option>
                                <option value="upi">📱 UPI</option>
                                <option value="bank">🏦 Bank Transfer</option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label">📄 Bill No</label>
                            <input type="text" name="bill_no" placeholder="BILL-001" class="jewel-input">
                        </div>
                        <div>
                            <label class="field-label">🏢 Vendor Name</label>
                            <input type="text" name="vendor_name" placeholder="Supplier/Vendor" class="jewel-input">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="field-label">📝 Description</label>
                            <textarea name="description" rows="2" placeholder="Additional details…" class="jewel-input"></textarea>
                        </div>
                    </div>
                    <button type="submit" name="add_expense" class="btn-expense mt-4">
                        <i class="fas fa-save mr-1"></i> Add Expense
                    </button>
                </form>
            </div>
        </div>

        <!-- Income Records Table -->
        <div class="jewel-card income-card p-5 mb-6">
            <h2 class="gold-font text-lg font-bold mb-4 income-title"><i class="fas fa-arrow-up mr-2"></i>Income Records</h2>
            <div class="table-wrap overflow-x-auto rounded-xl" style="border:1px solid rgba(22,163,74,0.15);">
                <table class="jewel-table">
                    <thead>
                        <tr>
                            <th class="text-left">Date</th>
                            <th class="text-left">Source</th>
                            <th class="text-left">Category</th>
                            <th class="text-right">Amount</th>
                            <th class="text-left">Method</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($income_records): foreach($income_records as $inc): ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($inc['income_date'])); ?></td>
                            <td class="font-semibold" style="color:#166534;"><?php echo htmlspecialchars($inc['source']); ?></td>
                            <td style="color:#7a4e0a;"><?php echo htmlspecialchars($inc['category']); ?></td>
                            <td class="text-right font-bold" style="color:#16a34a;">₹<?php echo number_format($inc['amount'],2); ?></td>
                            <td style="text-transform:uppercase;font-size:11px;"><?php echo htmlspecialchars($inc['payment_method']); ?></td>
                            <td class="text-center">
                                <a href="?delete_income=<?php echo $inc['id']; ?>" onclick="return confirm('Delete this income record?')" class="btn-delete">
                                    <i class="fas fa-trash mr-1"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="6" class="text-center py-8" style="color:#7a4e0a;opacity:0.6;">No income records found for this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="color:#166534;">Total Income</td>
                            <td class="text-right" style="color:#16a34a;">₹<?php echo number_format($total_income,2); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Expense Records Table -->
        <div class="jewel-card expense-card p-5 mb-6">
            <h2 class="gold-font text-lg font-bold mb-4 expense-title"><i class="fas fa-arrow-down mr-2"></i>Expense Records</h2>
            <div class="table-wrap overflow-x-auto rounded-xl" style="border:1px solid rgba(220,38,38,0.15);">
                <table class="jewel-table">
                    <thead>
                        <tr>
                            <th class="text-left">Date</th>
                            <th class="text-left">Category</th>
                            <th class="text-left">Vendor</th>
                            <th class="text-right">Amount</th>
                            <th class="text-left">Method</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($expense_records): foreach($expense_records as $exp): ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($exp['expense_date'])); ?></td>
                            <td style="color:#9f1239;"><?php echo htmlspecialchars($exp['category']); ?></td>
                            <td style="color:#7a4e0a;"><?php echo htmlspecialchars($exp['vendor_name'] ?? '—'); ?></td>
                            <td class="text-right font-bold" style="color:#dc2626;">₹<?php echo number_format($exp['amount'],2); ?></td>
                            <td style="text-transform:uppercase;font-size:11px;"><?php echo htmlspecialchars($exp['payment_method']); ?></td>
                            <td class="text-center">
                                <a href="?delete_expense=<?php echo $exp['id']; ?>" onclick="return confirm('Delete this expense record?')" class="btn-delete">
                                    <i class="fas fa-trash mr-1"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="6" class="text-center py-8" style="color:#7a4e0a;opacity:0.6;">No expense records found for this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="color:#9f1239;">Total Expenses</td>
                            <td class="text-right" style="color:#dc2626;">₹<?php echo number_format($total_expense,2); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="jewel-card p-5">
                <h3 class="gold-font text-base font-bold mb-4 income-title"><i class="fas fa-chart-pie mr-2"></i>Income by Category</h3>
                <canvas id="incomeChart" style="max-height:240px;"></canvas>
            </div>
            <div class="jewel-card p-5">
                <h3 class="gold-font text-base font-bold mb-4 expense-title"><i class="fas fa-chart-pie mr-2"></i>Expenses by Category</h3>
                <canvas id="expenseChart" style="max-height:240px;"></canvas>
            </div>
        </div>
    </div>

    <footer>
        <p class="text-xs" style="color:#7a4e0a;">
            &copy; 2026 MAA GOURI JEWELLERS &nbsp;|&nbsp; CRAFTED WITH ELEGANCE &nbsp;|&nbsp;
            Developed by <a href="https://saamparktechnologyresearch.in/" target="_blank" style="text-decoration:underline;color:#800020;">STR</a>
        </p>
    </footer>
</div><!-- end .page-wrapper -->

<style>
@media (max-width: 768px) { nav.nav-gold { margin-left: 0 !important; } }
footer { background: linear-gradient(0deg, #f5e6c8, #fdf6e3); border-top: 2px solid #d68b16; padding: 20px; text-align: center; margin-top: 40px; }
</style>

<script>
    /* ── Sidebar ── */
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

    /* ── Charts ── */
    const incomeLabels  = <?php echo json_encode(array_keys($income_by_cat)); ?>;
    const incomeData    = <?php echo json_encode(array_values($income_by_cat)); ?>;
    const expenseLabels = <?php echo json_encode(array_keys($expense_by_cat)); ?>;
    const expenseData   = <?php echo json_encode(array_values($expense_by_cat)); ?>;

    const goldPalette  = ['#d68b16','#b5730e','#7a4e0a','#f5c842','#c9a96e','#e8a020','#9a6010'];
    const redPalette   = ['#dc2626','#b91c1c','#9f1239','#ef4444','#f87171','#be123c','#7f1d1d'];

    const chartOpts = (legendColor) => ({
        responsive: true, maintainAspectRatio: true,
        plugins: {
            legend: { labels: { color: legendColor, font: { size: 11, family: 'Poppins' }, padding: 12, boxWidth: 12 } },
            tooltip: { backgroundColor: '#fdf6e3', titleColor: '#800020', bodyColor: '#7a4e0a', borderColor: '#d68b16', borderWidth: 1 }
        }
    });

    if(incomeData.length > 0) {
        new Chart(document.getElementById('incomeChart'), {
            type: 'pie',
            data: { labels: incomeLabels, datasets: [{ data: incomeData, backgroundColor: goldPalette, borderWidth: 2, borderColor: '#fff' }] },
            options: chartOpts('#166534')
        });
    } else {
        document.getElementById('incomeChart').parentElement.innerHTML += '<p style="text-align:center;color:#9ca3af;font-size:12px;margin-top:8px;">No data for this period</p>';
        document.getElementById('incomeChart').style.display = 'none';
    }

    if(expenseData.length > 0) {
        new Chart(document.getElementById('expenseChart'), {
            type: 'pie',
            data: { labels: expenseLabels, datasets: [{ data: expenseData, backgroundColor: redPalette, borderWidth: 2, borderColor: '#fff' }] },
            options: chartOpts('#9f1239')
        });
    } else {
        document.getElementById('expenseChart').parentElement.innerHTML += '<p style="text-align:center;color:#9ca3af;font-size:12px;margin-top:8px;">No data for this period</p>';
        document.getElementById('expenseChart').style.display = 'none';
    }
</script>
</body>
</html>