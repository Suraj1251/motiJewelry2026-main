<?php
session_start();
require_once 'config/database.php';
require_once 'config/mail_config.php';

$is_logged_in = isset($_SESSION['user_id']);
$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';
$message = '';

// Create due update history table if it does not exist
$create_due_history = "CREATE TABLE IF NOT EXISTS due_update_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    previous_balance DECIMAL(10,2) NOT NULL,
    new_balance DECIMAL(10,2) NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
mysqli_query($conn, $create_due_history);

// ── AJAX: send reminder ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajax_send_reminder') {
    header('Content-Type: application/json');
    $customer_email  = trim($_POST['customer_email']  ?? '');
    $customer_name   = trim($_POST['customer_name']   ?? 'Customer');
    $customer_mobile = trim($_POST['customer_mobile'] ?? '');
    $invoice_no      = trim($_POST['invoice_no']      ?? '');
    $balance_amount  = floatval($_POST['balance_amount'] ?? 0);

    // Try to look up email by mobile if not provided
    if (empty($customer_email) && !empty($customer_mobile)) {
        $safe_mobile = mysqli_real_escape_string($conn, $customer_mobile);
        $cust_res = mysqli_query($conn, "SELECT email FROM customers WHERE mobile = '$safe_mobile' LIMIT 1");
        if ($cust_res && mysqli_num_rows($cust_res) > 0) {
            $customer_email = trim(mysqli_fetch_assoc($cust_res)['email'] ?? '');
        }
    }

    if (empty($customer_email)) {
        echo json_encode(['success' => false, 'message' => 'Customer email is required to send reminder.']);
        exit();
    }
    if ($balance_amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'No unpaid amount to remind.']);
        exit();
    }

    $subject = 'Payment Reminder from Maa Gouri Jewellers';
    $body    = '<p>Dear ' . htmlspecialchars($customer_name) . ',</p>'
             . '<p>This is a reminder that an amount of <strong>&#8377;' . number_format($balance_amount, 2) . '</strong> is still due.'
             . ($invoice_no ? ' (Invoice: ' . htmlspecialchars($invoice_no) . ')' : '') . '</p>'
             . '<p>Please make the remaining payment at your earliest convenience.</p>'
             . '<p>Thank you,<br>Maa Gouri Jewellers</p>';

    error_log('[due_list] Reminder attempt -> to: ' . $customer_email . ' | invoice: ' . $invoice_no . ' | balance: ' . $balance_amount);
    $res = sendSMTPMail($customer_email, $subject, $body);
    if (!empty($res['success'])) {
        if (!empty($invoice_no)) {
            $safe_inv = mysqli_real_escape_string($conn, $invoice_no);
            mysqli_query($conn, "UPDATE invoices SET reminder_sent = 1 WHERE invoice_no = '$safe_inv'");
        }
        error_log('[due_list] Reminder sent OK -> ' . $customer_email);
        echo json_encode(['success' => true, 'message' => 'Reminder sent to ' . $customer_email]);
    } else {
        $errMsg = $res['message'] ?? 'Failed to send email.';
        error_log('[due_list] Reminder failed -> ' . $customer_email . ' | error: ' . $errMsg);
        echo json_encode(['success' => false, 'message' => $errMsg]);
    }
    exit();
}

// ── POST: clear due for an invoice ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_due') {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $upd = mysqli_query($conn, "UPDATE invoices SET balance_amount = 0, due_date = NULL WHERE id = $id");
        $message = $upd ? 'Due cleared successfully.' : 'Failed to clear due: ' . mysqli_error($conn);
    }
}

// ── AJAX: fetch due update history for modal ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajax_history') {
    header('Content-Type: application/json');
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid invoice ID.']);
        exit();
    }

    // Fetch the invoice's already paid amount so the modal total includes earlier billing payments
    $invoicePaidResult = mysqli_query($conn, "SELECT COALESCE(paid_amount, 0) AS paid_amount FROM invoices WHERE id = $id LIMIT 1");
    $running = 0.0;
    if ($invoicePaidResult) {
        $invoicePaidRow = mysqli_fetch_assoc($invoicePaidResult);
        $running = floatval($invoicePaidRow['paid_amount'] ?? 0);
    }

    // Fetch due-update history in chronological order to compute the updated total paid amount
    $res = mysqli_query($conn, "SELECT DATE_FORMAT(payment_date, '%d-%m-%Y') AS payment_date, amount_paid, previous_balance, new_balance, payment_date AS pd, created_at
                               FROM due_update_history
                               WHERE invoice_id = $id
                               ORDER BY payment_date ASC, created_at ASC");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $amt = floatval($row['amount_paid'] ?? 0);
            $running += $amt;
            $row['total_amount_paid'] = number_format($running, 2, '.', '');
            // remove helper fields if present
            unset($row['pd']);
            unset($row['created_at']);
            $history[] = $row;
        }
        // return newest-first for the modal display
        $history = array_reverse($history);
    }
    echo json_encode(['success' => true, 'history' => $history]);
    exit();
}

// ── Export all due customers history as CSV ─────────────────────────────────
if (($_GET['action'] ?? '') === 'export_due_history') {
    $hasPaidAmountCol = false;
    $colRes = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'paid_amount'");
    if ($colRes && mysqli_num_rows($colRes) > 0) {
        $hasPaidAmountCol = true;
    }
    $paidColumn = $hasPaidAmountCol ? 'COALESCE(i.paid_amount,0)' : '0';

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="due_history_export_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Invoice ID','Invoice No','Customer Name','Customer Mobile','Customer Email','Invoice Total','Due Amount','Due Date','Initial Paid Amount','Payment Date','Amount Paid','Total Amount Paid','Old Due','New Due']);

    $dueQuery = "SELECT i.id, i.invoice_no, i.customer_name, i.customer_mobile, COALESCE(c.email, '') AS customer_email, COALESCE(i.total_amount, 0) AS total_amount, COALESCE(i.balance_amount, 0) AS balance_amount, COALESCE(i.due_date, '') AS due_date, $paidColumn AS initial_paid_amount
                 FROM invoices i
                 LEFT JOIN customers c ON c.mobile = i.customer_mobile
                 WHERE COALESCE(i.balance_amount, 0) > 0
                 ORDER BY i.customer_name, i.invoice_no";
    $dueRes = mysqli_query($conn, $dueQuery);
    if ($dueRes) {
        while ($inv = mysqli_fetch_assoc($dueRes)) {
            $invoiceId = intval($inv['id']);
            $running = floatval($inv['initial_paid_amount']);
            $historyRes = mysqli_query($conn, "SELECT DATE_FORMAT(payment_date, '%d-%m-%Y') AS payment_date, amount_paid, previous_balance, new_balance
                                               FROM due_update_history
                                               WHERE invoice_id = $invoiceId
                                               ORDER BY payment_date ASC, created_at ASC");
            if ($historyRes && mysqli_num_rows($historyRes) > 0) {
                while ($h = mysqli_fetch_assoc($historyRes)) {
                    $running += floatval($h['amount_paid']);
                    fputcsv($output, [
                        $invoiceId,
                        $inv['invoice_no'],
                        $inv['customer_name'],
                        $inv['customer_mobile'],
                        $inv['customer_email'],
                        number_format(floatval($inv['total_amount']), 2, '.', ''),
                        number_format(floatval($inv['balance_amount']), 2, '.', ''),
                        $inv['due_date'],
                        number_format(floatval($inv['initial_paid_amount']), 2, '.', ''),
                        $h['payment_date'],
                        number_format(floatval($h['amount_paid']), 2, '.', ''),
                        number_format($running, 2, '.', ''),
                        number_format(floatval($h['previous_balance']), 2, '.', ''),
                        number_format(floatval($h['new_balance']), 2, '.', '')
                    ]);
                }
            } else {
                fputcsv($output, [
                    $invoiceId,
                    $inv['invoice_no'],
                    $inv['customer_name'],
                    $inv['customer_mobile'],
                    $inv['customer_email'],
                    number_format(floatval($inv['total_amount']), 2, '.', ''),
                    number_format(floatval($inv['balance_amount']), 2, '.', ''),
                    $inv['due_date'],
                    number_format(floatval($inv['initial_paid_amount']), 2, '.', ''),
                    '',
                    '',
                    number_format($running, 2, '.', ''),
                    '',
                    ''
                ]);
            }
        }
    }
    fclose($output);
    exit();
}

// ── POST: update due amount / due date ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $id       = intval($_POST['id'] ?? 0);
    $balance  = floatval($_POST['balance_amount'] ?? 0);
    $due_date = trim($_POST['due_date'] ?? '');
    $due_date_sql = ($due_date === '') ? 'NULL' : "'" . mysqli_real_escape_string($conn, $due_date) . "'";

    if ($id > 0) {
        $bal = mysqli_real_escape_string($conn, number_format($balance, 2, '.', ''));
        $current = mysqli_fetch_assoc(mysqli_query($conn, "SELECT balance_amount FROM invoices WHERE id = $id LIMIT 1"));
        $previous_balance = floatval($current['balance_amount'] ?? 0);
        $new_balance = floatval($bal);
        if ($previous_balance > $new_balance) {
            $amount_paid = $previous_balance - $new_balance;
            $payment_date = date('Y-m-d');
            mysqli_query($conn, "INSERT INTO due_update_history (invoice_id, previous_balance, new_balance, amount_paid, payment_date) VALUES ($id, $previous_balance, $new_balance, $amount_paid, '$payment_date')");
        }
        $upd = mysqli_query($conn, "UPDATE invoices SET balance_amount = $bal, due_date = $due_date_sql WHERE id = $id");
        $ok  = (bool)$upd;
        $msg = $ok ? 'Updated successfully.' : 'Update failed: ' . mysqli_error($conn);

        $isAjax = (isset($_POST['ajax']) && $_POST['ajax'] == '1')
               || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $ok, 'message' => $msg]);
            exit();
        }
        $message = $msg;
    }
}

// ── Fetch all invoices with a due balance ─────────────────────────────────────
$q = "SELECT i.id, i.invoice_no, i.customer_name, i.customer_mobile,
             COALESCE(c.email, '') AS customer_email,
             i.balance_amount, i.due_date,
             GROUP_CONCAT(DISTINCT COALESCE(ii.product_name, '') SEPARATOR ', ') AS items
      FROM invoices i
      LEFT JOIN invoice_items ii ON ii.invoice_id = i.id
      LEFT JOIN customers c      ON c.mobile = i.customer_mobile
      WHERE i.balance_amount > 0
      GROUP BY i.id
      ORDER BY i.due_date IS NULL, i.due_date ASC, i.created_at DESC";

$res  = mysqli_query($conn, $q);
$rows = [];
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
}

$logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
<title>Due List – Maa Gouri Jewellers</title>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Fallback CDN for Font Awesome in case the primary is blocked -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/theme.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');

*, *::before, *::after { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
h1, h2, h3, .gold-font { font-family: 'Playfair Display', serif; }

/* ========== SIDEBAR ========== */
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

.sidebar-nav { flex: 1; padding: 10px 0; }
.sidebar-section-label { padding: 10px 20px 4px; color: rgba(255,255,255,0.45); font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; }
.sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 11px 20px; color: rgba(255,255,255,0.85); text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s ease; border-left: 3px solid transparent; position: relative; }
.sidebar-nav a:hover { background: rgba(255,255,255,0.13); color: #fff; border-left-color: rgba(255,255,255,0.8); padding-left: 26px; }
.sidebar-nav a.active { background: rgba(255,255,255,0.22); color: #fff; border-left-color: #fff; font-weight: 700; }
.sidebar-nav a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; opacity: 0.9; }
.sidebar-divider { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 16px; }
.sidebar-user { padding: 14px 16px 18px; border-top: 1px solid rgba(255,255,255,0.18); background: rgba(0,0,0,0.12); flex-shrink: 0; }
.sidebar-user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.sidebar-user-info i { color: rgba(255,255,255,0.9); font-size: 26px; flex-shrink: 0; }
.sidebar-user-info .user-details p { color: #fff; font-size: 12px; font-weight: 600; line-height: 1.3; }
.sidebar-user-info .user-details span { color: rgba(255,255,255,0.55); font-size: 10px; }
.sidebar-logout { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; background: rgba(239,68,68,0.75); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; transition: background 0.2s; border: 1px solid rgba(239,68,68,0.4); }
.sidebar-logout:hover { background: #ef4444; color: #fff; }
.sidebar-login-btn { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; background: rgba(255,255,255,0.2); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; transition: background 0.2s; border: 1px solid rgba(255,255,255,0.3); }
.sidebar-login-btn:hover { background: rgba(255,255,255,0.3); }

.sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; backdrop-filter: blur(2px); }
.sidebar-overlay.active { display: block; }

/* ========== MAIN LAYOUT ========== */
.page-wrapper { margin-left: 240px; min-height: 100vh; transition: margin-left 0.35s ease; background: #f9fafb; }
nav.nav-gold { background: linear-gradient(135deg, #b5730e, #d68b16) !important; margin-left: 0; }

/* ========== BURGER MENU ========== */
.burger-menu { width: 28px; height: 20px; position: relative; cursor: pointer; }
.burger-menu span { display: block; position: absolute; height: 3px; width: 100%; background: #ffffff; border-radius: 3px; transition: all 0.3s ease; }
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

/* ── Layout ───────────────────────────────────── */
.page-heading { margin-bottom: 24px; }
.page-heading h1, .page-heading h2 { margin: 0; }
.page-heading p { margin: 0.5rem 0 0 0; color: #7a4e0a; font-size: 14px; }

/* ── Alert ────────────────────────────────────── */
.alert-success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; border-radius: 10px; padding: 14px 18px; margin-bottom: 20px; font-size: 14px; }

/* ── Table shell ──────────────────────────────── */
.due-table-wrap { overflow-x: auto; border-radius: 14px; }
.due-table { width: 100%; border-collapse: separate; border-spacing: 0 8px; min-width: 860px; }

/* ── Header ───────────────────────────────────── */
.due-table thead th {
    text-align: left;
    font-size: 11px;
    font-weight: 600;
    color: #9ca3af;
    padding: 8px 14px;
    text-transform: uppercase;
    letter-spacing: 0.7px;
    background: transparent;
}

/* ── Rows ─────────────────────────────────────── */
.due-table tbody tr {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(15,23,42,0.05);
    transition: box-shadow 0.2s;
}
.due-table tbody tr:hover { box-shadow: 0 4px 18px rgba(15,23,42,0.10); }
.due-table tbody td {
    padding: 13px 14px;
    vertical-align: middle;
    font-size: 13px;
    color: #374151;
    border: none;
}

.due-table tbody td:first-child { border-radius: 12px 0 0 12px; }
.due-table tbody td:last-child  { border-radius: 0 12px 12px 0; }

/* ── Cell helpers ─────────────────────────────── */
.items-cell { max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #6b7280; }
.customer-name { font-weight: 600; color: #111827; }
.inv-badge { display: inline-block; margin-top: 5px; padding: 2px 9px; border-radius: 999px; font-size: 11px; background: #fef3c7; color: #92400e; font-weight: 600; }
.mobile-text { font-weight: 600; color: #374151; }
.email-text  { color: #6b7280; font-size: 12px; max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* ── Inline form inputs ───────────────────────── */
.field-label { font-size: 10px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
.inline-input {
    width: 100%; border: 1.5px solid #e5e7eb; border-radius: 8px;
    padding: 7px 10px; font-size: 13px; color: #111827;
    transition: border-color 0.15s;
    background: #f9fafb;
}
.inline-input:focus { outline: none; border-color: #f59e0b; background: #fff; }

/* ── Buttons ──────────────────────────────────── */
.btn { display: inline-flex; align-items: center; gap: 5px; padding: 7px 13px; border-radius: 8px; border: 0; cursor: pointer; font-weight: 600; font-size: 12px; transition: opacity 0.15s, transform 0.1s; white-space: nowrap; }
.btn:active { transform: scale(0.97); }
.btn:disabled { opacity: 0.55; cursor: not-allowed; }
.btn-save   { background: #10b981; color: #fff; }
.btn-save:hover   { background: #059669; }
.btn-remind { background: #f59e0b; color: #fff; }
.btn-remind:hover { background: #d97706; }    .btn-history { background: #2563eb; color: #fff; border: 1px solid rgba(37,99,235,0.35); padding: 9px 16px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; }
    .btn-history:hover { background: #1d4ed8; box-shadow: 0 10px 20px rgba(37,99,235,0.18); }.btn-delete { background: #fee2e2; color: #dc2626; border: 1.5px solid rgba(239,68,68,0.2); border-radius: 8px; padding: 7px 13px; font-weight: 600; font-size: 12px; cursor: pointer; transition: background 0.15s, color 0.15s; }
.btn-delete:hover { background: #ef4444; color: #fff; }
.btn-back { background: linear-gradient(135deg, #d68b16, #b5730e); color: #fff; padding: 10px 18px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; transition: all 0.2s; }
.btn-back:hover { background: linear-gradient(135deg, #e8a020, #c8830e); transform: translateY(-2px); box-shadow: 0 4px 16px rgba(214,139,22,0.35); }
    .btn-export { background: #111827; color: #fff; padding: 10px 18px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13px; transition: all 0.2s; border: 1px solid rgba(255,255,255,0.15); }
    .btn-export:hover { background: #1f2937; transform: translateY(-1px); box-shadow: 0 8px 24px rgba(0,0,0,0.12); }
/* ── Toast ────────────────────────────────────── */
#toast {
    position: fixed; bottom: 28px; right: 28px; z-index: 9999;
    padding: 14px 22px; border-radius: 12px; font-size: 13px;
    font-weight: 600; color: #fff; box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    opacity: 0; transform: translateY(12px);
    transition: opacity 0.25s, transform 0.25s;
    pointer-events: none;
}
#toast.show { opacity: 1; transform: translateY(0); }
#toast.success { background: #10b981; }
#toast.error   { background: #ef4444; }

@media (max-width: 720px) {
    .items-cell  { max-width: 140px; }
    .due-table td, .due-table thead th { padding: 10px 10px; }
}

/* ── Loading Overlay ──────────────────────────── */
#loadingOverlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0.95);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 99999;
    opacity: 1;
    transition: opacity 0.3s ease;
    pointer-events: all;
}
#loadingOverlay.hidden {
    opacity: 0;
    pointer-events: none;
}
.loading-spinner {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
}
.spinner {
    width: 60px;
    height: 60px;
    border: 5px solid #f0f0f0;
    border-top-color: #d68b16;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
.loading-text {
    font-size: 16px;
    font-weight: 600;
    color: #7a4e0a;
    font-family: 'Poppins', sans-serif;
}
</style>
</head>
<body class="<?php echo $theme == 'light' ? 'light-theme' : 'dark-theme'; ?>" style="background:#F5F5F5; margin:0; padding:0;">

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

<script>
function hideLoadingOverlay() {
    const overlay = document.getElementById('loadingOverlay');
    if(overlay) {
        setTimeout(() => {
            overlay.classList.add('hidden');
        }, 300);
    }
}

function showLoadingOverlay() {
    const overlay = document.getElementById('loadingOverlay');
    if(overlay) {
        overlay.classList.remove('hidden');
    }
}

// Hide loading overlay when page is fully loaded
if(document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hideLoadingOverlay);
} else {
    hideLoadingOverlay();
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
</script>

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

    <!-- Navigation -->
    <nav class="sidebar-nav">
        <div class="sidebar-section-label">Menu</div>
        <a href="reports.php">
            <svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" style="color:inherit;flex-shrink:0;">
                <rect x="3" y="11" width="4" height="10" rx="1" ry="1" fill="currentColor"></rect>
                <rect x="10" y="7" width="4" height="14" rx="1" ry="1" fill="currentColor"></rect>
                <rect x="17" y="3" width="4" height="18" rx="1" ry="1" fill="currentColor"></rect>
            </svg>
            REPORTS
        </a>
    </nav>

    <!-- User Info + Logout -->
    <div class="sidebar-user">
        <?php if($is_logged_in): ?>
        <div class="sidebar-user-info">
            <svg width="28" height="28" viewBox="0 0 496 512" aria-hidden="true" focusable="false" style="flex-shrink:0;color:inherit;">
                <path fill="currentColor" d="M248 8C111 8 0 119 0 256s111 248 248 248 248-111 248-248S385 8 248 8zm0 96a72 72 0 1 1 0 144 72 72 0 0 1 0-144zm0 344c-59.6 0-112.9-32.7-139.7-80.4 7.1-44 88.4-68.5 139.7-68.5 51.3 0 132.6 24.5 139.7 68.5C360.9 415.3 307.6 448 248 448z"></path>
            </svg>
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

<!-- ========== TOP NAVBAR ========== -->
<nav class="nav-gold shadow-lg sticky top-0 z-50" style="margin-left:240px;">
    <div class="container mx-auto px-4 sm:px-6 py-3 sm:py-4">
        <div class="flex justify-between items-center">

            <!-- Left: Logo + Title -->
            <div class="flex items-center space-x-3">
                <?php
                $logo_found = false;
                foreach($logo_paths as $path) {
                    if(file_exists($path)) {
                        echo '<img src="'.$path.'" alt="Logo" style="height:40px;width:auto;object-fit:contain;">';
                        $logo_found = true; break;
                    }
                }
                if(!$logo_found) echo '<i class="fas fa-receipt" style="color:#fff;font-size:24px;"></i>';
                ?>
                <div>
                    <h1 class="text-lg sm:text-xl font-bold" style="color:#fff;margin:0;">Due List</h1>
                </div>
            </div>

            <!-- Right Side -->
            <div class="ml-auto flex items-center gap-4">
                <?php if($is_logged_in): ?>
                <span class="text-sm font-medium text-white flex items-center">
                    <svg width="16" height="16" viewBox="0 0 448 512" aria-hidden="true" focusable="false" style="margin-right:8px;display:inline-block;color:inherit;">
                        <path fill="currentColor" d="M313.6 304c-28.7 14.1-61.9 24-97.6 24s-68.9-9.9-97.6-24C53.6 330.4 0 404.7 0 496h448c0-91.3-53.6-165.6-134.4-192zM224 256a128 128 0 1 0 0-256 128 128 0 0 0 0 256z"></path>
                    </svg>
                    <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                </span>
                <?php else: ?>
                <a href="login.php" class="text-sm font-medium text-white hover:opacity-80">
                    <i class="fas fa-sign-in-alt mr-1"></i> LOGIN
                </a>
                <?php endif; ?>

                <!-- Mobile burger -->
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

<!-- ========== PAGE WRAPPER ========== -->
<div class="page-wrapper">
<div class="container mx-auto px-4 sm:px-6 py-6 sm:py-8">

    <!-- Header -->
    <div class="page-heading">
        <h2 class="text-2xl sm:text-3xl font-bold" style="color:#800020;font-family:'Playfair Display',serif;">
            <i class="fas fa-list mr-2" style="color:#d68b16;"></i> Customers with Due Amounts
        </h2>
        <p>Pending invoices — update amount or due date, then save. Send email reminders directly.</p>
    </div>

    <!-- Navigation Buttons -->
    <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center;">
        <a href="reports.php" class="btn-back">
            <i class="fas fa-arrow-left mr-1"></i> Back to Reports
        </a>
        <a href="due_list.php?action=export_due_history" onclick="showLoadingOverlay()" class="btn btn-export">
            Export Due History
        </a>
    </div>

    <?php if ($message): ?>
    <div class="alert-success"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Table -->
    <div class="due-table-wrap">
    <table class="due-table">
        <thead>
            <tr>
                <th style="width:36px">#</th>
                <th>Customer</th>
                <th style="width:115px">Phone</th>
                <th style="width:155px">Email</th>
                <th>Items</th>
                <th style="width:130px">Due Amount (₹)</th>
                <th style="width:135px">Due Date</th>
                <!-- <th style="width:220px">Tracker</th> -->
                <th style="width:210px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr class="empty-row"><td colspan="8"><i class="fas fa-check-circle mr-2" style="color:#10b981;"></i>No due invoices found.</td></tr>
        <?php else: $i = 1; foreach ($rows as $r): ?>
        <tr>
            <!-- # -->
            <td style="color:#9ca3af;font-weight:600;text-align:center;"><?php echo $i++; ?></td>

            <!-- Customer -->
            <td>
                <div class="customer-name"><?php echo htmlspecialchars($r['customer_name']); ?></div>
                <span class="inv-badge">Inv: <?php echo htmlspecialchars($r['invoice_no']); ?></span>
            </td>

            <!-- Phone -->
            <td><span class="mobile-text"><?php echo htmlspecialchars($r['customer_mobile']); ?></span></td>

            <!-- Email -->
            <td>
                <span class="email-text" title="<?php echo htmlspecialchars($r['customer_email']); ?>">
                    <?php echo $r['customer_email'] ? htmlspecialchars($r['customer_email']) : '<span style="color:#d1d5db">—</span>'; ?>
                </span>
            </td>

            <!-- Items -->
            <td>
                <div class="items-cell" title="<?php echo htmlspecialchars($r['items']); ?>">
                    <?php echo htmlspecialchars($r['items'] ?: '—'); ?>
                </div>
            </td>

            <!-- Due Amount (editable) -->
            <td>
                <div class="field-label">Due (₹)</div>
                <input type="number" min="0" step="0.01"
                       class="inline-input due-amount-input"
                       data-id="<?php echo intval($r['id']); ?>"
                       value="<?php echo htmlspecialchars(number_format((float)$r['balance_amount'], 2, '.', '')); ?>">
            </td>

            <!-- Due Date (editable) -->
            <td>
                <div class="field-label">Due Date</div>
                <input type="date"
                       class="inline-input due-date-input"
                       data-id="<?php echo intval($r['id']); ?>"
                       value="<?php echo htmlspecialchars($r['due_date'] ?? ''); ?>">
            </td>

            <!-- Actions -->
            <td>
                <div style="display:flex;flex-direction:column;gap:7px;">
                    <!-- Save -->
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <button class="btn btn-save"
                                onclick="saveRow(<?php echo intval($r['id']); ?>, this)">
                            Save
                        </button>
                        <button class="btn btn-history"
                                type="button"
                                onclick="openHistoryModal(<?php echo intval($r['id']); ?>, '<?php echo htmlspecialchars(addslashes($r['customer_name'])); ?>')">
                            History
                        </button>
                    </div>
                    <!-- Delete -->
                    <form method="post" onsubmit="return confirm('Clear due for this invoice?');" style="margin:0;">
                        <input type="hidden" name="action" value="delete_due">
                        <input type="hidden" name="id"     value="<?php echo intval($r['id']); ?>">
                        <button type="submit" class="btn-delete">
                            Delete Due
                        </button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div><!-- /table-wrap -->

</div><!-- /container -->
</div><!-- /page-wrapper -->

<!-- Toast -->
<div id="toast"></div>

<script>
/* ── Toast helper ─────────────────────────────────── */
function showToast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = 'show ' + (type || 'success');
    clearTimeout(t._timer);
    t._timer = setTimeout(function() { t.className = ''; }, 3200);
}

/* ── Save a row via AJAX ──────────────────────────── */
function saveRow(id, btn) {
    var row    = btn.closest('tr');
    var amount = row.querySelector('.due-amount-input').value;
    var ddate  = row.querySelector('.due-date-input').value;

    var params = new URLSearchParams();
    params.append('action',         'update');
    params.append('id',             id);
    params.append('balance_amount', amount);
    params.append('due_date',       ddate);
    params.append('ajax',           '1');

    btn.disabled    = true;
    btn.innerHTML   = '<i class="fas fa-spinner fa-spin mr-1"></i> Saving…';

    fetch('due_list.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    params.toString()
    })
    .then(r => r.json())
    .then(data => {
            if (data.success) {
            showToast('Saved successfully!', 'success');
            btn.innerHTML = '<i class="fas fa-check mr-1"></i> Saved';
            setTimeout(function() { btn.innerHTML = '<i class="fas fa-save mr-1"></i> Save'; }, 2000);
        } else {
            showToast(data.message || 'Save failed.', 'error');
            btn.innerHTML = '<i class="fas fa-save mr-1"></i> Save';
        }
    })
    .catch(function(err) {
        showToast('Error: ' + (err.message || err), 'error');
        btn.innerHTML = '<i class="fas fa-save mr-1"></i> Save';
    })
    .finally(function() { btn.disabled = false; });
}

function openHistoryModal(invoiceId, customerName) {
    var modal = document.getElementById('historyModal');
    var title = document.getElementById('historyModalTitle');
    var body  = document.getElementById('historyModalBody');
    title.textContent = 'Due Update History for ' + customerName;
    body.innerHTML = '<div style="text-align:center;padding:24px;">Loading history…</div>';
    modal.style.display = 'flex';

    var params = new URLSearchParams();
    params.append('action', 'ajax_history');
    params.append('id', invoiceId);

    fetch('due_list.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (!data.success) {
            body.innerHTML = '<div style="padding:16px;color:#b91c1c;">' + (data.message || 'Unable to load history.') + '</div>';
            return;
        }
        if (!data.history || data.history.length === 0) {
            body.innerHTML = '<div style="padding:16px;color:#374151;">No update history found.</div>';
            return;
        }
        var html = '<table style="width:100%;border-collapse:collapse;text-align:left;font-size:14px;"><thead><tr>' +
               '<th style="padding:10px;border-bottom:1px solid #e5e7eb;">Date</th>' +
               '<th style="padding:10px;border-bottom:1px solid #e5e7eb;">Amount Paid</th>' +
               '<th style="padding:10px;border-bottom:1px solid #e5e7eb;">Total Amount Paid</th>' +
               '<th style="padding:10px;border-bottom:1px solid #e5e7eb;">Old Due</th>' +
               '<th style="padding:10px;border-bottom:1px solid #e5e7eb;">New Due</th>' +
               '</tr></thead><tbody>';
        data.history.forEach(function(row) {
            html += '<tr>' +
                '<td style="padding:10px;border-bottom:1px solid #e5e7eb;">' + row.payment_date + '</td>' +
                '<td style="padding:10px;border-bottom:1px solid #e5e7eb;">₹' + parseFloat(row.amount_paid).toFixed(2) + '</td>' +
                '<td style="padding:10px;border-bottom:1px solid #e5e7eb;">₹' + parseFloat(row.total_amount_paid || 0).toFixed(2) + '</td>' +
                '<td style="padding:10px;border-bottom:1px solid #e5e7eb;">₹' + parseFloat(row.previous_balance).toFixed(2) + '</td>' +
                '<td style="padding:10px;border-bottom:1px solid #e5e7eb;">₹' + parseFloat(row.new_balance).toFixed(2) + '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        body.innerHTML = html;
    })
    .catch(function(err) {
        body.innerHTML = '<div style="padding:16px;color:#b91c1c;">Error loading history.</div>';
    });
}

function closeHistoryModal() {
    var modal = document.getElementById('historyModal');
    modal.style.display = 'none';
}

</script>
<div id="historyModal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:9999;background:rgba(0,0,0,0.55);justify-content:center;align-items:center;padding:24px;">
    <div style="background:#fff;border-radius:12px;max-width:720px;width:100%;max-height:calc(100vh - 48px);box-shadow:0 18px 50px rgba(0,0,0,0.18);overflow:hidden;">
        <div style="padding:18px 22px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;">
            <h2 id="historyModalTitle" style="font-size:18px;margin:0;color:#111827;">History</h2>
            <button type="button" onclick="closeHistoryModal()" style="border:none;background:none;font-size:18px;color:#6b7280;cursor:pointer;">✕</button>
        </div>
        <div id="historyModalBody" style="max-height:70vh;overflow:auto;padding:18px 22px;">Loading history…</div>
    </div>
</div>
</body>
</html>

<!-- Font Awesome fallback: replace <i class="fa..."> with emoji if FA didn't load -->
<script>
// Run after DOM ready
(function(){
    function faLoaded() {
        var el = document.createElement('i');
        el.className = 'fas fa-user';
        el.style.display = 'inline-block';
        el.style.visibility = 'hidden';
        document.body.appendChild(el);
        var loaded = window.getComputedStyle(el).getPropertyValue('font-family').toLowerCase().indexOf('fontawesome') !== -1;
        document.body.removeChild(el);
        return loaded;
    }

    if (!faLoaded()) {
        var map = {
            'fa-user': '👤', 'fa-user-circle':'👤', 'fa-sign-out-alt':'🔓', 'fa-sign-in-alt':'🔐',
            'fa-list':'📋', 'fa-arrow-left':'←', 'fa-check-circle':'✅', 'fa-save':'💾',
            'fa-trash-alt':'🗑️', 'fa-spinner':'⏳', 'fa-check':'✓', 'fa-chart-bar':'📊',
            'fa-receipt':'🧾','fa-chart-line':'📈','fa-boxes':'📦','fa-users':'👥','fa-gem':'💎',
            'fa-book':'📖','fa-weight-hanging':'⚖️','fa-coins':'🪙','fa-search':'🔍','fa-plus-circle':'➕'
        };

        document.querySelectorAll('i[class*="fa-"]').forEach(function(i){
            // skip icons explicitly marked to preserve (use index.php originals)
            if (i.hasAttribute('data-fa-preserve')) return;
            var classes = i.className.split(/\s+/);
            for (var c of classes) {
                if (c.indexOf('fa-') === 0) {
                    var key = c.trim();
                    if (map[key]) {
                        var span = document.createElement('span');
                        span.textContent = map[key];
                        span.style.fontSize = window.getComputedStyle(i).fontSize || '14px';
                        span.style.display = 'inline-block';
                        span.style.verticalAlign = 'middle';
                        // copy margin classes like mr-1 if present
                        if (i.className.indexOf('mr-1') !== -1) span.style.marginRight = '6px';
                        i.parentNode.replaceChild(span, i);
                    }
                    break;
                }
            }
        });
    }
})();
</script>