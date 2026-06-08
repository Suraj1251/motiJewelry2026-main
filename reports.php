<?php
session_start();
require_once 'config/database.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get daily sales for last 7 days
$daily_sales = [];
for($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $sales = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0) as total FROM invoices WHERE DATE(created_at) = '$date'"));
    $daily_sales[] = ['date' => date('d M', strtotime($date)), 'total' => $sales['total']];
}

// Get top products
$top_products = mysqli_query($conn, "SELECT p.name, SUM(ii.quantity) as sold FROM invoice_items ii JOIN products p ON ii.product_id = p.id GROUP BY ii.product_id ORDER BY sold DESC LIMIT 5");

// Payment Status Summary
$pay_summary = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN payment_status='paid'   THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN payment_status='part'   THEN 1 ELSE 0 END) as part_count,
        SUM(CASE WHEN payment_status='unpaid' THEN 1 ELSE 0 END) as unpaid_count,
        SUM(CASE WHEN payment_status='paid'   THEN total_amount ELSE 0 END) as paid_amt,
        SUM(CASE WHEN payment_status='part'   THEN total_amount ELSE 0 END) as part_amt,
        SUM(CASE WHEN payment_status='unpaid' THEN total_amount ELSE 0 END) as unpaid_amt,
        SUM(CASE WHEN payment_status='part'   THEN balance_amount ELSE 0 END) as part_balance,
        SUM(CASE WHEN payment_status='unpaid' THEN balance_amount ELSE 0 END) as unpaid_balance
    FROM invoices
"));

$paid_cust = mysqli_query($conn, "SELECT invoice_no, customer_name, customer_mobile, total_amount, created_at FROM invoices WHERE payment_status='paid' ORDER BY created_at DESC LIMIT 100");
$paid_rows = [];
while($r = mysqli_fetch_assoc($paid_cust)) $paid_rows[] = $r;

$part_customers = mysqli_query($conn, "SELECT invoice_no, customer_name, customer_mobile, total_amount, paid_amount, balance_amount, created_at FROM invoices WHERE payment_status='part' ORDER BY created_at DESC LIMIT 100");
$part_rows = [];
while($r = mysqli_fetch_assoc($part_customers)) $part_rows[] = $r;

$unpaid_customers = mysqli_query($conn, "SELECT invoice_no, customer_name, customer_mobile, total_amount, paid_amount, balance_amount, created_at FROM invoices WHERE payment_status='unpaid' ORDER BY created_at DESC LIMIT 100");
$unpaid_rows = [];
while($r = mysqli_fetch_assoc($unpaid_customers)) $unpaid_rows[] = $r;

// GST summary
$gst_month = mysqli_real_escape_string($conn, $_GET['gst_month'] ?? date('Y-m'));
$gst_month_where = $gst_month ? "WHERE DATE_FORMAT(created_at,'%Y-%m') = '$gst_month'" : '';
$gst_summary = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        SUM(CASE WHEN gst_type='gst'  THEN 1 ELSE 0 END) as gst_count,
        SUM(CASE WHEN gst_type!='gst' THEN 1 ELSE 0 END) as nongst_count,
        SUM(CASE WHEN gst_type='gst'  THEN total_amount ELSE 0 END) as gst_taxable_total,
        SUM(CASE WHEN gst_type!='gst' THEN total_amount ELSE 0 END) as nongst_amt,
        SUM(CASE WHEN gst_type='gst'  THEN gst_amount   ELSE 0 END) as actual_gst_collected
    FROM invoices $gst_month_where
"));

// Bills table with filters
$filter_from   = mysqli_real_escape_string($conn, $_GET['filter_from']   ?? '');
$filter_to     = mysqli_real_escape_string($conn, $_GET['filter_to']     ?? '');
$filter_name   = mysqli_real_escape_string($conn, trim($_GET['filter_name'] ?? ''));
$filter_status = mysqli_real_escape_string($conn, $_GET['filter_status'] ?? '');
$filter_gst    = mysqli_real_escape_string($conn, $_GET['filter_gst']    ?? '');

$where_clauses = [];
if($filter_from)   $where_clauses[] = "DATE(created_at) >= '$filter_from'";
if($filter_to)     $where_clauses[] = "DATE(created_at) <= '$filter_to'";
if($filter_name)   $where_clauses[] = "(customer_name LIKE '%$filter_name%' OR customer_mobile LIKE '%$filter_name%')";
if($filter_status) $where_clauses[] = "payment_status = '$filter_status'";
if($filter_gst === 'gst')     $where_clauses[] = "gst_type = 'gst'";
if($filter_gst === 'non_gst') $where_clauses[] = "gst_type != 'gst'";
$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// customer_gstin column exists directly in invoices table
$bills_result = mysqli_query($conn, "SELECT invoice_no, customer_name, customer_mobile, customer_address, customer_gstin, total_amount, paid_amount, balance_amount, payment_status, gst_type, gst_amount, subtotal, created_at FROM invoices $where_sql ORDER BY created_at DESC LIMIT 500");
$bills_rows = [];
while($r = mysqli_fetch_assoc($bills_result)) $bills_rows[] = $r;

$total_bills_amount = array_sum(array_column($bills_rows, 'total_amount'));
$total_bills_count  = count($bills_rows);

$logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Reports - Maa Gouri Jewellers</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
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
        .sidebar-logo img { width: 44px; height: 44px; object-fit: contain; border-radius: 50%; background: rgba(255,255,255,0.1); padding: 3px; flex-shrink: 0; }
        .sidebar-logo-text h2 { color: #fff; font-size: 13px; font-weight: 700; line-height: 1.3; font-family: 'Playfair Display', serif; letter-spacing: 0.5px; }
        .sidebar-logo-text p  { color: rgba(255,255,255,0.65); font-size: 10px; margin-top: 1px; }

        .sidebar-nav { flex: 1; padding: 10px 0; }
        .sidebar-section-label { padding: 10px 20px 4px; color: rgba(255,255,255,0.45); font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; }

        .sidebar-nav a {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 20px; color: rgba(255,255,255,0.85);
            text-decoration: none; font-size: 13px; font-weight: 500;
            transition: all 0.2s ease; border-left: 3px solid transparent;
            letter-spacing: 0.3px; position: relative;
        }
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
        .page-wrapper { margin-left: 240px; min-height: 100vh; transition: margin-left 0.35s ease; background: #F5F5F5; }

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

        .jewel-input { background: #fdf6e3; border: 1px solid rgba(181,115,14,0.3); color: #4a3000; border-radius: 10px; padding: 8px 12px; transition: all 0.3s ease; font-size: 13px; }
        .jewel-input:focus { border-color: #d68b16; box-shadow: 0 0 0 3px rgba(214,139,22,0.15); outline: none; }

        /* Section titles */
        .section-title { color: #800020; font-size: 1.2rem; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .section-title i { color: #d68b16; }

        /* Buttons */
        .btn-jewel { background: linear-gradient(135deg, #800020, #d68b16); border: none; border-radius: 10px; padding: 9px 22px; font-weight: 700; color: #fff; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; font-size: 13px; cursor: pointer; }
        .btn-jewel:hover { transform: scale(1.04); box-shadow: 0 8px 24px rgba(214,139,22,0.35); color: #fff; }

        .btn-excel { background: linear-gradient(135deg, #16a34a, #15803d); border: none; border-radius: 10px; padding: 9px 20px; font-weight: 700; color: #fff; transition: all 0.3s; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; }
        .btn-excel:hover { transform: scale(1.04); box-shadow: 0 8px 20px rgba(22,163,74,0.4); }

        /* Stat cards */
        .stat-card-paid   { background: linear-gradient(145deg, #f0fdf4, #dcfce7); border: 1px solid #86efac; border-radius: 16px; }
        .stat-card-part   { background: linear-gradient(145deg, #fff7ed, #fed7aa); border: 1px solid #fdba74; border-radius: 16px; }
        .stat-card-unpaid { background: linear-gradient(145deg, #fff1f2, #fecdd3); border: 1px solid #fca5a5; border-radius: 16px; }
        .stat-card-gst    { background: linear-gradient(145deg, #f0fdfa, #ccfbf1); border: 1px solid #5eead4; border-radius: 16px; }
        .stat-card-nongst { background: linear-gradient(145deg, #f8fafc, #f1f5f9); border: 1px solid #cbd5e1; border-radius: 16px; }

        /* Detail collapse */
        .detail-collapse { display: none; padding: 16px; border-radius: 12px; background: #fdf6e3; border: 1px solid rgba(181,115,14,0.2); margin-top: 8px; }
        .detail-collapse.open { display: block; }

        /* Table */
        .report-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .report-table th { background: linear-gradient(135deg, #7a4e0a, #d68b16); color: #fff; font-weight: 600; padding: 10px 8px; text-align: left; }
        .report-table td { padding: 9px 8px; border-bottom: 1px solid rgba(181,115,14,0.1); color: #3a2800; vertical-align: middle; }
        .report-table tbody tr:hover { background: #fdf6e3; }
        .report-table tbody tr:nth-child(even) { background: #fffbf0; }
        .report-table tbody tr:nth-child(even):hover { background: #fdf6e3; }

        /* Payment badges */
        .badge-paid   { background: #dcfce7; color: #166534; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .badge-part   { background: #fed7aa; color: #9a3412; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .badge-unpaid { background: #fecdd3; color: #9f1239; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }

        /* GST number tag */
        .gstin-tag {
            display: inline-block;
            margin-top: 3px;
            font-size: 10px;
            font-weight: 600;
            color: #0f766e;
            background: #ccfbf1;
            border: 1px solid #5eead4;
            border-radius: 6px;
            padding: 1px 7px;
            letter-spacing: 0.5px;
            font-family: monospace;
        }

        /* Progress bar */
        .progress-bar-bg { background: rgba(181,115,14,0.15); border-radius: 999px; height: 10px; }
        .progress-bar-fill { background: linear-gradient(90deg, #800020, #d68b16); height: 10px; border-radius: 999px; transition: width 0.6s ease; }

        /* Chart card */
        .chart-card { background: linear-gradient(145deg, #fdf6e3, #fff); border: 1px solid rgba(181,115,14,0.2); border-radius: 20px; padding: 20px; }

        /* Summary chips */
        .chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .chip-yellow { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .chip-green  { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .chip-teal   { background: #ccfbf1; color: #134e4a; border: 1px solid #5eead4; }
        .chip-gray   { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .chip-emerald{ background: #d1fae5; color: #064e3b; border: 1px solid #6ee7b7; }

        /* Toggle btn */
        .toggle-detail-btn { background: none; border: 1px solid rgba(181,115,14,0.4); color: #7a4e0a; border-radius: 20px; padding: 4px 14px; font-size: 11px; cursor: pointer; transition: all 0.2s; margin-top: 10px; display: inline-block; }
        .toggle-detail-btn:hover { background: rgba(214,139,22,0.1); }

        /* GST total box */
        .gst-total-box { background: linear-gradient(135deg, rgba(52,211,153,0.1), rgba(20,184,166,0.05)); border: 1px solid rgba(52,211,153,0.3); border-radius: 16px; padding: 16px 20px; }

        /* Filter form */
        .filter-label { font-size: 11px; font-weight: 600; color: #7a4e0a; margin-bottom: 4px; display: block; }

        /* Footer */
        footer { background: linear-gradient(0deg, #f5e6c8, #fdf6e3); border-top: 2px solid #d68b16; padding: 20px; text-align: center; margin-top: 40px; }

        @media (max-width: 640px) {
            .charts-grid { grid-template-columns: 1fr !important; }
            .stats-3col  { grid-template-columns: 1fr !important; }
            .bills-table-wrap { overflow-x: auto; }
            .report-table { min-width: 780px; }
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
        <a href="reports.php" class="active"><i class="fas fa-chart-bar"></i> REPORTS</a>
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
        <h1 class="gold-font"><i class="fas fa-chart-bar mr-2"></i> Business Reports</h1>
        <p>Sales analytics, payment status &amp; GST summary</p>
    </div>

    <div class="container mx-auto px-4 sm:px-6 py-6">

        <!-- ── CHARTS ROW ── -->
        <div class="charts-grid grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Sales Chart -->
            <div class="chart-card">
                <h3 class="section-title mb-4"><i class="fas fa-chart-line"></i> Last 7 Days Sales</h3>
                <canvas id="salesChart"></canvas>
            </div>

            <!-- Top Products -->
            <div class="chart-card">
                <h3 class="section-title mb-4"><i class="fas fa-trophy"></i> Top Selling Products</h3>
                <div class="space-y-4">
                    <?php
                    $products_array = [];
                    while($p = mysqli_fetch_assoc($top_products)) $products_array[] = $p;
                    if($products_array): $max_sold = max(array_column($products_array,'sold'));
                    foreach($products_array as $product): ?>
                    <div>
                        <div class="flex justify-between mb-1">
                            <span class="text-sm font-semibold" style="color:#800020;">💎 <?php echo htmlspecialchars($product['name']); ?></span>
                            <span class="text-sm font-bold" style="color:#d68b16;"><?php echo $product['sold']; ?> units</span>
                        </div>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width:<?php echo min(100, ($product['sold']/$max_sold)*100); ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; else: ?>
                    <div class="text-center py-12" style="color:#7a4e0a;opacity:0.5;">
                        <i class="fas fa-chart-simple text-4xl mb-3 block"></i>
                        No sales data yet.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── PAYMENT STATUS OVERVIEW ── -->
        <div class="jewel-card p-5 sm:p-6 mb-6">
            <h3 class="section-title mb-5"><i class="fas fa-wallet"></i> Payment Status Overview</h3>

            <div class="stats-3col grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                <!-- Paid -->
                <div class="stat-card-paid p-4">
                    <div class="flex justify-between items-start mb-2">
                        <span class="font-bold text-sm" style="color:#166534;">✅ Full Paid</span>
                        <span class="text-2xl font-black" style="color:#166534;"><?php echo $pay_summary['paid_count']; ?></span>
                    </div>
                    <p class="font-bold text-lg" style="color:#166534;">₹<?php echo number_format($pay_summary['paid_amt'],2); ?></p>
                    <p class="text-xs mt-1" style="color:#15803d;">Total collected (full payment)</p>
                    <button onclick="toggleDetail('detailPaid')" class="toggle-detail-btn">👁️ View Customers</button>
                </div>
                <!-- Part -->
                <div class="stat-card-part p-4">
                    <div class="flex justify-between items-start mb-2">
                        <span class="font-bold text-sm" style="color:#9a3412;">⏳ Part Payment</span>
                        <span class="text-2xl font-black" style="color:#9a3412;"><?php echo $pay_summary['part_count']; ?></span>
                    </div>
                    <p class="font-bold text-lg" style="color:#9a3412;">₹<?php echo number_format($pay_summary['part_amt'],2); ?></p>
                    <p class="text-xs mt-1" style="color:#c2410c;">⚠️ Balance: ₹<?php echo number_format($pay_summary['part_balance'],2); ?></p>
                    <button onclick="toggleDetail('detailPart')" class="toggle-detail-btn">👁️ View Customers</button>
                </div>
                <!-- Unpaid -->
                <div class="stat-card-unpaid p-4">
                    <div class="flex justify-between items-start mb-2">
                        <span class="font-bold text-sm" style="color:#9f1239;">❌ Advanced (Credit)</span>
                        <span class="text-2xl font-black" style="color:#9f1239;"><?php echo $pay_summary['unpaid_count']; ?></span>
                    </div>
                    <p class="font-bold text-lg" style="color:#9f1239;">₹<?php echo number_format($pay_summary['unpaid_amt'],2); ?></p>
                    <p class="text-xs mt-1" style="color:#be123c;">⚠️ Total due: ₹<?php echo number_format($pay_summary['unpaid_balance'],2); ?></p>
                    <button onclick="toggleDetail('detailUnpaid')" class="toggle-detail-btn">👁️ View Customers</button>
                </div>
            </div>

            <!-- Paid detail -->
            <div id="detailPaid" class="detail-collapse mb-3">
                <h4 class="font-bold text-sm mb-3" style="color:#166534;">✅ Full Paid Customers</h4>
                <?php if(empty($paid_rows)): ?>
                    <p class="text-sm" style="color:#7a4e0a;">No paid customers found.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="report-table">
                        <thead><tr>
                            <th>Customer</th><th>Mobile</th><th class="text-right">Amount</th><th class="text-center">Date</th><th class="text-center">Invoice</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach($paid_rows as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['customer_mobile']); ?></td>
                                <td class="text-right font-bold" style="color:#16a34a;">₹<?php echo number_format($r['total_amount'],2); ?></td>
                                <td class="text-center"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                                <td class="text-center font-mono text-xs" style="color:#d68b16;"><?php echo htmlspecialchars($r['invoice_no']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Part detail -->
            <div id="detailPart" class="detail-collapse mb-3">
                <h4 class="font-bold text-sm mb-3" style="color:#c2410c;">⏳ Part Payment — Balance Remaining</h4>
                <?php if(empty($part_rows)): ?>
                    <p class="text-sm" style="color:#7a4e0a;">No part payment customers found.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="report-table">
                        <thead><tr>
                            <th>Customer</th><th>Mobile</th><th class="text-right">Bill Total</th><th class="text-right">Paid</th><th class="text-right">⚠️ Balance</th><th class="text-center">Date</th><th class="text-center">Invoice</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach($part_rows as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['customer_mobile']); ?></td>
                                <td class="text-right font-bold" style="color:#d97706;">₹<?php echo number_format($r['total_amount'],2); ?></td>
                                <td class="text-right" style="color:#16a34a;">₹<?php echo number_format($r['paid_amount'],2); ?></td>
                                <td class="text-right font-bold" style="color:#dc2626;">₹<?php echo number_format($r['balance_amount'],2); ?></td>
                                <td class="text-center"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                                <td class="text-center font-mono text-xs" style="color:#d68b16;"><?php echo htmlspecialchars($r['invoice_no']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Unpaid detail -->
            <div id="detailUnpaid" class="detail-collapse">
                <h4 class="font-bold text-sm mb-3" style="color:#be123c;">❌ Unpaid — Full Payment Pending</h4>
                <?php if(empty($unpaid_rows)): ?>
                    <p class="text-sm" style="color:#16a34a;">No unpaid customers! 🎉</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="report-table">
                        <thead><tr>
                            <th>Customer</th><th>Mobile</th><th class="text-right">Bill Total</th><th class="text-right">⚠️ Due</th><th class="text-center">Date</th><th class="text-center">Invoice</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach($unpaid_rows as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['customer_mobile']); ?></td>
                                <td class="text-right font-bold" style="color:#d97706;">₹<?php echo number_format($r['total_amount'],2); ?></td>
                                <td class="text-right font-bold" style="color:#dc2626;">₹<?php echo number_format($r['balance_amount'],2); ?></td>
                                <td class="text-center"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                                <td class="text-center font-mono text-xs" style="color:#d68b16;"><?php echo htmlspecialchars($r['invoice_no']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── GST SUMMARY ── -->
        <div class="jewel-card p-5 sm:p-6 mb-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-5 gap-3">
                <h3 class="section-title"><i class="fas fa-receipt"></i> GST Summary</h3>
                <form method="GET" class="flex items-center gap-2 flex-wrap">
                    <?php foreach(['filter_from','filter_to','filter_name','filter_status','filter_gst'] as $fk) {
                        if(!empty($_GET[$fk])) echo '<input type="hidden" name="'.$fk.'" value="'.htmlspecialchars($_GET[$fk]).'">';
                    } ?>
                    <label class="filter-label whitespace-nowrap">📅 Month:</label>
                    <input type="month" name="gst_month" value="<?php echo htmlspecialchars($gst_month); ?>" class="jewel-input" style="width:160px;">
                    <button type="submit" class="btn-jewel" style="padding:8px 16px;font-size:12px;">Go</button>
                    <a href="reports.php" class="btn-jewel" style="padding:8px 14px;font-size:12px;background:linear-gradient(135deg,#6b7280,#4b5563);">All Time</a>
                </form>
            </div>

            <?php
            $actual_gst = floatval($gst_summary['actual_gst_collected'] ?? 0);
            $taxable    = floatval($gst_summary['gst_taxable_total'] ?? 0);
            $cgst = $actual_gst / 2;
            $sgst = $actual_gst / 2;
            if($actual_gst <= 0 && $taxable > 0) {
                $actual_gst = round($taxable * 0.03, 2);
                $cgst = round($taxable * 0.015, 2);
                $sgst = round($taxable * 0.015, 2);
            }
            $month_label = $gst_month ? date('F Y', strtotime($gst_month.'-01')) : 'All Time';
            ?>

            <p class="text-xs mb-4" style="color:#7a4e0a;">📌 Showing data for: <strong style="color:#800020;"><?php echo $month_label; ?></strong></p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <!-- GST Card -->
                <div class="stat-card-gst p-5">
                    <div class="flex justify-between items-center mb-3">
                        <span class="font-bold text-sm" style="color:#134e4a;">📄 GST Bills</span>
                        <span class="text-2xl font-black" style="color:#0f766e;"><?php echo $gst_summary['gst_count']; ?> bills</span>
                    </div>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between py-1" style="border-bottom:1px solid rgba(20,184,166,0.2);">
                            <span style="color:#0d9488;">💰 Taxable Amount</span>
                            <strong style="color:#134e4a;">₹<?php echo number_format($taxable,2); ?></strong>
                        </div>
                        <div class="flex justify-between py-1" style="border-bottom:1px solid rgba(20,184,166,0.2);">
                            <span style="color:#0d9488;">📊 CGST (1.5%)</span>
                            <strong style="color:#0f766e;">₹<?php echo number_format($cgst,2); ?></strong>
                        </div>
                        <div class="flex justify-between py-1" style="border-bottom:1px solid rgba(20,184,166,0.2);">
                            <span style="color:#0d9488;">📊 SGST (1.5%)</span>
                            <strong style="color:#0f766e;">₹<?php echo number_format($sgst,2); ?></strong>
                        </div>
                        <div class="flex justify-between py-2 px-3 rounded-xl mt-1" style="background:rgba(52,211,153,0.15);">
                            <span class="font-bold" style="color:#134e4a;">✅ Total GST</span>
                            <strong class="text-lg" style="color:#0f766e;">₹<?php echo number_format($actual_gst,2); ?></strong>
                        </div>
                    </div>
                </div>
                <!-- Non-GST Card -->
                <div class="stat-card-nongst p-5">
                    <div class="flex justify-between items-center mb-3">
                        <span class="font-bold text-sm" style="color:#475569;">📋 Non-GST Bills</span>
                        <span class="text-2xl font-black" style="color:#334155;"><?php echo $gst_summary['nongst_count']; ?> bills</span>
                    </div>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between py-1" style="border-bottom:1px solid #e2e8f0;">
                            <span style="color:#64748b;">💰 Total Sale Amount</span>
                            <strong style="color:#334155;">₹<?php echo number_format($gst_summary['nongst_amt'],2); ?></strong>
                        </div>
                        <div class="flex justify-between py-1" style="border-bottom:1px solid #e2e8f0;">
                            <span style="color:#64748b;">📊 Tax Rate</span>
                            <strong style="color:#475569;">0% (No GST)</strong>
                        </div>
                        <div class="flex justify-between py-2 px-3 rounded-xl mt-1" style="background:rgba(100,116,139,0.1);">
                            <span class="font-bold" style="color:#475569;">❌ GST Collected</span>
                            <strong class="text-lg" style="color:#334155;">₹0.00</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="gst-total-box">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <p class="font-bold text-sm" style="color:#0d9488;">🏛️ Total GST Payable to Govt — <?php echo $month_label; ?></p>
                        <p class="text-xs mt-1" style="color:#14b8a6;">CGST ₹<?php echo number_format($cgst,2); ?> + SGST ₹<?php echo number_format($sgst,2); ?></p>
                    </div>
                    <p class="text-2xl font-black" style="color:#0f766e;">₹<?php echo number_format($actual_gst,2); ?></p>
                </div>
            </div>
        </div>

        <!-- ── ALL BILLS TABLE ── -->
        <div class="jewel-card p-5 sm:p-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-5 gap-3">
                <h3 class="section-title"><i class="fas fa-file-invoice"></i> All Bills Record</h3>
                <button onclick="downloadExcel()" class="btn-excel">
                    <i class="fas fa-file-excel"></i> Download Excel (.xlsx)
                </button>
            </div>

            <!-- Filter bar -->
            <form method="GET" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-5 p-4 rounded-xl" style="background:#fdf6e3;border:1px solid rgba(181,115,14,0.15);">
                <div>
                    <label class="filter-label">📅 From Date</label>
                    <input type="date" name="filter_from" value="<?php echo htmlspecialchars($filter_from); ?>" class="jewel-input w-full">
                </div>
                <div>
                    <label class="filter-label">📅 To Date</label>
                    <input type="date" name="filter_to" value="<?php echo htmlspecialchars($filter_to); ?>" class="jewel-input w-full">
                </div>
                <div>
                    <label class="filter-label">🔍 Name / Mobile</label>
                    <input type="text" name="filter_name" value="<?php echo htmlspecialchars($filter_name); ?>" placeholder="Search…" class="jewel-input w-full">
                </div>
                <div>
                    <label class="filter-label">💳 Payment</label>
                    <select name="filter_status" class="jewel-input w-full">
                        <option value="" <?php if(!$filter_status) echo 'selected'; ?>>All</option>
                        <option value="paid"   <?php if($filter_status==='paid')   echo 'selected'; ?>>✅ Paid</option>
                        <option value="part"   <?php if($filter_status==='part')   echo 'selected'; ?>>⏳ Part</option>
                        <option value="unpaid" <?php if($filter_status==='unpaid') echo 'selected'; ?>>❌ Unpaid</option>
                    </select>
                </div>
                <div>
                    <label class="filter-label">🧾 GST Type</label>
                    <select name="filter_gst" class="jewel-input w-full">
                        <option value="" <?php if(!$filter_gst) echo 'selected'; ?>>All</option>
                        <option value="gst"     <?php if($filter_gst==='gst')     echo 'selected'; ?>>📄 GST (3%)</option>
                        <option value="non_gst" <?php if($filter_gst==='non_gst') echo 'selected'; ?>>📋 Non-GST</option>
                    </select>
                </div>
                <div class="col-span-2 sm:col-span-3 lg:col-span-5 flex gap-3">
                    <button type="submit" class="btn-jewel" style="padding:8px 20px;font-size:12px;">🔍 Filter</button>
                    <a href="reports.php" class="btn-jewel" style="padding:8px 16px;font-size:12px;background:linear-gradient(135deg,#6b7280,#4b5563);">✖ Clear</a>
                </div>
            </form>

            <!-- Summary chips -->
            <div class="flex flex-wrap gap-2 mb-4">
                <span class="chip chip-yellow">Total Bills: <strong><?php echo $total_bills_count; ?></strong></span>
                <span class="chip chip-green">Total Amount: <strong>₹<?php echo number_format($total_bills_amount,2); ?></strong></span>
                <?php
                $gst_filtered    = count(array_filter($bills_rows, fn($b)=>$b['gst_type']==='gst'));
                $nongst_filtered = count(array_filter($bills_rows, fn($b)=>$b['gst_type']!=='gst'));
                $total_gst_collected = array_sum(array_column(array_filter($bills_rows, fn($b)=>$b['gst_type']==='gst'), 'gst_amount'));
                $total_cgst = round($total_gst_collected/2, 2);
                $total_sgst = round($total_gst_collected/2, 2);
                ?>
                <span class="chip chip-teal">GST Bills: <strong><?php echo $gst_filtered; ?></strong></span>
                <span class="chip chip-gray">Non-GST: <strong><?php echo $nongst_filtered; ?></strong></span>
                <?php if($total_gst_collected > 0): ?>
                <span class="chip chip-emerald">Total GST: <strong>₹<?php echo number_format($total_gst_collected,2); ?></strong>
                    <span style="font-size:10px;opacity:0.7;">(CGST ₹<?php echo number_format($total_cgst,2); ?> + SGST ₹<?php echo number_format($total_sgst,2); ?>)</span>
                </span>
                <?php endif; ?>
            </div>

            <!-- Table -->
            <div class="bills-table-wrap overflow-x-auto rounded-xl" style="border:1px solid rgba(181,115,14,0.15);">
                <table id="billsTable" class="report-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Invoice No</th>
                            <th>Customer</th>
                            <th>Mobile</th>
                            <th class="text-right">Amount (₹)</th>
                            <th class="text-right">Paid</th>
                            <th class="text-right">Balance</th>
                            <th class="text-center">GST</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Date</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($bills_rows)): ?>
                        <tr><td colspan="11" class="text-center py-10" style="color:#7a4e0a;">No bills found.</td></tr>
                    <?php else: foreach($bills_rows as $i => $bill):
                        $balance   = floatval($bill['balance_amount'] ?? 0);
                        $paid_show = floatval($bill['paid_amount'] ?? 0);
                        $gst_amt   = floatval($bill['gst_amount'] ?? 0);
                        $cgst_amt  = round($gst_amt/2, 2);
                        $sgst_amt  = round($gst_amt/2, 2);
                        $gstin     = trim($bill['customer_gstin'] ?? '');
                        $status_badge = match($bill['payment_status']) {
                            'paid'   => '<span class="badge-paid">✅ Paid</span>',
                            'part'   => '<span class="badge-part">⏳ Part</span>',
                            'unpaid' => '<span class="badge-unpaid">❌ Unpaid</span>',
                            default  => htmlspecialchars($bill['payment_status']),
                        };
                    ?>
                    <tr>
                        <td style="color:#7a4e0a;"><?php echo $i+1; ?></td>
                        <td class="font-mono text-xs" style="color:#d68b16;"><?php echo htmlspecialchars($bill['invoice_no']); ?></td>

                        <!-- ★ UPDATED: Customer name + GSTIN tag below -->
                        <td>
                            <span class="font-semibold" style="color:#800020;"><?php echo htmlspecialchars($bill['customer_name']); ?></span>
                            <?php if($gstin !== ''): ?>
                                <br><span class="gstin-tag">🧾 <?php echo htmlspecialchars($gstin); ?></span>
                            <?php endif; ?>
                        </td>

                        <td style="color:#6b7280;"><?php echo htmlspecialchars($bill['customer_mobile']); ?></td>
                        <td class="text-right font-bold" style="color:#16a34a;">₹<?php echo number_format($bill['total_amount'],2); ?></td>
                        <td class="text-right" style="color:#15803d;">₹<?php echo number_format($paid_show,2); ?></td>
                        <td class="text-right font-bold" style="color:<?php echo $balance>0?'#dc2626':'#9ca3af'; ?>">
                            <?php echo $balance>0 ? '₹'.number_format($balance,2) : '—'; ?>
                        </td>
                        <td class="text-center">
                            <?php if($bill['gst_type']==='gst'): ?>
                                <span style="color:#0d9488;font-weight:700;font-size:11px;">📄 GST</span>
                                <?php if($gst_amt>0): ?>
                                    <div style="font-size:9px;color:#14b8a6;">Total: ₹<?php echo number_format($gst_amt,2); ?></div>
                                    <div style="font-size:9px;color:#14b8a6;">C+S: ₹<?php echo number_format($cgst_amt,2); ?> ea</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#9ca3af;font-size:11px;">📋 Non-GST</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?php echo $status_badge; ?></td>
                        <td class="text-center text-xs" style="color:#6b7280;white-space:nowrap;"><?php echo date('d M Y', strtotime($bill['created_at'])); ?></td>
                        <td class="text-center">
                            <a href="view_pdf.php?invoice_no=<?php echo urlencode($bill['invoice_no']); ?>" target="_blank"
                               class="btn-jewel" style="padding:3px 10px;font-size:10px;border-radius:20px;">
                               🖨️ Print
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
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

    /* ── Toggle detail tables ── */
    function toggleDetail(id) {
        document.getElementById(id).classList.toggle('open');
    }

    /* ── Chart ── */
    const labels    = <?php echo json_encode(array_column($daily_sales,'date')); ?>;
    const salesData = <?php echo json_encode(array_column($daily_sales,'total')); ?>;
    new Chart(document.getElementById('salesChart').getContext('2d'), {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Sales (₹)', data: salesData,
                borderColor: '#d68b16', backgroundColor: 'rgba(214,139,22,0.1)',
                borderWidth: 3, tension: 0.4, fill: true,
                pointRadius: 5, pointHoverRadius: 8,
                pointBackgroundColor: '#d68b16', pointBorderColor: '#800020',
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: {
                legend: { labels: { font: { size: 12, weight: 'bold' }, color: '#800020' } },
                tooltip: { backgroundColor: '#fdf6e3', titleColor: '#800020', bodyColor: '#7a4e0a', borderColor: '#d68b16', borderWidth: 1,
                    callbacks: { label: ctx => '💰 ₹ ' + ctx.raw.toLocaleString('en-IN') }
                }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(181,115,14,0.1)' },
                    ticks: { callback: v => '₹' + v.toLocaleString('en-IN'), color:'#7a4e0a', font:{size:11} } },
                x: { grid: { color: 'rgba(181,115,14,0.1)' },
                    ticks: { color:'#7a4e0a', font:{size:11} } }
            }
        }
    });

    /* ── Excel Export ── */
    const billsData = <?php echo json_encode($bills_rows); ?>;

    function downloadExcel() {
        if (!billsData || billsData.length === 0) { alert('No bills found to export!'); return; }
        const today     = '<?php echo date("d M Y"); ?>';
        const todayFile = '<?php echo date("Y-m-d"); ?>';
        const inrFmt  = v => '₹' + parseFloat(v||0).toLocaleString('en-IN', {minimumFractionDigits:2});
        const fmtDate = raw => {
            if (!raw) return '';
            const [yr, mo, dy] = raw.split(' ')[0].split('-');
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return `${dy}-${months[parseInt(mo)-1]}-${yr.slice(2)}`;
        };
        const totalAmt     = billsData.reduce((s,b) => s + parseFloat(b.total_amount||0), 0);
        const totalPaid    = billsData.reduce((s,b) => s + parseFloat(b.paid_amount||0), 0);
        const totalBalance = billsData.reduce((s,b) => s + parseFloat(b.balance_amount||0), 0);
        const totalGST     = billsData.reduce((s,b) => s + parseFloat(b.gst_amount||0), 0);
        const gstBills     = billsData.filter(b => b.gst_type === 'gst').length;
        const nonGstBills  = billsData.filter(b => b.gst_type !== 'gst').length;
        const paidBills    = billsData.filter(b => b.payment_status === 'paid').length;
        const partBills    = billsData.filter(b => b.payment_status === 'part').length;
        const unpaidBills  = billsData.filter(b => b.payment_status === 'unpaid').length;

        const wb = XLSX.utils.book_new();

        // Sheet 1 — All Bills
        const aoa1 = [];
        aoa1.push(['💎 MAA GOURI JEWELLERS', '', '', '', '', '', '', '', '', '', '', '']);
        aoa1.push(['All Bills Report — Generated: ' + today, '', '', '', '', '', '', '', '', '', '', '']);
        aoa1.push([]);
        aoa1.push(['Total Bills', billsData.length, '', 'Total Amount', inrFmt(totalAmt), '', 'Total GST Collected', inrFmt(totalGST), '', 'Balance Due', inrFmt(totalBalance), '']);
        aoa1.push(['Full Paid', paidBills, '', 'Part Payment', partBills, '', 'Unpaid', unpaidBills, '', 'GST Bills', gstBills, '']);
        aoa1.push([]);
        // ★ UPDATED: Added GSTIN column header
        aoa1.push(['#', 'Invoice No', 'Customer Name', 'GSTIN', 'Mobile', 'Address', 'Total Amount (₹)', 'Paid Amount (₹)', 'Balance Due (₹)', 'GST Type', 'GST Amount (₹)', 'Payment Status', 'Date']);
        billsData.forEach((b, i) => {
            aoa1.push([
                i + 1,
                b.invoice_no,
                b.customer_name,
                b.customer_gstin || '',   // ★ GSTIN column
                b.customer_mobile,
                b.customer_address || '',
                parseFloat(b.total_amount||0),
                parseFloat(b.paid_amount||0),
                parseFloat(b.balance_amount||0),
                b.gst_type === 'gst' ? 'GST (3%)' : 'Non-GST',
                parseFloat(b.gst_amount||0),
                b.payment_status === 'paid' ? 'Full Paid' : b.payment_status === 'part' ? 'Part Payment' : 'Unpaid',
                fmtDate(b.created_at)
            ]);
        });
        aoa1.push(['', 'TOTAL', '', '', '', '', billsData.reduce((s,b)=>s+parseFloat(b.total_amount||0),0), billsData.reduce((s,b)=>s+parseFloat(b.paid_amount||0),0), totalBalance, '', totalGST, '', '']);

        const ws1 = XLSX.utils.aoa_to_sheet(aoa1);
        ws1['!cols'] = [{wch:5},{wch:20},{wch:22},{wch:20},{wch:14},{wch:28},{wch:18},{wch:18},{wch:18},{wch:12},{wch:16},{wch:14},{wch:14}];
        ws1['!merges'] = [{s:{r:0,c:0},e:{r:0,c:12}},{s:{r:1,c:0},e:{r:1,c:12}}];
        XLSX.utils.book_append_sheet(wb, ws1, 'All Bills');

        // Sheet 2 — Payment Summary
        const aoa2 = [];
        aoa2.push(['💎 MAA GOURI JEWELLERS — Payment Summary']);
        aoa2.push(['Generated: ' + today]);
        aoa2.push([]);
        aoa2.push(['Category', 'Count', 'Amount (₹)']);
        aoa2.push(['Full Paid', paidBills, totalPaid]);
        aoa2.push(['Part Payment', partBills, billsData.filter(b=>b.payment_status==='part').reduce((s,b)=>s+parseFloat(b.total_amount||0),0)]);
        aoa2.push(['Unpaid (Credit)', unpaidBills, billsData.filter(b=>b.payment_status==='unpaid').reduce((s,b)=>s+parseFloat(b.total_amount||0),0)]);
        aoa2.push(['TOTAL', billsData.length, totalAmt]);
        aoa2.push([]);
        aoa2.push(['Balance Due', '', totalBalance]);
        aoa2.push(['Total GST Collected', '', totalGST]);
        aoa2.push(['CGST (1.5%)', '', totalGST/2]);
        aoa2.push(['SGST (1.5%)', '', totalGST/2]);
        const ws2 = XLSX.utils.aoa_to_sheet(aoa2);
        ws2['!cols'] = [{wch:28},{wch:12},{wch:20}];
        XLSX.utils.book_append_sheet(wb, ws2, 'Payment Summary');

        // Sheet 3 — Pending
        const pendingData = billsData.filter(b => b.payment_status !== 'paid' && parseFloat(b.balance_amount||0) > 0);
        // ★ UPDATED: Added GSTIN column in pending sheet too
        const aoa3 = [['#','Invoice No','Customer','GSTIN','Mobile','Bill Total (₹)','Paid (₹)','Balance Due (₹)','Status','Date']];
        pendingData.forEach((b,i) => aoa3.push([
            i+1, b.invoice_no, b.customer_name, b.customer_gstin||'', b.customer_mobile,
            parseFloat(b.total_amount||0), parseFloat(b.paid_amount||0), parseFloat(b.balance_amount||0),
            b.payment_status==='part'?'Part Payment':'Unpaid', fmtDate(b.created_at)
        ]));
        if(!pendingData.length) aoa3.push(['','No pending payments! 🎉','','','','','','','','']);
        const ws3 = XLSX.utils.aoa_to_sheet(aoa3);
        ws3['!cols'] = [{wch:5},{wch:20},{wch:22},{wch:20},{wch:14},{wch:18},{wch:16},{wch:18},{wch:14},{wch:14}];
        XLSX.utils.book_append_sheet(wb, ws3, 'Pending Payments');

        XLSX.writeFile(wb, `MaaGouriJewellers_Report_${todayFile}.xlsx`);
    }
</script>
</body>
</html>