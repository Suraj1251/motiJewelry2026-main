<?php
require_once 'config/database.php';

// ── Ensure required columns exist (safe no-op if already present) ────────
$cols = ['cash_paid', 'upi_paid', 'account_paid', 'cheque_paid', 'old_gold_value'];
foreach ($cols as $c) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE '$c'");
    if ($chk && mysqli_num_rows($chk) == 0) {
        mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN $c DECIMAL(10,2) DEFAULT 0");
    }
}

// ── Month filter ─────────────────────────────────────────────────────────
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));

// ── Fetch all paid/part invoices for the month ────────────────────────────
$monthStartEsc = mysqli_real_escape_string($conn, $monthStart);
$monthEndEsc   = mysqli_real_escape_string($conn, $monthEnd);

$sql = "
    SELECT
        DATE(created_at)               AS day,
        invoice_no,
        payment_method,
        payment_status,
        COALESCE(cash_paid, 0)         AS cash_paid,
        COALESCE(upi_paid, 0)          AS upi_paid,
        COALESCE(account_paid, 0)      AS account_paid,
        COALESCE(cheque_paid, 0)       AS cheque_paid,
        COALESCE(old_gold_value, 0)    AS old_gold_value,
        paid_amount,
        total_amount
    FROM invoices
    WHERE DATE(created_at) BETWEEN '$monthStartEsc' AND '$monthEndEsc'
      AND payment_status != 'unpaid'
    ORDER BY created_at DESC
";
$res = mysqli_query($conn, $sql);
if (!$res) die("Query Error: " . mysqli_error($conn));

// ── Aggregate per day ──────────────────────────────────────────────────────
// cash bucket  = cash_paid
// upi bucket   = upi_paid + account_paid (digital/NEFT)
$days = [];
while ($row = mysqli_fetch_assoc($res)) {
    $d = $row['day'];
    if (!isset($days[$d])) {
        $days[$d] = ['cash' => 0, 'upi' => 0, 'cheque' => 0, 'oldgold' => 0, 'total' => 0, 'bills' => 0];
    }
    $cash    = floatval($row['cash_paid']);
    $upi     = floatval($row['upi_paid']) + floatval($row['account_paid']);
    $cheque  = floatval($row['cheque_paid']);
    $oldgold = floatval($row['old_gold_value']);

    // Fallback: if no split columns populated but a simple method is set
    $method = strtolower(trim($row['payment_method']));
    if ($cash == 0 && $upi == 0 && $cheque == 0 && $oldgold == 0) {
        if (strpos($method, 'cash') !== false) {
            $cash = floatval($row['paid_amount']);
        } elseif (strpos($method, 'upi') !== false) {
            $upi = floatval($row['paid_amount']);
        } elseif (strpos($method, 'neft') !== false) {
            $upi = floatval($row['paid_amount']);
        } else {
            $cash = floatval($row['paid_amount']); // default bucket
        }
    }

    $days[$d]['cash']    += $cash;
    $days[$d]['upi']     += $upi;
    $days[$d]['cheque']  += $cheque;
    $days[$d]['oldgold'] += $oldgold;
    $days[$d]['total']   += floatval($row['paid_amount']);
    $days[$d]['bills']   += 1;
}
ksort($days);
$days = array_reverse($days, true); // latest day first

// ── Month totals ──────────────────────────────────────────────────────────
$monthCash    = array_sum(array_column($days, 'cash'));
$monthUpi     = array_sum(array_column($days, 'upi'));
$monthCheque  = array_sum(array_column($days, 'cheque'));
$monthOldGold = array_sum(array_column($days, 'oldgold'));
$monthTotal   = array_sum(array_column($days, 'total'));
$monthBills   = array_sum(array_column($days, 'bills'));

// ── Today highlight ───────────────────────────────────────────────────────
$today = date('Y-m-d');
$todayCash   = $days[$today]['cash']   ?? 0;
$todayUpi    = $days[$today]['upi']    ?? 0;
$todayTotal  = $days[$today]['total']  ?? 0;
$todayBills  = $days[$today]['bills']  ?? 0;

function fmt($v) {
    return '₹' . number_format($v, 2, '.', ',');
}
function pct($part, $total) {
    return $total > 0 ? round(($part / $total) * 100) : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="author" content="MANU GUPTA">
<title>Accounts — GOURI Jewellers</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --maroon: #800020;
        --gold:   #d68b16;
        --gold2:  #7a4e0a;
        --cream:  #fdf6e3;
        --cream2: #f5ead0;
    }
    body { font-family: 'Inter', sans-serif; background: var(--cream); }
    .playfair { font-family: 'Playfair Display', serif; }
    .sidebar { width: 180px; min-height: 100vh; background: linear-gradient(180deg, #7a3a00 0%, #b5730e 100%); }
    .nav-item { display:flex; align-items:center; gap:10px; padding:10px 16px; color:#f5deb3; font-size:13px; font-weight:500; cursor:pointer; border-radius:6px; margin:2px 8px; transition:.15s; text-decoration:none; }
    .nav-item:hover, .nav-item.active { background:rgba(255,255,255,.15); color:#fff; }
    .nav-section { font-size:10px; color:#c8a25a; letter-spacing:1.5px; padding:14px 16px 4px; }
    .card { background:#fff; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,.07); }
    .grad-maroon { background: linear-gradient(135deg, var(--maroon), #c0002e); }
    .grad-gold   { background: linear-gradient(135deg, var(--gold2), var(--gold)); }
    .bar-cash { background: linear-gradient(90deg, #d68b16, #f5a623); border-radius:99px; height:8px; transition:width .5s ease; }
    .bar-upi  { background: linear-gradient(90deg, #800020, #c0002e); border-radius:99px; height:8px; transition:width .5s ease; }
    .badge-cash  { background:#fef3c7; color:#92400e; border:1px solid #fcd34d; }
    .badge-upi   { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
    .badge-split { background:#dbeafe; color:#1e3a8a; border:1px solid #93c5fd; }
    .day-row { border-bottom:1px solid #f0e6cc; transition:background .15s; }
    .day-row:hover { background: var(--cream2); }
    .today-row { background: linear-gradient(90deg,rgba(214,139,22,.08),transparent); }
    input[type="month"] {
        font-family:'Inter',sans-serif; font-size:13px;
        border:2px solid #e5c97a; border-radius:8px;
        padding:6px 12px; background:#fffbf0; color:#7a4e0a;
        outline:none; cursor:pointer;
    }
    input[type="month"]:focus { border-color:var(--gold); }
    @media print {
        .no-print { display:none !important; }
        body { background:#fff; }
        .card { box-shadow:none; border:1px solid #ddd; }
    }
</style>
</head>
<body class="flex">

<!-- ── Sidebar ─────────────────────────────────────────────────────────── -->
<div class="sidebar no-print flex flex-col">
    <div class="flex items-center gap-2 p-4 border-b border-yellow-700/30">
        <div style="width:36px;height:36px;border-radius:50%;overflow:hidden;background:#c8a25a;">
            <img src="assets/images/moti-removebg-preview.png" alt="Logo" style="width:100%;height:100%;object-fit:cover;">
        </div>
        <div>
            <div style="color:#fff;font-size:13px;font-weight:700;">GOURI JEWELLERS</div>
            <div style="color:#c8a25a;font-size:10px;">Premium Since 2026</div>
        </div>
    </div>

    <nav class="flex-1 py-3">
        <div class="nav-section">MAIN MENU</div>
        <a href="index.php"     class="nav-item"> HOME</a>
        <a href="billing.php"   class="nav-item"> Billing</a>
        <a href="stock.php"     class="nav-item"> Stock</a>
        <a href="customers.php" class="nav-item"> Customers</a>

        <div class="nav-section">ANALYTICS</div>
        <a href="reports.php"   class="nav-item"> Reports</a>
        <a href="income.php"    class="nav-item"> Income &amp; Exp</a>

        <div class="nav-section">TOOLS</div>
        <a href="whatsapp.php"  class="nav-item"> WhatsApp</a>
        <a href="sanchay.php"   class="nav-item"> Sanchay</a>
        <a href="purchase.php"  class="nav-item"> Purchase</a>
        <a href="contacts.php"  class="nav-item"> Contacts</a>
        <a href="accounts.php"  class="nav-item active"> Accounts</a>
    </nav>

    <a href="login.php" class="nav-item m-3" style="background:rgba(255,255,255,.1);">🔑 Login</a>
</div>

<!-- ── Main ───────────────────────────────────────────────────────────────── -->
<div class="flex-1 p-6 overflow-auto">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <h1 class="playfair text-2xl font-bold" style="color:var(--maroon);">📒 Accounts</h1>
            <p style="color:#9ca3af;font-size:13px;">Cash &amp; UPI collection history — <?= htmlspecialchars($monthLabel) ?></p>
        </div>
        <div class="flex gap-3 items-center no-print">
            <form method="GET" class="flex gap-2 items-center">
                <label style="font-size:12px;color:var(--gold2);font-weight:600;">📅 Month:</label>
                <input type="month" name="month" value="<?= htmlspecialchars($month) ?>" onchange="this.form.submit()">
            </form>
            <button onclick="window.print()" style="background:linear-gradient(135deg,var(--gold2),var(--gold));color:#fff;border:none;padding:7px 16px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;">🖨️ Print</button>
        </div>
    </div>

    <!-- ── Today's Summary Cards ─────────────────────────────────────────── -->
    <?php if ($month === date('Y-m')): ?>
    <div class="mb-5">
        <div style="font-size:11px;color:var(--gold2);font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px;">⚡ Today — <?= date('d M Y') ?></div>
        <div class="grid grid-cols-2 gap-3" style="max-width:700px;">
            <div class="card p-4 flex items-center gap-4">
                <div class="grad-gold rounded-xl flex items-center justify-center" style="width:48px;height:48px;font-size:22px;">💵</div>
                <div>
                    <div style="font-size:11px;color:#9ca3af;font-weight:500;">Today Cash</div>
                    <div class="playfair text-xl font-bold" style="color:var(--gold2);"><?= fmt($todayCash) ?></div>
                    <div style="font-size:11px;color:#9ca3af;"><?= $todayBills ?> bill(s)</div>
                </div>
            </div>
            <div class="card p-4 flex items-center gap-4">
                <div class="grad-maroon rounded-xl flex items-center justify-center" style="width:48px;height:48px;font-size:22px;">📲</div>
                <div>
                    <div style="font-size:11px;color:#9ca3af;font-weight:500;">Today UPI</div>
                    <div class="playfair text-xl font-bold" style="color:var(--maroon);"><?= fmt($todayUpi) ?></div>
                    <div style="font-size:11px;color:#9ca3af;"><?= pct($todayUpi, $todayTotal) ?>% of today</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Month Summary Cards ────────────────────────────────────────────── -->
    <div class="grid grid-cols-4 gap-4 mb-6">
        <div class="card p-5">
            <div style="font-size:11px;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Total Collection</div>
            <div class="playfair text-2xl font-bold mt-1" style="color:var(--maroon);"><?= fmt($monthTotal) ?></div>
            <div style="font-size:11px;color:#9ca3af;margin-top:2px;"><?= $monthBills ?> invoices</div>
        </div>
        <div class="card p-5">
            <div style="font-size:11px;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">💵 Cash</div>
            <div class="playfair text-2xl font-bold mt-1" style="color:var(--gold2);"><?= fmt($monthCash) ?></div>
            <div style="font-size:11px;margin-top:4px;">
                <div class="bar-cash" style="width:<?= pct($monthCash,$monthTotal) ?>%"></div>
                <span style="color:#9ca3af;"><?= pct($monthCash,$monthTotal) ?>% of total</span>
            </div>
        </div>
        <div class="card p-5">
            <div style="font-size:11px;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">📲 UPI</div>
            <div class="playfair text-2xl font-bold mt-1" style="color:var(--maroon);"><?= fmt($monthUpi) ?></div>
            <div style="font-size:11px;margin-top:4px;">
                <div class="bar-upi" style="width:<?= pct($monthUpi,$monthTotal) ?>%"></div>
                <span style="color:#9ca3af;"><?= pct($monthUpi,$monthTotal) ?>% of total</span>
            </div>
        </div>
        <div class="card p-5">
            <div style="font-size:11px;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">📅 Active Days</div>
            <div class="playfair text-2xl font-bold mt-1" style="color:#065f46;"><?= count($days) ?></div>
            <div style="font-size:11px;color:#9ca3af;margin-top:2px;">days with sales</div>
        </div>
    </div>

    <?php if ($monthCheque > 0 || $monthOldGold > 0): ?>
    <div class="flex gap-4 mb-5" style="font-size:12px;color:#7a4e0a;">
        <?php if ($monthCheque > 0): ?>
        <div class="card px-4 py-2">🏦 Cheque/Other: <strong><?= fmt($monthCheque) ?></strong></div>
        <?php endif; ?>
        <?php if ($monthOldGold > 0): ?>
        <div class="card px-4 py-2">🥇 Old Gold Adj: <strong><?= fmt($monthOldGold) ?></strong></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Daily History Table ────────────────────────────────────────────── -->
    <div class="card overflow-hidden">
        <div style="background:linear-gradient(135deg,var(--gold2),var(--gold));padding:14px 20px;display:flex;align-items:center;justify-content:space-between;">
            <div class="playfair text-lg font-bold text-white">Daily Collection — <?= htmlspecialchars($monthLabel) ?></div>
            <div style="font-size:12px;color:rgba(255,255,255,.8);"><?= count($days) ?> days</div>
        </div>

        <?php if (empty($days)): ?>
        <div style="text-align:center;padding:48px 20px;color:#9ca3af;">
            <div style="font-size:40px;margin-bottom:12px;">📭</div>
            <div style="font-size:15px;font-weight:600;">No collections found</div>
            <div style="font-size:13px;">No paid invoices for <?= htmlspecialchars($monthLabel) ?></div>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:var(--cream2);">
                        <th style="padding:11px 16px;text-align:left;font-size:11px;color:var(--gold2);font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Date</th>
                        <th style="padding:11px 16px;text-align:center;font-size:11px;color:var(--gold2);font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Bills</th>
                        <th style="padding:11px 16px;text-align:right;font-size:11px;color:var(--gold2);font-weight:700;text-transform:uppercase;letter-spacing:.5px;">💵 Cash</th>
                        <th style="padding:11px 16px;text-align:right;font-size:11px;color:var(--gold2);font-weight:700;text-transform:uppercase;letter-spacing:.5px;">📲 UPI</th>
                        <th style="padding:11px 16px;text-align:right;font-size:11px;color:var(--gold2);font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Total</th>
                        <th style="padding:11px 16px;text-align:center;font-size:11px;color:var(--gold2);font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Mode</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($days as $d => $v):
                    $isToday = ($d === $today);
                    $cashPct = pct($v['cash'], $v['total']);
                    $upiPct  = pct($v['upi'],  $v['total']);
                    $dayLabel = date('D, d M', strtotime($d));
                ?>
                <tr class="day-row <?= $isToday ? 'today-row' : '' ?>">
                    <td style="padding:12px 16px;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <?php if($isToday): ?>
                            <span style="background:var(--maroon);color:#fff;font-size:9px;font-weight:700;padding:2px 7px;border-radius:99px;">TODAY</span>
                            <?php endif; ?>
                            <span style="font-size:13px;font-weight:600;color:#374151;"><?= $dayLabel ?></span>
                        </div>
                    </td>
                    <td style="padding:12px 16px;text-align:center;">
                        <span style="background:var(--cream2);color:var(--gold2);font-size:12px;font-weight:700;padding:3px 10px;border-radius:99px;"><?= $v['bills'] ?></span>
                    </td>
                    <td style="padding:12px 16px;text-align:right;">
                        <?php if($v['cash'] > 0): ?>
                        <div style="font-size:14px;font-weight:700;color:var(--gold2);"><?= fmt($v['cash']) ?></div>
                        <div style="font-size:10px;color:#9ca3af;"><?= $cashPct ?>%</div>
                        <?php else: ?>
                        <span style="color:#d1d5db;font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 16px;text-align:right;">
                        <?php if($v['upi'] > 0): ?>
                        <div style="font-size:14px;font-weight:700;color:var(--maroon);"><?= fmt($v['upi']) ?></div>
                        <div style="font-size:10px;color:#9ca3af;"><?= $upiPct ?>%</div>
                        <?php else: ?>
                        <span style="color:#d1d5db;font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 16px;text-align:right;">
                        <div style="font-size:14px;font-weight:700;color:#111;"><?= fmt($v['total']) ?></div>
                        <div style="display:flex;gap:1px;height:4px;width:80px;margin-left:auto;margin-top:4px;border-radius:99px;overflow:hidden;background:#f3f4f6;">
                            <div style="width:<?= $cashPct ?>%;background:var(--gold);"></div>
                            <div style="width:<?= $upiPct  ?>%;background:var(--maroon);"></div>
                        </div>
                    </td>
                    <td style="padding:12px 16px;text-align:center;">
                        <?php if($v['cash'] > 0 && $v['upi'] > 0): ?>
                        <span class="badge-split" style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:99px;display:inline-block;">Split</span>
                        <?php elseif($v['cash'] > 0): ?>
                        <span class="badge-cash" style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:99px;display:inline-block;">Cash</span>
                        <?php else: ?>
                        <span class="badge-upi" style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:99px;display:inline-block;">UPI</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:linear-gradient(90deg,rgba(122,78,10,.08),rgba(128,0,32,.05));border-top:2px solid #e5c97a;">
                        <td style="padding:13px 16px;font-size:13px;font-weight:700;color:var(--gold2);">📊 <?= htmlspecialchars($monthLabel) ?> Total</td>
                        <td style="padding:13px 16px;text-align:center;font-size:13px;font-weight:700;color:#374151;"><?= $monthBills ?></td>
                        <td style="padding:13px 16px;text-align:right;font-size:14px;font-weight:700;color:var(--gold2);"><?= fmt($monthCash) ?></td>
                        <td style="padding:13px 16px;text-align:right;font-size:14px;font-weight:700;color:var(--maroon);"><?= fmt($monthUpi) ?></td>
                        <td style="padding:13px 16px;text-align:right;font-size:15px;font-weight:800;color:#111;"><?= fmt($monthTotal) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Legend ─────────────────────────────────────────────────────────── -->
    <div class="no-print flex gap-4 mt-4 flex-wrap" style="font-size:11px;color:#9ca3af;">
        <span>💵 <strong>Cash</strong> = cash_paid column</span>
        <span>📲 <strong>UPI</strong> = upi_paid + account_paid (NEFT) columns</span>
        <span>⚠️ Unpaid invoices excluded — only paid/part-paid shown</span>
    </div>

</div><!-- /main -->
</body>
</html>