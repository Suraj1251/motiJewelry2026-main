<?php
session_start();
require_once 'config/database.php';

$is_logged_in = isset($_SESSION['user_id']);

// ── Filters ────────────────────────────────────────────────────────────────
$search      = isset($_GET['search']) ? trim($_GET['search']) : '';
$material    = isset($_GET['material']) ? trim($_GET['material']) : '';
$date_from   = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to     = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$where = [];
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $where[] = "(purchase_no LIKE '%$s%' OR invoice_no LIKE '%$s%' OR supplier_name LIKE '%$s%')";
}
if ($material !== '' && in_array($material, ['Gold','Silver','Diamond','Platinum'])) {
    $m = $conn->real_escape_string($material);
    $where[] = "material_type = '$m'";
}
if ($date_from !== '') {
    $df = $conn->real_escape_string($date_from);
    $where[] = "purchase_date >= '$df'";
}
if ($date_to !== '') {
    $dt = $conn->real_escape_string($date_to);
    $where[] = "purchase_date <= '$dt'";
}
$where_sql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── Handle delete ─────────────────────────────────────────────────────────
$delete_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $del_id = intval($_POST['delete_id']);
    // roll back stock before deleting
    $items_res = $conn->query("SELECT material_type, qty FROM purchase_items WHERE purchase_id = $del_id");
    if ($items_res) {
        while ($row = $items_res->fetch_assoc()) {
            $conn->query("UPDATE stock_metal SET qty_available = qty_available - " . floatval($row['qty']) . " WHERE material_type = '" . $conn->real_escape_string($row['material_type']) . "'");
        }
    }
    $conn->query("DELETE FROM purchase_items WHERE purchase_id = $del_id");
    $conn->query("DELETE FROM purchase_entries WHERE id = $del_id");
    $delete_msg = 'Purchase entry deleted successfully.';
}

// ── Fetch list ────────────────────────────────────────────────────────────
$list_sql = "SELECT id, purchase_no, purchase_date, invoice_no, invoice_date, supplier_name,
                    material_type, qty, unit, total_amount, payment_mode
             FROM purchase_entries
             $where_sql
             ORDER BY purchase_date DESC, id DESC";
$result = $conn->query($list_sql);

// ── Totals for filtered set ───────────────────────────────────────────────
$total_amount_sum = 0;
$total_count = 0;
$rows = [];
if ($result) {
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
        $total_amount_sum += floatval($r['total_amount']);
        $total_count++;
    }
}

function fmt_inr($n) {
    return number_format((float)$n, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Purchase History | Gouri Jewellers</title>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');
*{font-family:'Poppins',sans-serif;box-sizing:border-box;}
h1,h2,h3,.gold-font{font-family:'Playfair Display',serif;}

.sidebar{position:fixed;top:0;left:0;width:240px;height:100vh;background:linear-gradient(180deg,#7a4e0a 0%,#b5730e 40%,#d68b16 100%);z-index:1000;display:flex;flex-direction:column;box-shadow:4px 0 24px rgba(0,0,0,0.25);transition:transform .35s cubic-bezier(.4,0,.2,1);overflow-y:auto;overflow-x:hidden;}
.sidebar::-webkit-scrollbar{width:4px;}.sidebar::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.2);border-radius:4px;}
.sidebar-logo{padding:22px 18px 16px;border-bottom:1px solid rgba(255,255,255,0.18);display:flex;align-items:center;gap:12px;flex-shrink:0;}
.sidebar-logo img{width:44px;height:44px;object-fit:contain;border-radius:50%;background:rgba(255,255,255,0.1);padding:3px;flex-shrink:0;}
.sidebar-logo-text h2{color:#fff;font-size:13px;font-weight:700;font-family:'Playfair Display',serif;letter-spacing:.5px;}
.sidebar-logo-text p{color:rgba(255,255,255,0.65);font-size:10px;margin-top:1px;}
.sidebar-nav{flex:1;padding:10px 0;}
.sidebar-section-label{padding:10px 20px 4px;color:rgba(255,255,255,0.45);font-size:9px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;}
.sidebar-nav a{display:flex;align-items:center;gap:12px;padding:11px 20px;color:rgba(255,255,255,0.85);text-decoration:none;font-size:13px;font-weight:500;transition:all .2s;border-left:3px solid transparent;position:relative;}
.sidebar-nav a:hover{background:rgba(255,255,255,0.13);color:#fff;border-left-color:rgba(255,255,255,0.8);padding-left:26px;}
.sidebar-nav a.active{background:rgba(255,255,255,0.22);color:#fff;border-left-color:#fff;font-weight:700;}
.sidebar-nav a i{width:18px;text-align:center;font-size:14px;flex-shrink:0;}
.sidebar-divider{height:1px;background:rgba(255,255,255,0.12);margin:6px 16px;}
.sidebar-user{padding:14px 16px 18px;border-top:1px solid rgba(255,255,255,0.18);background:rgba(0,0,0,0.12);flex-shrink:0;}
.sidebar-user-info{display:flex;align-items:center;gap:10px;margin-bottom:12px;}
.sidebar-user-info i{color:rgba(255,255,255,0.9);font-size:26px;}
.sidebar-user-info .user-details p{color:#fff;font-size:12px;font-weight:600;}
.sidebar-user-info .user-details span{color:rgba(255,255,255,0.55);font-size:10px;}
.sidebar-logout,.sidebar-login-btn{display:flex;align-items:center;justify-content:center;gap:8px;padding:9px 14px;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;transition:background .2s;border:1px solid rgba(255,255,255,0.3);}
.sidebar-logout{background:rgba(239,68,68,0.75);color:#fff;border-color:rgba(239,68,68,0.4);}
.sidebar-logout:hover{background:#ef4444;}
.sidebar-login-btn{background:rgba(255,255,255,0.2);color:#fff;}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;backdrop-filter:blur(2px);}
.sidebar-overlay.active{display:block;}
.page-wrapper{margin-left:240px;min-height:100vh;}
nav.nav-gold{background:linear-gradient(135deg,#b5730e,#d68b16)!important;}
nav.nav-gold span{color:#fff!important;}
.burger-menu{width:28px;height:20px;position:relative;cursor:pointer;}
.burger-menu span{display:block;position:absolute;height:3px;width:100%;background:#fff;border-radius:3px;transition:all .3s;}
.burger-menu span:nth-child(1){top:0}
.burger-menu span:nth-child(2){top:9px}
.burger-menu span:nth-child(3){top:18px}
@media(max-width:768px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.page-wrapper{margin-left:0!important}.mobile-burger{display:block!important}}
@media(min-width:769px){.mobile-burger{display:none!important}}

.form-card{background:#fff;border-radius:20px;box-shadow:0 8px 40px rgba(0,0,0,0.08);border:1px solid rgba(214,139,22,0.12);padding:24px;}
.field-label{display:block;color:#7a4e0a;font-size:12px;font-weight:600;margin-bottom:5px;letter-spacing:.3px;}
.form-input{width:100%;padding:10px 14px;border-radius:12px;border:1.5px solid rgba(148,163,184,0.3);background:#fbfaf8;color:#334155;font-size:13px;outline:none;transition:border-color .2s,box-shadow .2s;}
.form-input:focus{border-color:#d68b16;box-shadow:0 0 0 3px rgba(214,139,22,0.12);}
.form-select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%23d68b16' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;}
.btn-filter{background:linear-gradient(135deg,#800020,#d68b16);color:#fff;border:none;border-radius:12px;padding:10px 22px;font-size:13px;font-weight:700;cursor:pointer;transition:all .2s;}
.btn-filter:hover{transform:scale(1.03);}
.btn-clear{background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;border-radius:12px;padding:10px 18px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;}
.stat-box{background:linear-gradient(135deg,#fdf6e3,#fff9ed);border:1.5px solid rgba(214,139,22,0.2);border-radius:16px;padding:16px 20px;}
.stat-label{font-size:11px;color:#7a4e0a;font-weight:600;letter-spacing:.4px;text-transform:uppercase;}
.stat-value{font-size:22px;font-weight:700;color:#800020;font-family:'Playfair Display',serif;}

table.hist-table{width:100%;border-collapse:collapse;font-size:12.5px;}
table.hist-table thead th{background:linear-gradient(135deg,#7a4e0a,#b5730e);color:#fff;padding:10px 12px;text-align:left;font-weight:600;white-space:nowrap;}
table.hist-table thead th:first-child{border-top-left-radius:10px;}
table.hist-table thead th:last-child{border-top-right-radius:10px;}
table.hist-table tbody td{padding:10px 12px;border-bottom:1px solid #f0ede8;color:#334155;white-space:nowrap;}
table.hist-table tbody tr:hover{background:#fdf6e3;}
.mat-pill{padding:3px 10px;border-radius:50px;font-size:11px;font-weight:600;display:inline-block;}
.mat-pill.Gold{background:#fef3c7;color:#7a4e0a;}
.mat-pill.Silver{background:#f1f5f9;color:#1e293b;}
.mat-pill.Diamond{background:#ede9fe;color:#4c1d95;}
.mat-pill.Platinum{background:#f0fdf4;color:#14532d;}
.action-btn{padding:6px 10px;border-radius:8px;font-size:11px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:4px;border:none;cursor:pointer;}
.action-view{background:#dbeafe;color:#1e40af;}
.action-delete{background:#fee2e2;color:#991b1b;}
.alert-success{background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;border-radius:12px;padding:14px 18px;font-size:13px;font-weight:600;}
.empty-state{text-align:center;padding:60px 20px;color:#94a3b8;}
</style>
</head>
<body style="background:#F5F5F5;margin:0;padding:0;">

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="sidebar" id="mainSidebar">
    <div class="sidebar-logo">
        <?php
        $logo_paths=['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
        $found=false;
        foreach($logo_paths as $p){if(file_exists($p)){echo '<img src="'.$p.'" alt="Logo">';$found=true;break;}}
        if(!$found) echo '<i class="fas fa-gem" style="color:#fff;font-size:30px;"></i>';
        ?>
        <div class="sidebar-logo-text"><h2>GOURI JEWELLERS</h2><p>Premium Since 2026</p></div>
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
        <a href="income_expenses.php"><i class="fas fa-chart-line"></i> INCOME & EXP</a>
        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Tools</div>
        <a href="whatsapp_automation.php"><i class="fab fa-whatsapp"></i> WHATSAPP</a>
        <a href="purchase.php"><i class="fas fa-shopping-cart"></i> PURCHASE</a>
        <a href="purchase_history.php" class="active"><i class="fas fa-history"></i> PURCHASE HISTORY</a>
        <a href="contacts.php"><i class="fas fa-address-book"></i> CONTACTS</a>
        <a href="accounts.php"><i class="fas fa-file-invoice-dollar"></i> ACCOUNTS</a>
    </nav>
    <div class="sidebar-user">
        <?php if($is_logged_in): ?>
        <div class="sidebar-user-info">
            <i class="fas fa-user-circle"></i>
            <div class="user-details">
                <p><?=htmlspecialchars($_SESSION['user_name'])?></p>
                <span><?=htmlspecialchars($_SESSION['user_mobile']??'Admin')?></span>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout"><i class="fas fa-sign-out-alt"></i> LOGOUT</a>
        <?php else: ?>
        <a href="login.php" class="sidebar-login-btn"><i class="fas fa-sign-in-alt"></i> LOGIN</a>
        <?php endif; ?>
    </div>
</div>

<nav class="nav-gold shadow-lg sticky top-0 z-50" style="margin-left:240px;">
    <div class="container mx-auto px-4 py-3 flex justify-between items-center">
        <h1 style="color:#fff;font-family:'Playfair Display',serif;font-size:18px;font-weight:700;">
            <i class="fas fa-history mr-2"></i>Purchase History
        </h1>
        <div class="flex items-center gap-4">
            <?php if($is_logged_in): ?>
            <span class="text-sm font-medium" style="color:#fff;"><i class="fas fa-user mr-1"></i><?=htmlspecialchars($_SESSION['user_name'])?></span>
            <?php endif; ?>
            <div class="mobile-burger" style="display:none;">
                <div class="burger-menu" id="burgerMenu" onclick="toggleSidebar()">
                    <span></span><span></span><span></span>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="page-wrapper">
<div class="container mx-auto px-4 py-8" style="max-width:1200px;">

<?php if($delete_msg): ?>
<div class="alert-success mb-6"><i class="fas fa-check-circle mr-2"></i><?=$delete_msg?></div>
<?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-1 gap-4 md:grid-cols-3 mb-6">
    <div class="stat-box">
        <div class="stat-label">Total Purchases</div>
        <div class="stat-value"><?=$total_count?></div>
    </div>
    <div class="stat-box">
        <div class="stat-label">Total Amount (Filtered)</div>
        <div class="stat-value">₹ <?=fmt_inr($total_amount_sum)?></div>
    </div>
    <div class="stat-box">
        <div class="stat-label">Quick Action</div>
        <a href="purchase.php" style="color:#800020;font-weight:700;font-size:14px;text-decoration:none;">
            <i class="fas fa-plus-circle mr-1"></i> New Purchase Entry
        </a>
    </div>
</div>

<!-- Filters -->
<div class="form-card mb-6">
    <form method="GET" class="grid grid-cols-1 gap-4 md:grid-cols-5 items-end">
        <div class="md:col-span-2">
            <label class="field-label">Search (Purchase No / Invoice No / Supplier)</label>
            <input type="text" name="search" class="form-input" value="<?=htmlspecialchars($search)?>" placeholder="e.g. PUR2607 or Laxmi Tonch">
        </div>
        <div>
            <label class="field-label">Material</label>
            <select name="material" class="form-input form-select">
                <option value="">All</option>
                <?php foreach(['Gold','Silver','Diamond','Platinum'] as $m): ?>
                <option value="<?=$m?>" <?=$material===$m?'selected':''?>><?=$m?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label">From Date</label>
            <input type="date" name="date_from" class="form-input" value="<?=htmlspecialchars($date_from)?>">
        </div>
        <div>
            <label class="field-label">To Date</label>
            <input type="date" name="date_to" class="form-input" value="<?=htmlspecialchars($date_to)?>">
        </div>
        <div class="md:col-span-5 flex gap-3 justify-end">
            <a href="purchase_history.php" class="btn-clear"><i class="fas fa-times mr-1"></i> Clear</a>
            <button type="submit" class="btn-filter"><i class="fas fa-filter mr-2"></i>Apply Filters</button>
        </div>
    </form>
</div>

<!-- Table -->
<div class="form-card">
    <?php if (count($rows) === 0): ?>
        <div class="empty-state">
            <i class="fas fa-inbox" style="font-size:40px;margin-bottom:12px;display:block;"></i>
            No purchase entries found<?=($search || $material || $date_from || $date_to) ? ' for the selected filters.' : '.'?>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="hist-table">
            <thead>
                <tr>
                    <th>Purchase No</th>
                    <th>Purchase Date</th>
                    <th>Invoice No</th>
                    <th>Supplier</th>
                    <th>Material</th>
                    <th>Qty</th>
                    <th>Payment Mode</th>
                    <th>Total Amount</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td style="font-weight:600;color:#7a4e0a;"><?=htmlspecialchars($row['purchase_no'])?></td>
                    <td><?=htmlspecialchars(date('d-M-Y', strtotime($row['purchase_date'])))?></td>
                    <td><?=htmlspecialchars($row['invoice_no'])?></td>
                    <td><?=htmlspecialchars($row['supplier_name'])?></td>
                    <td><span class="mat-pill <?=htmlspecialchars($row['material_type'])?>"><?=htmlspecialchars($row['material_type'])?></span></td>
                    <td><?=rtrim(rtrim(number_format((float)$row['qty'],4),'0'),'.')?> <?=htmlspecialchars($row['unit'])?></td>
                    <td><?=htmlspecialchars($row['payment_mode'])?></td>
                    <td style="font-weight:700;color:#800020;">₹ <?=fmt_inr($row['total_amount'])?></td>
                    <td>
                        <div class="flex gap-2">
                            <a href="purchase_view.php?id=<?=intval($row['id'])?>" class="action-btn action-view" title="View">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <form method="POST" onsubmit="return confirm('Delete this purchase entry? Stock quantities will be rolled back.');" style="display:inline;">
                                <input type="hidden" name="delete_id" value="<?=intval($row['id'])?>">
                                <button type="submit" class="action-btn action-delete"><i class="fas fa-trash"></i> Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div><!-- /container -->
</div><!-- /page-wrapper -->

<script>
function toggleSidebar(){
    document.getElementById('mainSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}
function closeSidebar(){
    document.getElementById('mainSidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('active');
}
</script>
</body>
</html>