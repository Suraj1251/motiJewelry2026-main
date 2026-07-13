<!-- moti-jewellers/forgot_password.php -->
<?php
session_start();
require_once 'config/database.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/phpmailer/phpmailer/src/Exception.php';
require 'vendor/phpmailer/phpmailer/src/PHPMailer.php';
require 'vendor/phpmailer/phpmailer/src/SMTP.php';

$error   = '';
$success = '';

// step: 'email' → 'otp' → 'reset' → done
$step = $_SESSION['fp_step'] ?? 'email';

// ── STEP 1: Send OTP ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_otp'])) {
    $email = mysqli_real_escape_string($conn, trim($_POST['email']));

    $check = mysqli_query($conn, "SELECT id, name FROM users WHERE email = '$email'");
    if (mysqli_num_rows($check) > 0) {
        $user = mysqli_fetch_assoc($check);

        $otp        = sprintf("%06d", mt_rand(100000, 999999));
        $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        mysqli_query($conn, "DELETE FROM otp_logins WHERE email = '$email' AND is_used = 0");
        $ins = "INSERT INTO otp_logins (email, otp, expires_at) VALUES ('$email', '$otp', '$expires_at')";

        if (mysqli_query($conn, $ins)) {
            // ── Send OTP via PHPMailer ────────────────────────────────────────
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'santudhara157@gmail.com';
                $mail->Password   = 'gieoszdkzsouypho';
                $mail->SMTPSecure = 'tls';
                $mail->Port       = 587;

                $mail->setFrom('santudhara157@gmail.com', 'Gouri Jewellers');
                $mail->addAddress($email, $user['name']);
                $mail->isHTML(true);
                $mail->Subject = '🔐 Password Reset OTP - Gouri Jewellers';
                $mail->Body    = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;">
                <div style="max-width:480px;margin:auto;border:1px solid #ddd;border-radius:10px;overflow:hidden;">
                  <div style="background:linear-gradient(135deg,#667eea,#764ba2);padding:20px;text-align:center;">
                    <h2 style="color:#fff;margin:0;">💎 Gouri Jewellers</h2>
                    <p style="color:#fff;margin:4px 0 0;font-size:13px;">Password Reset OTP</p>
                  </div>
                  <div style="padding:24px;text-align:center;">
                    <p style="color:#374151;">Dear <strong>' . htmlspecialchars($user['name']) . '</strong>,</p>
                    <p style="color:#374151;">Use this OTP to reset your password:</p>
                    <div style="font-size:36px;font-weight:800;letter-spacing:10px;color:#764ba2;margin:16px 0;">' . $otp . '</div>
                    <p style="color:#6b7280;font-size:13px;">Valid for <strong>10 minutes</strong>. Do not share with anyone.</p>
                  </div>
                  <div style="background:#f9fafb;padding:12px;text-align:center;font-size:11px;color:#9ca3af;">
                    Gouri Jewellers &mdash; Contact: +91 96472 91299
                  </div>
                </div></body></html>';
                $mail->AltBody = "Your Gouri Jewellers Password Reset OTP: $otp (valid for 10 minutes)";
                $mail->send();

                $_SESSION['fp_step']  = 'otp';
                $_SESSION['fp_email'] = $email;
                $_SESSION['fp_name']  = $user['name'];
                $step    = 'otp';
                $success = "✅ OTP sent to <strong>$email</strong>. Please check your inbox.";

            } catch (Exception $e) {
                // OTP DB mein save hai, but email nahi gaya
                $error = "❌ CAN'T OTP send: " . $mail->ErrorInfo;
                // DB se delete kar do
                mysqli_query($conn, "DELETE FROM otp_logins WHERE email = '$email' AND is_used = 0");
            }

        } else {
            $error = "❌ Failed to generate OTP. Please try again.";
        }
    } else {
        $error = "❌ Email address not found!";
    }
}
// <meta name="author" content="MANU GUPTA">

// ── STEP 2: Verify OTP ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify_otp'])) {
    $otp   = mysqli_real_escape_string($conn, trim($_POST['otp']));
    $email = $_SESSION['fp_email'] ?? '';

    if (empty($email)) {
        $error = "❌ Session expired. Please start again.";
        $_SESSION['fp_step'] = 'email';
        $step = 'email';
    } else {
        $chk = mysqli_query($conn,
            "SELECT * FROM otp_logins WHERE email='$email' AND otp='$otp' AND is_used=0 AND expires_at > NOW()");
        if (mysqli_num_rows($chk) > 0) {
            $rec = mysqli_fetch_assoc($chk);
            // Mark OTP used
            mysqli_query($conn, "UPDATE otp_logins SET is_used=1 WHERE id={$rec['id']}");
            $_SESSION['fp_step']         = 'reset';
            $_SESSION['fp_otp_verified'] = true;
            $step    = 'reset';
            $success = "✅ OTP verified! Please set your new password.";
        } else {
            $error = "❌ Invalid or expired OTP. Please try again.";
            $step  = 'otp';
        }
    }
}

// ── STEP 3: Reset Password ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    $email = $_SESSION['fp_email'] ?? '';

    if (empty($email) || empty($_SESSION['fp_otp_verified'])) {
        $error = "❌ Session expired. Please start again.";
        $_SESSION['fp_step'] = 'email';
        $step = 'email';
    } else {
        $new_pass     = $_POST['new_password'];
        $confirm_pass = $_POST['confirm_password'];

        if (strlen($new_pass) < 6) {
            $error = "❌ Password must be at least 6 characters.";
            $step  = 'reset';
        } elseif ($new_pass !== $confirm_pass) {
            $error = "❌ Passwords do not match!";
            $step  = 'reset';
        } else {
            $hashed    = password_hash($new_pass, PASSWORD_DEFAULT);
            $email_esc = mysqli_real_escape_string($conn, $email);
            $upd = mysqli_query($conn, "UPDATE users SET password='$hashed' WHERE email='$email_esc'");

            if ($upd) {
                // Clear all fp session data
                unset($_SESSION['fp_step'], $_SESSION['fp_email'],
                      $_SESSION['fp_name'], $_SESSION['fp_otp_verified']);
                $step    = 'done';
                $success = "✅ Password reset successful! You can now login with your new password.";
            } else {
                $error = "❌ Failed to update password. Please try again.";
                $step  = 'reset';
            }
        }
    }
}

$step = $_SESSION['fp_step'] ?? $step;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="author" content="MANU GUPTA">
    <meta name="description" content="Forgot Password for Gouri Jewellers">
    <title>Forgot Password - GOURI JEWELLERS</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
</head>
<body class="bg-gray-50">
<div class="min-h-screen flex items-center justify-center py-8 px-4">
    <div class="max-w-md w-full bg-gray-300 rounded-2xl shadow-2xl overflow-hidden">

        <!-- Header -->
        <div class="gradient-bg p-6 text-center">
            <i class="fas fa-gem text-4xl text-white mb-2"></i>
            <h2 class="text-2xl font-bold text-white">GOURI JEWELLERS</h2>
            <p class="text-white opacity-90 text-sm">Forgot Password</p>
        </div>

        <!-- Step Indicator -->
        <?php if ($step !== 'done'): ?>
        <div class="flex items-center justify-center gap-2 px-6 pt-5 pb-2">
            <!-- Step 1 -->
            <div class="step-dot <?php echo in_array($step,['otp','reset']) ? 'step-done' : ($step==='email' ? 'step-active' : 'step-idle'); ?>">
                <?php echo in_array($step,['otp','reset']) ? '✓' : '1'; ?>
            </div>
            <div class="h-0.5 w-10 <?php echo in_array($step,['otp','reset','done']) ? 'bg-green-500' : 'bg-gray-200'; ?>"></div>
            <!-- Step 2 -->
            <div class="step-dot <?php echo $step==='reset' ? 'step-done' : ($step==='otp' ? 'step-active' : 'step-idle'); ?>">
                <?php echo $step==='reset' ? '✓' : '2'; ?>
            </div>
            <div class="h-0.5 w-10 <?php echo $step==='reset' ? 'bg-green-500' : 'bg-gray-200'; ?>"></div>
            <!-- Step 3 -->
            <div class="step-dot <?php echo $step==='reset' ? 'step-active' : 'step-idle'; ?>">3</div>
        </div>
        <div class="flex justify-center gap-8 text-xs text-gray-400 pb-3">
            <span>Email</span><span>OTP</span><span>New Password</span>
        </div>
        <?php endif; ?>

        <div class="p-6">
            <!-- Alerts -->
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm flex items-start gap-2">
                    <i class="fas fa-exclamation-circle mt-0.5"></i><span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-4 text-sm flex items-start gap-2">
                    <i class="fas fa-check-circle mt-0.5"></i><span><?php echo $success; ?></span>
                </div>
            <?php endif; ?>

            <?php if ($step === 'email'): ?>
            <!-- ── STEP 1: Email ── -->
            <h3 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-envelope mr-2 text-purple-500"></i>Enter Your Email</h3>
            <form method="POST">
                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 text-sm font-semibold">Registered Email Address</label>
                    <div class="relative">
                        <i class="fas fa-envelope absolute left-3 top-3 text-gray-400"></i>
                        <input type="email" name="email" required autofocus
                               class="w-full pl-10 pr-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500 text-sm"
                               placeholder="your@email.com">
                    </div>
                    <p class="text-xs text-gray-400 mt-1">We'll send a 6-digit OTP to this email.</p>
                </div>
                <button type="submit" name="send_otp"
                        class="w-full gradient-bg text-white py-2.5 rounded-lg font-semibold hover:opacity-90 transition text-sm">
                    <i class="fas fa-paper-plane mr-2"></i>Send OTP
                </button>
            </form>

            <?php elseif ($step === 'otp'): ?>
            <!-- ── STEP 2: OTP ── -->
            <h3 class="text-lg font-bold text-gray-800 mb-1"><i class="fas fa-shield-alt mr-2 text-purple-500"></i>Verify OTP</h3>
            <p class="text-xs text-gray-400 mb-4">OTP sent to <strong><?php echo htmlspecialchars($_SESSION['fp_email'] ?? ''); ?></strong></p>
            <form method="POST">
                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 text-sm font-semibold">Enter 6-digit OTP</label>
                    <div class="relative">
                        <i class="fas fa-key absolute left-3 top-3 text-gray-400"></i>
                        <input type="text" name="otp" required pattern="[0-9]{6}" maxlength="6"
                               autocomplete="off" autofocus inputmode="numeric"
                               class="w-full pl-10 pr-3 py-2 border rounded-lg focus:outline-none focus:border-purple-500 text-center text-2xl tracking-widest font-bold"
                               placeholder="000000">
                    </div>
                    <p class="text-xs text-gray-400 mt-1">OTP is valid for 10 minutes.</p>
                </div>
                <button type="submit" name="verify_otp"
                        class="w-full gradient-bg text-white py-2.5 rounded-lg font-semibold hover:opacity-90 transition text-sm">
                    <i class="fas fa-check-circle mr-2"></i>Verify OTP
                </button>
            </form>
            <div class="text-center mt-3">
                <a href="forgot_password.php"
                   class="text-purple-600 text-xs hover:underline">
                    <i class="fas fa-redo-alt mr-1"></i>Resend OTP
                </a>
            </div>

            <?php elseif ($step === 'reset'): ?>
            <!-- ── STEP 3: New Password ── -->
            <h3 class="text-lg font-bold text-gray-800 mb-1"><i class="fas fa-lock mr-2 text-purple-500"></i>Set New Password</h3>
            <p class="text-xs text-gray-400 mb-4">For account: <strong><?php echo htmlspecialchars($_SESSION['fp_email'] ?? ''); ?></strong></p>
            <form method="POST">
                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 text-sm font-semibold">New Password</label>
                    <div class="relative">
                        <i class="fas fa-lock absolute left-3 top-3 text-gray-400"></i>
                        <input type="password" name="new_password" id="newPass" required minlength="6" autofocus
                               class="w-full pl-10 pr-10 py-2 border rounded-lg focus:outline-none focus:border-purple-500 text-sm"
                               placeholder="Minimum 6 characters">
                        <span class="toggle-eye absolute right-3 top-2.5 text-gray-400 hover:text-gray-600" onclick="togglePass('newPass','eye1')">
                            <i class="fas fa-eye" id="eye1"></i>
                        </span>
                    </div>
                </div>
                <div class="mb-5">
                    <label class="block text-gray-700 mb-1 text-sm font-semibold">Confirm New Password</label>
                    <div class="relative">
                        <i class="fas fa-lock absolute left-3 top-3 text-gray-400"></i>
                        <input type="password" name="confirm_password" id="confPass" required minlength="6"
                               class="w-full pl-10 pr-10 py-2 border rounded-lg focus:outline-none focus:border-purple-500 text-sm"
                               placeholder="Re-enter new password">
                        <span class="toggle-eye absolute right-3 top-2.5 text-gray-400 hover:text-gray-600" onclick="togglePass('confPass','eye2')">
                            <i class="fas fa-eye" id="eye2"></i>
                        </span>
                    </div>
                    <p id="matchMsg" class="text-xs mt-1 hidden"></p>
                </div>
                <button type="submit" name="reset_password" id="resetBtn"
                        class="w-full gradient-bg text-white py-2.5 rounded-lg font-semibold hover:opacity-90 transition text-sm">
                    <i class="fas fa-save mr-1"></i>Save New Password
                </button>
            </form>

            <?php elseif ($step === 'done'): ?>
            <!-- ── DONE ── -->
            <div class="text-center py-4">
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-check-circle text-green-500 text-4xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-800 mb-2">Password Reset Successful!</h3>
                <p class="text-gray-500 text-sm mb-6">Your password has been updated. Please login with your new password.</p>
                <a href="login.php"
                   class="inline-block gradient-bg text-white px-8 py-2.5 rounded-lg font-semibold hover:opacity-90 transition text-sm">
                    <i class="fas fa-sign-in-alt mr-2"></i>Go to Login
                </a>
            </div>
            <?php endif; ?>

            <?php if ($step !== 'done'): ?>
            <div class="text-center mt-5 pt-4 border-t border-gray-100">
                <a href="login.php" class="text-gray-400 text-xs hover:text-purple-600">
                    <i class="fas fa-arrow-left mr-1"></i>Back to Login
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function togglePass(inputId, iconId) {
    const inp = document.getElementById(inputId);
    const ico = document.getElementById(iconId);
    if (inp.type === 'password') { inp.type = 'text'; ico.classList.replace('fa-eye','fa-eye-slash'); }
    else { inp.type = 'password'; ico.classList.replace('fa-eye-slash','fa-eye'); }
}

// Live password match check
const newP  = document.getElementById('newPass');
const conP  = document.getElementById('confPass');
const msg   = document.getElementById('matchMsg');
const btn   = document.getElementById('resetBtn');

if (conP) {
    conP.addEventListener('input', checkMatch);
    if (newP) newP.addEventListener('input', checkMatch);
}

function checkMatch() {
    if (!conP.value) { msg.classList.add('hidden'); return; }
    if (newP.value === conP.value) {
        msg.textContent = '✅ Passwords match';
        msg.className = 'text-xs mt-1 text-green-600';
        msg.classList.remove('hidden');
    } else {
        msg.textContent = '❌ Passwords do not match';
        msg.className = 'text-xs mt-1 text-red-500';
        msg.classList.remove('hidden');
    }
}
</script>
</body>
</html>
