<?php
session_start();
require_once 'config/database.php';

$error = '';
$success = '';

// ─── LOGIN ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_otp'])) {
    $email    = mysqli_real_escape_string($conn, trim($_POST['email']));
    $password = $_POST['password'];

    $check = mysqli_query($conn, "SELECT * FROM users WHERE email = '$email'");
    if (mysqli_num_rows($check) > 0) {
        $row = mysqli_fetch_assoc($check);
        if (password_verify($password, $row['password'])) {
            $_SESSION['user_id']     = $row['id'];
            $_SESSION['user_name']   = $row['name'];
            $_SESSION['user_mobile'] = $row['mobile'];
            $_SESSION['user_email']  = $email;
            header("Location: index.php");
            exit();
        } else {
            $error = "Email or Password is incorrect!";
        }
    } else {
        $error = "Email or Password is incorrect!";
    }
}

// ─── VERIFY OTP ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify_otp'])) {
    $entered_otp = trim($_POST['otp']);
    if (!isset($_SESSION['otp_sent']) || !$_SESSION['otp_sent']) {
        $error = "Please request an OTP first.";
    } elseif (strtotime($_SESSION['otp_expires']) < time()) {
        $error = "OTP has expired! Please try again.";
        unset($_SESSION['otp'], $_SESSION['otp_sent'], $_SESSION['otp_expires']);
    } elseif ($entered_otp == $_SESSION['otp']) {
        $_SESSION['user_id']     = $_SESSION['otp_user_id'];
        $_SESSION['user_name']   = $_SESSION['otp_user_name'];
        $_SESSION['user_mobile'] = $_SESSION['otp_user_mobile'];
        $_SESSION['user_email']  = $_SESSION['otp_email'];
        unset($_SESSION['otp'], $_SESSION['otp_sent'], $_SESSION['otp_expires'],
              $_SESSION['otp_email'], $_SESSION['otp_user_id'],
              $_SESSION['otp_user_name'], $_SESSION['otp_user_mobile']);
        header("Location: index.php");
        exit();
    } else {
        $error = "Incorrect OTP! Please check again.";
    }
}

// ─── RESEND OTP ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['resend_otp'])) {
    unset($_SESSION['otp'], $_SESSION['otp_sent'], $_SESSION['otp_expires'],
          $_SESSION['otp_email'], $_SESSION['otp_user_id'],
          $_SESSION['otp_user_name'], $_SESSION['otp_user_mobile']);
    $success = "Please enter your credentials again to request a new OTP.";
}

$otpSent = isset($_SESSION['otp_sent']) && $_SESSION['otp_sent'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Login — MAA GOURI JEWELLERS</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --gold:      #d68b16;
            --gold-dark: #b5730e;
            --gold-deep: #7a4e0a;
            --crimson:   #800020;
            --cream:     #fdf6e3;
            --cream2:    #f5ead0;
            --text-dark: #3a2800;
            --text-mid:  #7a4e0a;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', sans-serif;
            background: #1f1109;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            position: relative;
            overflow: hidden;
        }

        /* ── Subtle gem-like background animation ── */
        body::before {
            content: '';
            position: fixed; inset: 0;
            background:
                radial-gradient(circle at 15% 25%, rgba(214,139,22,0.06), transparent 18%),
                radial-gradient(circle at 85% 75%, rgba(128,0,32,0.04), transparent 20%);
            z-index: 0;
            animation: bgPulse 10s ease-in-out infinite alternate;
            transform-origin: center;
        }
        @keyframes bgPulse {
            from { opacity: 0.6; transform: scale(1); filter: blur(0.6px); }
            to   { opacity: 1;   transform: scale(1.02); filter: blur(1.2px); }
        }

        /* ── Main card ── */
        .login-card {
            position: relative; z-index: 10;
            width: 100%; max-width: 900px;
            background: #fff;
            border-radius: 28px;
            box-shadow:
                0 32px 80px rgba(0,0,0,0.18),
                0 0 0 1px rgba(181,115,14,0.15);
            overflow: hidden;
            display: grid;
            grid-template-columns: 1fr 1fr;
        }

        /* ── Left panel (slideshow) ── */
        .left-panel {
            position: relative;
            min-height: 520px;
            overflow: hidden;
        }

        .slide-img {
            position: absolute; inset: 0;
            width: 100%; height: 100%;
            object-fit: cover;
            opacity: 0;
            transition: opacity 1.4s ease-in-out;
        }
        .slide-img.active { opacity: 1; }

        .left-overlay {
            position: absolute; inset: 0;
            background: linear-gradient(
                to bottom,
                rgba(20,10,0,0.25) 0%,
                rgba(20,10,0,0.15) 40%,
                rgba(10,5,0,0.75) 100%
            );
            z-index: 1;
        }

        /* Gold border accent on right side of left panel */
        .left-panel::after {
            content: '';
            position: absolute;
            top: 0; right: 0;
            width: 3px; height: 100%;
            background: linear-gradient(180deg, transparent 0%, var(--gold) 30%, var(--gold-dark) 70%, transparent 100%);
            z-index: 3;
        }

        .left-content {
            position: absolute; inset: 0;
            z-index: 2;
            display: flex; flex-direction: column;
            justify-content: space-between;
            padding: 28px;
        }

        .brand-top {
            display: flex; align-items: center; gap: 12px;
        }
        .brand-top img {
            width: 48px; height: 48px; object-fit: contain;
            filter: drop-shadow(0 2px 8px rgba(0,0,0,0.6)) brightness(1.1);
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
            padding: 4px;
        }
        .brand-top-text h2 {
            font-family: 'Playfair Display', serif;
            font-size: 14px; font-weight: 700;
            color: #fff; line-height: 1.3;
            text-shadow: 0 2px 8px rgba(0,0,0,0.6);
            letter-spacing: 0.5px;
        }
        .brand-top-text p {
            font-size: 10px; color: rgba(255,255,255,0.7);
            margin-top: 2px;
        }

        .left-bottom { }
        .left-bottom h3 {
            font-family: 'Playfair Display', serif;
            font-size: 22px; font-weight: 700;
            color: #fff; line-height: 1.3;
            text-shadow: 0 2px 12px rgba(0,0,0,0.7);
            margin-bottom: 8px;
        }
        .left-bottom p {
            font-size: 12px; color: rgba(255,255,255,0.82);
            line-height: 1.7; margin-bottom: 16px;
            text-shadow: 0 1px 4px rgba(0,0,0,0.5);
        }

        /* Features list */
        .feature-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 18px; }
        .feature-item {
            display: flex; align-items: center; gap: 8px;
            font-size: 11px; color: rgba(255,255,255,0.9);
        }
        .feature-item i { color: var(--gold); font-size: 12px; width: 14px; }

        /* Slide dots */
        .slide-dots { display: flex; gap: 6px; }
        .dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: rgba(255,255,255,0.35); cursor: pointer;
            transition: all 0.3s ease;
        }
        .dot.active { background: var(--gold); width: 20px; border-radius: 4px; }

        /* ── Right panel (form) ── */
        .right-panel {
            padding: 44px 40px;
            display: flex; flex-direction: column; justify-content: center;
            background: #fff;
        }

        .form-logo {
            text-align: center; margin-bottom: 24px;
        }
        .form-logo img {
            width: 68px; height: 68px; object-fit: contain;
            filter: drop-shadow(0 4px 12px rgba(214,139,22,0.3));
            animation: logoFloat 3s ease-in-out infinite;
        }
        @keyframes logoFloat {
            0%,100% { transform: translateY(0); }
            50%      { transform: translateY(-6px); }
        }
        .form-logo h1 {
            font-family: 'Playfair Display', serif;
            font-size: 22px; font-weight: 700;
            color: var(--crimson); margin-top: 10px;
            line-height: 1.2;
        }
        .form-logo p { font-size: 11px; color: var(--text-mid); margin-top: 3px; }

        /* ── Form section title ── */
        .form-title {
            font-family: 'Playfair Display', serif;
            font-size: 20px; font-weight: 700;
            color: var(--crimson); margin-bottom: 20px;
            display: flex; align-items: center; gap: 8px;
        }
        .form-title::after {
            content: '';
            flex: 1; height: 2px;
            background: linear-gradient(90deg, rgba(214,139,22,0.5), transparent);
            border-radius: 2px;
        }

        /* ── Alert boxes ── */
        .alert {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; border-radius: 10px;
            font-size: 12px; font-weight: 500; margin-bottom: 16px;
        }
        .alert-error  { background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; }
        .alert-success{ background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }

        /* ── Input groups ── */
        .input-group { margin-bottom: 16px; }
        .input-label {
            display: block; font-size: 12px; font-weight: 600;
            color: var(--text-mid); margin-bottom: 6px;
        }
        .input-wrap {
            position: relative;
        }
        .input-icon {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            color: var(--gold-dark); font-size: 13px; pointer-events: none;
        }
        .jewel-field {
            width: 100%; padding: 10px 12px 10px 36px;
            background: var(--cream);
            border: 1.5px solid rgba(181,115,14,0.3);
            border-radius: 10px; font-size: 13px;
            color: var(--text-dark); font-family: 'Poppins', sans-serif;
            transition: all 0.25s ease;
            outline: none;
        }
        .jewel-field:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(214,139,22,0.15);
            background: #fffdf5;
        }
        .jewel-field::placeholder { color: rgba(122,78,10,0.4); }

        .eye-btn {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: rgba(122,78,10,0.5); font-size: 13px;
            transition: color 0.2s;
        }
        .eye-btn:hover { color: var(--gold); }

        /* ── Forgot password ── */
        .forgot-link {
            text-align: right; margin-top: -8px; margin-bottom: 20px;
        }
        .forgot-link a {
            font-size: 11px; font-weight: 600;
            color: var(--crimson); text-decoration: none;
            transition: opacity 0.2s;
        }
        .forgot-link a:hover { opacity: 0.7; }

        /* ── Submit button ── */
        .btn-submit {
            width: 100%;
            padding: 11px;
            background: linear-gradient(135deg, var(--crimson), var(--gold));
            border: none; border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 13px; font-weight: 700;
            color: #fff; cursor: pointer;
            transition: all 0.3s ease;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            letter-spacing: 0.5px;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 28px rgba(128,0,32,0.35);
        }
        .btn-submit:active { transform: translateY(0); }

        /* ── Divider ── */
        .divider {
            display: flex; align-items: center; gap: 12px;
            margin: 18px 0; color: rgba(122,78,10,0.4); font-size: 11px;
        }
        .divider::before, .divider::after {
            content: ''; flex: 1; height: 1px;
            background: rgba(181,115,14,0.2);
        }

        /* ── OTP boxes ── */
        .otp-row {
            display: flex; justify-content: center; gap: 10px; margin-bottom: 24px;
        }
        .otp-box {
            width: 44px; height: 52px;
            text-align: center; font-size: 20px; font-weight: 700;
            font-family: 'Playfair Display', serif;
            background: var(--cream);
            border: 2px solid rgba(181,115,14,0.3);
            border-radius: 12px; color: var(--crimson);
            outline: none; transition: all 0.25s ease;
            caret-color: var(--gold);
        }
        .otp-box:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(214,139,22,0.2);
            background: #fffdf5;
            transform: scale(1.06);
        }
        .otp-box.filled {
            border-color: var(--crimson);
            background: #fff1f2;
        }

        /* OTP shield icon */
        .otp-shield {
            width: 64px; height: 64px;
            background: linear-gradient(135deg, var(--crimson), var(--gold));
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            box-shadow: 0 8px 24px rgba(128,0,32,0.3);
            animation: shieldPulse 2s ease-in-out infinite;
        }
        @keyframes shieldPulse {
            0%,100% { box-shadow: 0 8px 24px rgba(128,0,32,0.3); }
            50%      { box-shadow: 0 8px 32px rgba(214,139,22,0.5); }
        }
        .otp-shield i { color: #fff; font-size: 26px; }

        /* ── Resend / back links ── */
        .text-link {
            background: none; border: none; cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: 12px; font-weight: 600;
            color: var(--crimson); transition: opacity 0.2s;
        }
        .text-link:hover { opacity: 0.7; }
        .text-link-gray {
            background: none; border: none; cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: 12px; color: #9ca3af;
            transition: color 0.2s; display: block; margin: 8px auto 0;
        }
        .text-link-gray:hover { color: var(--text-mid); }

        /* ── Trust badges ── */
        .trust-badges {
            display: flex; justify-content: center; gap: 20px;
            margin-top: 22px; padding-top: 18px;
            border-top: 1px solid rgba(181,115,14,0.12);
        }
        .badge-item {
            display: flex; flex-direction: column; align-items: center; gap: 4px;
            font-size: 10px; color: var(--text-mid); opacity: 0.75;
        }
        .badge-item i { font-size: 16px; color: var(--gold); }

        /* ── Responsive ── */
        @media (max-width: 720px) {
            .login-card { grid-template-columns: 1fr; }
            .left-panel { display: none; }
            .right-panel { padding: 36px 28px; }
        }
        @media (max-width: 400px) {
            .right-panel { padding: 28px 20px; }
            .otp-box { width: 38px; height: 46px; font-size: 18px; }
        }

        /* Back button */
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: linear-gradient(135deg, rgba(214,139,22,0.15), rgba(214,139,22,0.08));
            color: var(--gold);
            border: 1.5px solid rgba(214,139,22,0.3);
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.25s ease;
            font-family: 'Poppins', sans-serif;
            margin-bottom: 20px;
        }
        .btn-back:hover {
            background: linear-gradient(135deg, rgba(214,139,22,0.25), rgba(214,139,22,0.15));
            border-color: rgba(214,139,22,0.5);
            transform: translateX(-3px);
        }
        .btn-back i { font-size: 14px; }
    </style>
</head>
<body>

<div class="login-card">

    <!-- ════ LEFT PANEL ════ -->
    <div class="left-panel">
        <!-- Slides -->
        <img class="slide-img active" src="assets/images/slider/nakles.png"   alt="Gold Necklace">
        <img class="slide-img"        src="assets/images/slider/dimon.png"    alt="Diamond Ring">
        <img class="slide-img"        src="assets/images/slider/bangal.png"   alt="Gold Bangles">
        <img class="slide-img"        src="assets/images/slider/earring.png"  alt="Earrings">
        <img class="slide-img"        src="assets/images/slider/jeweller.png" alt="Jewellery">
        <img class="slide-img"        src="assets/images/slider/bridal.png"   alt="Bridal Set">

        <div class="left-overlay"></div>

        <div class="left-content">
            <!-- Brand top -->
            <div class="brand-top">
                <img src="assets/images/moti-removebg-preview.png" alt="Logo"
                     onerror="this.style.display='none'">
                <div class="brand-top-text">
                    <h2>MAA GOURI JEWELLERS</h2>
                    <p>Premium Since 2026</p>
                </div>
            </div>

            <!-- Bottom text -->
            <div class="left-bottom">
                <h3>Crafting Timeless<br>Elegance</h3>
                <p>Complete jewellery management — GST billing, live stock tracking, customer insights &amp; more.</p>
                <div class="feature-list">
                    <div class="feature-item"><i class="fas fa-file-invoice-dollar"></i> GST &amp; Non-GST Billing</div>
                    <div class="feature-item"><i class="fas fa-boxes"></i> Live Stock Tracking</div>
                    <div class="feature-item"><i class="fas fa-users"></i> Customer Management</div>
                    <div class="feature-item"><i class="fas fa-chart-bar"></i> Business Reports</div>
                    <div class="feature-item"><i class="fab fa-whatsapp"></i> WhatsApp Automation</div>
                </div>
                <div class="slide-dots" id="slideDots">
                    <span class="dot active"></span>
                    <span class="dot"></span>
                    <span class="dot"></span>
                    <span class="dot"></span>
                    <span class="dot"></span>
                    <span class="dot"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ════ RIGHT PANEL ════ -->
    <div class="right-panel">

        <!-- Logo -->
        <div class="form-logo">
            <img src="assets/images/moti-removebg-preview.png" alt="Logo" onerror="this.style.display='none'">
            <h1>MAA GOURI JEWELLERS</h1>
            <p>Premium Jewellery Management System</p>
        </div>

        <!-- Back Button -->
        <a href="index.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to Home
        </a>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- ════ LOGIN FORM ════ -->
        <div id="loginForm" <?php echo $otpSent ? 'style="display:none"' : ''; ?>>
            <div class="form-title"><i class="fas fa-sign-in-alt" style="color:var(--gold);font-size:16px;"></i> Secure Login</div>

            <form method="POST" action="">
                <div class="input-group">
                    <label class="input-label">Email Address</label>
                    <div class="input-wrap">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" name="email" required placeholder="your@email.com" class="jewel-field">
                    </div>
                </div>

                <div class="input-group">
                    <label class="input-label">Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="password" id="passwordInput" required placeholder="••••••••" class="jewel-field" style="padding-right:38px;">
                        <button type="button" class="eye-btn" onclick="togglePass()">
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="forgot-link">
                    <a href="forgot_password.php"><i class="fas fa-key" style="margin-right:4px;"></i>Forgot Password?</a>
                </div>

                <button type="submit" name="send_otp" class="btn-submit">
                    <i class="fas fa-gem"></i> LOGIN TO DASHBOARD
                </button>
            </form>

            <!-- Trust badges -->
            <div class="trust-badges">
                <div class="badge-item"><i class="fas fa-shield-alt"></i>Secure</div>
                <div class="badge-item"><i class="fas fa-lock"></i>Encrypted</div>
                <div class="badge-item"><i class="fas fa-user-check"></i>Verified</div>
            </div>
        </div>

        <!-- ════ OTP FORM ════ -->
        <div id="otpForm" <?php echo $otpSent ? '' : 'style="display:none"'; ?>>
            <div class="otp-shield"><i class="fas fa-shield-alt"></i></div>

            <div style="text-align:center;margin-bottom:20px;">
                <h3 style="font-family:'Playfair Display',serif;font-size:20px;font-weight:700;color:var(--crimson);margin-bottom:6px;">Verify OTP</h3>
                <p style="font-size:12px;color:#6b7280;line-height:1.6;">
                    A 6-digit OTP has been sent to<br>
                    <strong style="color:var(--crimson);">motijewellers9635985848@gmail.com</strong>
                </p>
            </div>

            <form method="POST" id="otpFormEl">
                <div class="otp-row">
                    <input class="otp-box" type="text" maxlength="1" pattern="[0-9]" inputmode="numeric" id="o1" autofocus>
                    <input class="otp-box" type="text" maxlength="1" pattern="[0-9]" inputmode="numeric" id="o2">
                    <input class="otp-box" type="text" maxlength="1" pattern="[0-9]" inputmode="numeric" id="o3">
                    <input class="otp-box" type="text" maxlength="1" pattern="[0-9]" inputmode="numeric" id="o4">
                    <input class="otp-box" type="text" maxlength="1" pattern="[0-9]" inputmode="numeric" id="o5">
                    <input class="otp-box" type="text" maxlength="1" pattern="[0-9]" inputmode="numeric" id="o6">
                </div>
                <input type="hidden" name="otp" id="otpHidden">

                <button type="submit" name="verify_otp" id="verifyBtn" class="btn-submit" disabled style="opacity:0.55;cursor:not-allowed;">
                    <i class="fas fa-check-circle"></i> VERIFY &amp; LOGIN
                </button>
            </form>

            <div style="text-align:center;margin-top:18px;">
                <p id="resendTimer" style="font-size:12px;color:#9ca3af;">
                    <?php if($otpSent): ?>
                    Resend OTP in <span id="countdown" style="font-weight:700;color:var(--gold);">30</span>s
                    <?php endif; ?>
                </p>
                <form method="POST" id="resendForm" style="display:none;margin-top:4px;">
                    <button type="submit" name="resend_otp" class="text-link">
                        <i class="fas fa-redo" style="margin-right:4px;"></i>Resend OTP
                    </button>
                </form>
                <button onclick="backToLogin()" class="text-link-gray">
                    <i class="fas fa-arrow-left" style="margin-right:4px;"></i>Back to Login
                </button>
            </div>
        </div>

    </div><!-- /right-panel -->
</div><!-- /login-card -->

<script>
    /* ── Slideshow ── */
    (function(){
        const imgs = document.querySelectorAll('.slide-img');
        const dots = document.querySelectorAll('.dot');
        let cur = 0, timer;

        function goTo(n) {
            imgs[cur].classList.remove('active');
            dots[cur].classList.remove('active');
            cur = (n + imgs.length) % imgs.length;
            imgs[cur].classList.add('active');
            dots[cur].classList.add('active');
        }

        function start() { timer = setInterval(() => goTo(cur+1), 5000); }
        function stop()  { clearInterval(timer); }

        dots.forEach((d,i) => d.addEventListener('click', () => { goTo(i); stop(); start(); }));
        const lp = document.querySelector('.left-panel');
        if(lp) { lp.addEventListener('mouseenter', stop); lp.addEventListener('mouseleave', start); }
        start();
    })();

    /* ── Password toggle ── */
    function togglePass() {
        const inp = document.getElementById('passwordInput');
        const ico = document.getElementById('eyeIcon');
        if (inp.type === 'password') { inp.type = 'text';     ico.classList.replace('fa-eye','fa-eye-slash'); }
        else                         { inp.type = 'password'; ico.classList.replace('fa-eye-slash','fa-eye'); }
    }

    /* ── Show/hide forms ── */
    function backToLogin() {
        document.getElementById('loginForm').style.display = 'block';
        document.getElementById('otpForm').style.display   = 'none';
    }

    /* ── OTP boxes ── */
    const boxes     = ['o1','o2','o3','o4','o5','o6'].map(id => document.getElementById(id));
    const verifyBtn = document.getElementById('verifyBtn');
    const otpHidden = document.getElementById('otpHidden');

    function updateHidden() {
        const val = boxes.map(b => b.value).join('');
        otpHidden.value = val;
        const complete = val.length === 6;
        verifyBtn.disabled = !complete;
        verifyBtn.style.opacity = complete ? '1' : '0.55';
        verifyBtn.style.cursor  = complete ? 'pointer' : 'not-allowed';
    }

    boxes.forEach((box, i) => {
        box.addEventListener('input', () => {
            box.value = box.value.replace(/\D/g,'').slice(-1);
            box.classList.toggle('filled', !!box.value);
            if (box.value && i < 5) boxes[i+1].focus();
            updateHidden();
        });
        box.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !box.value && i > 0) {
                boxes[i-1].focus(); boxes[i-1].value = '';
                boxes[i-1].classList.remove('filled');
                updateHidden();
            }
        });
        box.addEventListener('paste', e => {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'');
            if (pasted.length === 6) {
                boxes.forEach((b,j) => { b.value = pasted[j]||''; b.classList.toggle('filled', !!b.value); });
                boxes[5].focus(); updateHidden();
            }
        });
    });

    /* ── Resend countdown ── */
    <?php if($otpSent): ?>
    (function(){
        let sec = 30;
        const el    = document.getElementById('countdown');
        const form  = document.getElementById('resendForm');
        const timer = document.getElementById('resendTimer');
        const t = setInterval(() => {
            sec--;
            if(el) el.textContent = sec;
            if(sec <= 0) {
                clearInterval(t);
                if(timer) timer.style.display = 'none';
                if(form)  form.style.display  = 'block';
            }
        }, 1000);
    })();
    <?php endif; ?>
</script>
</body>
</html>