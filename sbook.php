<?php
session_start();
require_once 'config/database.php';

$is_logged_in = isset($_SESSION['user_id']);
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Karigari Payment | Moti Jewellers</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');

        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3,.gold-font { font-family: 'Playfair Display', serif; }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
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
            width: 44px;
            height: 44px;
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

        .sidebar-nav {
            flex: 1;
            padding: 10px 0;
        }

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
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 60%;
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

        .sidebar-user-info i {
            color: rgba(255,255,255,0.9);
            font-size: 26px;
            flex-shrink: 0;
        }

        .sidebar-user-info .user-details p {
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.3;
        }

        .sidebar-user-info .user-details span {
            color: rgba(255,255,255,0.55);
            font-size: 10px;
        }

        .sidebar-logout,
        .sidebar-login-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.2s;
            letter-spacing: 0.5px;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .sidebar-logout {
            background: rgba(239,68,68,0.75);
            color: #fff;
            border-color: rgba(239,68,68,0.4);
        }

        .sidebar-logout:hover { background: #ef4444; }
        .sidebar-login-btn { background: rgba(255,255,255,0.2); color: #fff; }
        .sidebar-login-btn:hover { background: rgba(255,255,255,0.3); }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            backdrop-filter: blur(2px);
        }

        .sidebar-overlay.active { display: block; }

        .page-wrapper { margin-left: 240px; min-height: 100vh; transition: margin-left 0.35s ease; }

        nav.nav-gold {
            background: linear-gradient(135deg, #b5730e, #d68b16) !important;
            margin-left: 0;
        }

        nav.nav-gold h1,
        nav.nav-gold p,
        nav.nav-gold span { color: #ffffff !important; }

        .burger-menu {
            width: 28px;
            height: 20px;
            position: relative;
            cursor: pointer;
        }

        .burger-menu span {
            display: block;
            position: absolute;
            height: 3px;
            width: 100%;
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

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .page-wrapper { margin-left: 0 !important; }
            .mobile-burger { display: block !important; }
        }

        @media (min-width: 769px) { .mobile-burger { display: none !important; } }

        .hero-with-logo { text-align: center; }
        .typing-text { background: linear-gradient(135deg, #800020, #c9a96e, #d68b16); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-family: 'Playfair Display', serif; }
        .cursor { display: inline-block; width: 3px; height: 1em; background: #d68b16; margin-left: 4px; vertical-align: middle; animation: blink 0.8s infinite; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }

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

        .form-card {
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 25px 80px rgba(0,0,0,0.08);
            border: 1px solid rgba(214,139,22,0.12);
        }

        .form-input {
            width: 100%;
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid rgba(148,163,184,0.25);
            background: #fbfaf8;
            color: #334155;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-input:focus { border-color: #d68b16; box-shadow: 0 0 0 4px rgba(214,139,22,0.1); }
        .form-label { color: #7a4e0a; font-weight: 600; }
        .required { color: #dc2626; }

        body.light-theme { background:#F5F5F5; }
        body.dark-theme { background:#201d1b; color:#f8fafc; }
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

<div id="loadingOverlay" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999;display:flex;justify-content:center;align-items:center;overflow:hidden;transition:opacity 0.6s ease,visibility 0.6s ease;background:radial-gradient(ellipse at 50% 60%, #1a0a00 0%, #0d0500 100%);">
    <div style="position:absolute;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 3px,rgba(214,139,22,0.015) 3px,rgba(214,139,22,0.015) 4px);pointer-events:none;z-index:1;"></div>
    <div style="position:absolute;top:28px;left:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite;">✦</div>
    <div style="position:absolute;top:28px;right:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 1s;">✦</div>
    <div style="position:absolute;bottom:28px;left:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 2s;">✦</div>
    <div style="position:absolute;bottom:28px;right:28px;color:rgba(214,139,22,0.18);font-size:72px;animation:ornFloat 4s ease-in-out infinite 3s;">✦</div>
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
    </style>
</div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="sidebar" id="mainSidebar">
    <div class="sidebar-logo">
        <?php
        $logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
        $logo_found = false;
        foreach($logo_paths as $path) {
            if(file_exists($path)) {
                echo '<img src="'.$path.'" alt="Moti Jewellers Logo">';
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

    <nav class="sidebar-nav">
        <div class="sidebar-section-label">Main Menu</div>
        <a href="index.php">
            <i class="fas fa-home"></i> HOME
        </a>
        <a href="billing.php">
            <i class="fas fa-receipt"></i> BILLING
        </a>
        <a href="stock.php">
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
            <i class="fas fa-chart-line"></i> INCOME & EXP
        </a>
        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Tools</div>
        <a href="whatsapp_automation.php">
            <i class="fab fa-whatsapp"></i> WHATSAPP
        </a>
        <a href="sbook.php" class="active">
            <i class="fas fa-book"></i> karigori
        </a>
    </nav>

    <div class="sidebar-user">
        <?php if($is_logged_in): ?>
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
        <?php else: ?>
        <a href="login.php" class="sidebar-login-btn">
            <i class="fas fa-sign-in-alt"></i> LOGIN
        </a>
        <?php endif; ?>
    </div>
</div>

<nav class="nav-gold shadow-lg sticky top-0 z-50" style="margin-left:240px;">
    <div class="container mx-auto px-4 sm:px-6 py-3 sm:py-4">
        <div class="flex justify-between items-center">
            <div></div>
            <div class="ml-auto flex items-center gap-4">
                <?php if($is_logged_in): ?>
                <span class="text-sm font-medium text-white">
                    <i class="fas fa-user mr-1"></i>
                    <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                </span>
                <?php endif; ?>
                <div class="mobile-burger" style="display:none;">
                    <div class="burger-menu" id="burgerMenu" onclick="toggleSidebar()">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="page-wrapper">
    <section class="hero-with-logo py-12 sm:py-16 md:py-20 relative" style="background:linear-gradient(135deg, #fdf6e3 0%, #f5ead0 50%, #fdf6e3 100%);">
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
                if(!$logo_found) echo '<i class="fas fa-gem" style="font-size:80px;color:#d68b16;"></i>';
                ?>
            </div>

            <h1 class="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-bold mt-4 mb-4" style="min-height:1.2em;">
                <span id="typingText" class="typing-text"></span><span class="cursor"></span>
            </h1>

            <p class="text-base sm:text-lg md:text-xl mb-8 max-w-2xl mx-auto" style="color:#7a4e0a;">
                Register Karigar payments quickly and professionally.
            </p>

            <div class="hero-buttons flex flex-col sm:flex-row justify-center space-y-3 sm:space-y-0 sm:space-x-6">
                <a href="billing.php" class="btn-jewel"><i class="fas fa-receipt mr-2"></i> START BILLING</a>
                <a href="stock.php" class="btn-jewel" style="background:linear-gradient(135deg,#7a4e0a,#d68b16);"><i class="fas fa-boxes mr-2"></i> VIEW STOCK</a>
            </div>
        </div>
    </section>

    <div class="container mx-auto px-4 sm:px-6 py-10">
        <div class="form-card p-8 sm:p-10 mx-auto max-w-4xl">
            <div class="mb-8 text-center">
                <h2 class="text-3xl font-bold" style="color:#800020;">Karigari Payment</h2>
                <p class="mt-2 text-sm text-gray-600">Enter karigar details below to register a new payment record.</p>
            </div>

            <form action="sbook_save.php" method="POST">
                <div class="grid gap-6 md:grid-cols-2">
                    <div>
                        <label class="form-label">Karigar Name <span class="required">*</span></label>
                        <input type="text" name="name" class="form-input mt-2" required>
                    </div>
                    <div>
                        <label class="form-label">Karigar ID <span class="required">*</span></label>
                        <input type="text" name="customer_id" class="form-input mt-2" required>
                    </div>
                    <div>
                        <label class="form-label">Phone Number <span class="required">*</span></label>
                        <input type="text" name="phone" class="form-input mt-2" maxlength="10" required>
                    </div>
                    <div>
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input mt-2">
                    </div>
                    <div class="md:col-span-2">
                        <label class="form-label">Address</label>
                        <textarea name="address" rows="4" class="form-input mt-2"></textarea>
                    </div>
                </div>

                <div class="mt-8 text-right">
                    <button type="submit" class="btn-jewel">Register Karigar Payment</button>
                </div>
            </form>
        </div>
    </div>

    <footer class="footer-jewel py-10 mt-4" style="background:linear-gradient(0deg, #f5e6c8, #fdf6e3); border-top: 2px solid #d68b16; margin-left:240px;">
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
            </div>
        </div>
    </footer>
</div>

</body>
</html>
