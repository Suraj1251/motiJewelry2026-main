<?php
session_start();
require_once 'config/database.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$invoice_no = mysqli_real_escape_string($conn, $_GET['invoice_no'] ?? '');
if(!$invoice_no) { die("Invoice number missing."); }

// Fetch invoice
$inv_res = mysqli_query($conn, "SELECT * FROM invoices WHERE invoice_no = '$invoice_no'");
if(!$inv_res || mysqli_num_rows($inv_res) == 0) {
    die("<h3 style='font-family:sans-serif;padding:20px;'>Invoice not found: ".htmlspecialchars($invoice_no)."</h3><a href='reports.php'><i class='fas fa-arrow-left mr-1'></i> Back to Reports</a>");
}
$inv = mysqli_fetch_assoc($inv_res);

$customer_name = trim($inv['customer_name'] ?? '');
$customer_mobile = trim($inv['customer_mobile'] ?? '');
$customer_address = trim($inv['customer_address'] ?? '');
$customer_gstin = trim($inv['customer_gstin'] ?? '');

$customer_lookup = null;

if(!empty($inv['customer_id'])) {
    $cust_res = mysqli_query($conn, "SELECT name, mobile, email, address, gst_number FROM customers WHERE id = " . intval($inv['customer_id']));
    if($cust_res && mysqli_num_rows($cust_res) > 0) {
        $customer_lookup = mysqli_fetch_assoc($cust_res);
    }
}

if(!$customer_lookup && !empty($customer_mobile)) {
    $cust_res = mysqli_query($conn, "SELECT name, mobile, email, address, gst_number FROM customers WHERE mobile = '" . mysqli_real_escape_string($conn, $customer_mobile) . "' LIMIT 1");
    if($cust_res && mysqli_num_rows($cust_res) > 0) {
        $customer_lookup = mysqli_fetch_assoc($cust_res);
    }
}

if(!$customer_lookup && !empty($customer_name)) {
    $cust_res = mysqli_query($conn, "SELECT name, mobile, email, address, gst_number FROM customers WHERE name = '" . mysqli_real_escape_string($conn, $customer_name) . "' LIMIT 1");
    if($cust_res && mysqli_num_rows($cust_res) > 0) {
        $customer_lookup = mysqli_fetch_assoc($cust_res);
    }
}

if($customer_lookup) {
    $customer_name = trim($customer_lookup['name'] ?? $customer_name);
    $customer_mobile = trim($customer_lookup['mobile'] ?? $customer_mobile);
    $customer_address = trim($customer_lookup['address'] ?? $customer_address);
    $customer_gstin = trim($customer_lookup['gst_number'] ?? $customer_gstin);
}

if($customer_name === '') $customer_name = 'Customer';
if($customer_mobile === '') $customer_mobile = '—';

// Fetch invoice items — build SELECT based on available product columns to avoid SQL errors
$has_serial   = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'serial_no'")) > 0;
$has_hsn      = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'hsn'")) > 0;
$has_hsn_code = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM products LIKE 'hsn_code'")) > 0;

// Select product values preferring products table, but fall back to invoice_items columns (manual entries)
$items_sql = "SELECT ii.invoice_id, ii.product_id, ii.quantity, ii.price, ii.total, ";
$items_sql .= "COALESCE(p.name, ii.product_name, '') AS product_name, ";
if($has_serial) {
    $items_sql .= "COALESCE(p.serial_no, ii.serial_no, '') AS serial_no, ";
} else {
    $items_sql .= "COALESCE(ii.serial_no, '') AS serial_no, ";
}
if($has_hsn) {
    $items_sql .= "COALESCE(p.hsn, p.hsn_code, ii.hsn_code, '') AS hsn_code ";
} elseif($has_hsn_code) {
    $items_sql .= "COALESCE(p.hsn_code, ii.hsn_code, '') AS hsn_code ";
} else {
    $items_sql .= "COALESCE(ii.hsn_code, '') AS hsn_code ";
}
$items_sql .= " FROM invoice_items ii LEFT JOIN products p ON ii.product_id = p.id WHERE ii.invoice_id = " . intval($inv['id']);
$items_res = mysqli_query($conn, $items_sql);
$items = [];
if($items_res) {
    while($row = mysqli_fetch_assoc($items_res)) $items[] = $row;
}

$is_gst   = ($inv['gst_type'] === 'gst');
$gst_total= floatval($inv['gst_amount'] ?? 0);
$cgst     = round($gst_total / 2, 2);
$sgst     = round($gst_total / 2, 2);
$subtotal = floatval($inv['subtotal'] ?? 0);
$total    = floatval($inv['total_amount']);
$paid     = floatval($inv['paid_amount'] ?? 0);
$balance  = floatval($inv['balance_amount'] ?? 0);
$date_fmt = date('d M Y', strtotime($inv['created_at']));
$gstin    = $customer_gstin;

// Number to words
function num2words($n) {
    $n = (int)$n;
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    if($n==0) return 'Zero';
    if($n<20) return $ones[$n];
    if($n<100) return $tens[(int)($n/10)].($n%10?' '.$ones[$n%10]:'');
    if($n<1000) return $ones[(int)($n/100)].' Hundred'.($n%100?' '.num2words($n%100):'');
    if($n<100000) return num2words((int)($n/1000)).' Thousand'.($n%1000?' '.num2words($n%1000):'');
    if($n<10000000) return num2words((int)($n/100000)).' Lakh'.($n%100000?' '.num2words($n%100000):'');
    return num2words((int)($n/10000000)).' Crore'.($n%10000000?' '.num2words($n%10000000):'');
}
$total_words = num2words($total) . ' Rupees Only';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice <?php echo htmlspecialchars($invoice_no); ?> — Moti Jewellers</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Arial,sans-serif;background:#e8e8e8;color:#222;}
.page{width:210mm;min-height:297mm;margin:10px auto;background:#fff;box-shadow:0 0 20px rgba(0,0,0,.25);display:flex;flex-direction:column;}
.header{background:linear-gradient(135deg,#1a0a00,#4a2000,#7a4000);padding:18px 24px;display:flex;justify-content:space-between;align-items:center;}
.logo-wrap{display:flex;align-items:center;gap:12px;}
.mj-circle{width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#FFD700,#B8860B);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:900;color:#1a0a00;border:2px solid #FFD700;flex-shrink:0;}
.shop-name{color:#FFD700;font-size:20px;font-weight:800;letter-spacing:1px;}
.shop-sub{color:#D4AF37;font-size:11px;margin-top:2px;}
.inv-info{text-align:right;}
.inv-info .label{color:#D4AF37;font-size:10px;letter-spacing:1px;}
.inv-info .inv-no{color:#FFD700;font-size:17px;font-weight:800;}
.inv-info .inv-date{color:#c8a96e;font-size:11px;margin-top:3px;}
.status-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:10px;font-weight:700;margin-top:4px;}
.st-paid{background:#d4edda;color:#155724;}
.st-part{background:#fff3cd;color:#856404;}
.st-unpaid{background:#f8d7da;color:#721c24;}
.gst-strip{background:#2a1200;padding:4px 24px;display:flex;gap:24px;border-bottom:2px solid #FFD700;}
.gst-strip span{color:#D4AF37;font-size:10px;}
.gst-strip strong{color:#FFD700;}
.cust-row{display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #e8d5a0;}
.cust-box{padding:12px 24px;}
.cust-box:first-child{border-right:1px solid #e8d5a0;}
.cust-label{font-size:9px;color:#999;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;}
.cust-name{font-size:14px;font-weight:700;color:#1a0a00;}
.cust-det{font-size:11px;color:#555;margin-top:2px;line-height:1.6;}
/* ★ GSTIN badge */
.gstin-badge{display:inline-block;margin-top:4px;font-size:10px;font-weight:700;color:#0f766e;background:#ccfbf1;border:1px solid #5eead4;border-radius:5px;padding:1px 8px;font-family:monospace;letter-spacing:0.5px;}
table.items{width:100%;border-collapse:collapse;}
table.items thead tr{background:linear-gradient(135deg,#3d1f00,#6b3800);}
table.items thead th{padding:8px 10px;font-size:10px;color:#FFD700;font-weight:700;text-align:left;}
table.items thead th.r{text-align:right;}
table.items tbody tr:nth-child(even){background:#fffbf0;}
table.items tbody td{padding:7px 10px;font-size:11px;color:#333;border-bottom:1px solid #f0e0b0;vertical-align:top;}
table.items tbody td.r{text-align:right;}
table.items tfoot tr{background:#fdf3d0;}
table.items tfoot td{padding:6px 10px;font-size:11px;font-weight:600;color:#5a3a00;border-top:1px solid #D4AF37;}
table.items tfoot td.r{text-align:right;font-weight:700;}
.words-bar{background:#fffbf0;border-top:1px solid #e8d5a0;border-bottom:1px solid #e8d5a0;padding:7px 24px;font-size:11px;color:#5a3a00;}
.words-bar strong{color:#1a0a00;}
.bottom-row{display:grid;grid-template-columns:1fr 1fr;border-top:2px solid #D4AF37;flex:1;}
.pay-box{padding:14px 24px;border-right:1px solid #e8d5a0;}
.pay-box h4{font-size:9px;text-transform:uppercase;color:#999;letter-spacing:1px;margin-bottom:8px;}
.pay-line{display:flex;justify-content:space-between;font-size:11px;padding:3px 0;color:#555;}
.pay-line.green{color:#27ae60;font-weight:700;}
.pay-line.red{color:#c0392b;font-weight:700;font-size:12px;}
.tot-box{padding:14px 24px;}
.tot-line{display:flex;justify-content:space-between;font-size:11px;padding:3px 0;border-bottom:1px solid #f5e0a0;color:#555;}
.grand{display:flex;justify-content:space-between;padding:8px 0;font-size:15px;font-weight:900;color:#1a0a00;border-top:2px solid #D4AF37;margin-top:4px;}
.footer{padding:12px 24px;display:flex;justify-content:space-between;align-items:flex-end;border-top:1px solid #e8d5a0;}
.footer-note{font-size:9px;color:#888;max-width:55%;line-height:1.5;}
.sig-line{width:130px;border-top:1px solid #555;margin-top:28px;padding-top:4px;font-size:10px;color:#555;text-align:center;}
.print-bar{background:#1a0a00;padding:10px;display:flex;justify-content:center;gap:10px;}
.print-bar a,.print-bar button{padding:8px 22px;border-radius:6px;font-weight:700;font-size:13px;cursor:pointer;border:none;text-decoration:none;}
.btn-p{background:linear-gradient(135deg,#FFD700,#D4AF37);color:#1a0a00;}
.btn-b{background:transparent;color:#FFD700;border:1px solid #FFD700 !important;}
@media print{.print-bar{display:none!important;}body{background:#fff;}.page{box-shadow:none;margin:0;width:100%;}}
</style>
</head>
<body>
<div class="print-bar">
    <a href="reports.php" class="btn-b"><i class="fas fa-arrow-left mr-1"></i> Back to Reports</a>
    <button class="btn-p" onclick="window.print()">🖨️ Print / Save PDF</button>
</div>
<div class="page">
    <!-- Header -->
    <div class="header">
        <div class="logo-wrap">
            <?php
            $logo_found = false;
            foreach(['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png'] as $lp) {
                if(file_exists($lp)) { echo '<img src="'.$lp.'" alt="Logo" style="width:56px;height:56px;object-fit:contain;">'; $logo_found=true; break; }
            }
            if(!$logo_found) echo '<div class="mj-circle">MJ</div>';
            ?>
            <div>
                <div class="shop-name">MAA GOURI JEWELLERS</div>
                <div class="shop-sub">💎 Gold &amp; Diamond Jewellery</div>
            </div>
        </div>
        <div class="inv-info">
            <div class="label"><?php echo $is_gst ? 'TAX INVOICE' : 'BILL / RECEIPT'; ?></div>
            <div class="inv-no"><?php echo htmlspecialchars($invoice_no); ?></div>
            <div class="inv-date">📅 <?php echo $date_fmt; ?></div>
            <span class="status-badge <?php echo 'st-'.$inv['payment_status']; ?>">
                <?php echo match($inv['payment_status']){'paid'=>'✅ PAID','part'=>'⏳ PART PAID','unpaid'=>'❌ UNPAID',default=>strtoupper($inv['payment_status'])}; ?>
            </span>
        </div>
    </div>

    <?php if($is_gst): ?>
    <div class="gst-strip">
        <span>GST: <strong>Regular (3%)</strong></span>
        <span>CGST: <strong>1.5%</strong></span>
        <span>SGST: <strong>1.5%</strong></span>
        <?php if($gstin !== ''): ?>
        <span>Customer GSTIN: <strong><?php echo htmlspecialchars($gstin); ?></strong></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Customer -->
    <div class="cust-row">
        <div class="cust-box">
            <div class="cust-label">Bill To</div>
            <div class="cust-name"><?php echo htmlspecialchars($customer_name); ?></div>
            <!-- ★ GSTIN shown under customer name -->
            <?php if($gstin !== ''): ?>
                <div><span class="gstin-badge">🧾 GSTIN: <?php echo htmlspecialchars($gstin); ?></span></div>
            <?php endif; ?>
            <div class="cust-det">
                📱 <?php echo htmlspecialchars($customer_mobile); ?><br>
                <?php if(!empty($customer_address)): ?>📍 <?php echo nl2br(htmlspecialchars($customer_address)); ?><?php endif; ?>
            </div>
        </div>
        <div class="cust-box">
            <div class="cust-label">Invoice Details</div>
            <div class="cust-det">
                <strong>Invoice No:</strong> <?php echo htmlspecialchars($invoice_no); ?><br>
                <strong>Date:</strong> <?php echo $date_fmt; ?><br>
                <strong>Payment Mode:</strong> <?php echo ucfirst($inv['payment_method'] ?? 'Cash'); ?><br>
                <strong>Bill Type:</strong> <?php echo $is_gst ? '📄 GST (3%)' : '📋 Non-GST'; ?><br>
                <?php if($gstin !== ''): ?>
                <strong>Customer GSTIN:</strong> <?php echo htmlspecialchars($gstin); ?><br>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Items -->
    <table class="items">
        <thead>
            <tr>
                <th style="width:28px">#</th>
                <th>Product</th>
                <th>S.No</th>
                <?php if($is_gst): ?><th>HSN</th><?php endif; ?>
                <th class="r">Qty (gms)</th>
                <th class="r">Rate (₹)</th>
                <th class="r">Amount (₹)</th>
            </tr>
        </thead>
        <tbody>
        <?php if(empty($items)): ?>
            <tr><td colspan="7" style="text-align:center;padding:18px;color:#888;">No items found.</td></tr>
        <?php else: foreach($items as $idx => $it):
            $name   = htmlspecialchars($it['product_name'] ?? 'Item');
            $serial = htmlspecialchars($it['serial_no'] ?? '—');
            $hsn    = htmlspecialchars($it['hsn_code'] ?? '7113');
            $qty    = floatval($it['quantity']);
            $price  = floatval($it['price']);
            $amount = floatval($it['total']);
        ?>
            <tr>
                <td><?php echo $idx+1; ?></td>
                <td><strong><?php echo $name; ?></strong></td>
                <td style="font-size:10px;color:#777;"><?php echo $serial; ?></td>
                <?php if($is_gst): ?><td><?php echo $hsn; ?></td><?php endif; ?>
                <td class="r"><?php echo number_format($qty, 3); ?></td>
                <td class="r">₹<?php echo number_format($price, 2); ?></td>
                <td class="r">₹<?php echo number_format($amount, 2); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
            <?php $colspan = $is_gst ? 5 : 4; ?>
            <tr><td colspan="<?php echo $colspan;?>"></td><td>Subtotal</td><td class="r">₹<?php echo number_format($subtotal,2);?></td></tr>
            <?php if($is_gst && $gst_total>0): ?>
            <tr><td colspan="<?php echo $colspan;?>"></td><td>CGST (1.5%)</td><td class="r">₹<?php echo number_format($cgst,2);?></td></tr>
            <tr><td colspan="<?php echo $colspan;?>"></td><td>SGST (1.5%)</td><td class="r">₹<?php echo number_format($sgst,2);?></td></tr>
            <?php endif; ?>
        </tfoot>
    </table>

    <!-- Amount in Words -->
    <div class="words-bar"><strong>Amount in Words:</strong> <?php echo $total_words; ?></div>

    <!-- Payment + Grand Total -->
    <div class="bottom-row">
        <div class="pay-box">
            <h4>Payment Info</h4>
            <div class="pay-line green">
                <span>✅ Amount Paid</span>
                <span>₹<?php echo number_format($paid,2); ?></span>
            </div>
            <?php if($balance > 0): ?>
            <div class="pay-line red">
                <span>⚠️ Balance Due</span>
                <span>₹<?php echo number_format($balance,2); ?></span>
            </div>
            <?php else: ?>
            <div class="pay-line green"><span>✅ Fully Paid</span></div>
            <?php endif; ?>
            <div class="pay-line" style="margin-top:6px;font-size:10px;color:#888;">
                Mode: <?php echo ucfirst($inv['payment_method'] ?? 'Cash'); ?>
            </div>
        </div>
        <div class="tot-box">
            <div class="tot-line"><span>Subtotal</span><span>₹<?php echo number_format($subtotal,2);?></span></div>
            <?php if($is_gst && $gst_total>0): ?>
            <div class="tot-line"><span>GST (CGST + SGST)</span><span>₹<?php echo number_format($gst_total,2);?></span></div>
            <?php endif; ?>
            <div class="grand">
                <span>💰 GRAND TOTAL</span>
                <span>₹<?php echo number_format($total,2);?></span>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <div class="footer-note">
            <strong>Maa Gouri Jewellers</strong> — Thank you for your purchase! 💎<br>
            <span style="font-size:9px;">Goods once sold will not be taken back without original bill.</span>
        </div>
        <div><div class="sig-line">Authorised Signatory</div></div>
    </div>
</div>
</body>
</html>