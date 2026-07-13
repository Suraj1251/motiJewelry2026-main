<?php
require_once 'config/database.php';

// ── Handle Add/Edit ─────────────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $name    = trim($_POST['name'] ?? '');
    $mobile  = trim($_POST['mobile'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($_POST['action'] === 'save' && $name !== '' && $mobile !== '') {
        $n = mysqli_real_escape_string($conn, $name);
        $m = mysqli_real_escape_string($conn, $mobile);
        $e = mysqli_real_escape_string($conn, $email);
        $a = mysqli_real_escape_string($conn, $address);
        mysqli_query($conn, "INSERT INTO customers (name, mobile, address, email)
                              VALUES ('$n', '$m', '$a', '$e')
                              ON DUPLICATE KEY UPDATE name='$n', address='$a', email='$e'");
        $msg = 'saved';
    }

    if ($_POST['action'] === 'delete' && !empty($_POST['mobile'])) {
        $m = mysqli_real_escape_string($conn, $_POST['mobile']);
        mysqli_query($conn, "DELETE FROM customers WHERE mobile = '$m'");
        $msg = 'deleted';
    }

    header('Location: contacts.php?msg=' . $msg);
    exit;
}
$msg = $_GET['msg'] ?? '';

// ── Search ───────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $s = mysqli_real_escape_string($conn, $search);
    $sql = "SELECT name, mobile, email, address FROM customers
            WHERE name LIKE '%$s%' OR mobile LIKE '%$s%' OR email LIKE '%$s%'
            ORDER BY name ASC";
} else {
    $sql = "SELECT name, mobile, email, address FROM customers ORDER BY name ASC";
}
$res = mysqli_query($conn, $sql);
if (!$res) die("Query Error: " . mysqli_error($conn));

$customers = [];
while ($row = mysqli_fetch_assoc($res)) {
    $customers[] = $row;
}
$total = count($customers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contacts — gouri Jewellers</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
<style>
    :root { --maroon:#800020; --gold:#d68b16; --gold2:#7a4e0a; --cream:#fdf6e3; --cream2:#f5ead0; }
    body { font-family:'Inter',sans-serif; background:var(--cream); }
    .playfair { font-family:'Playfair Display',serif; }
    .sidebar { width:180px; min-height:100vh; background:linear-gradient(180deg,#7a3a00 0%,#b5730e 100%); }
    .nav-item { display:flex; align-items:center; gap:10px; padding:10px 16px; color:#f5deb3; font-size:13px; font-weight:500; cursor:pointer; border-radius:6px; margin:2px 8px; transition:.15s; text-decoration:none; }
    .nav-item:hover, .nav-item.active { background:rgba(255,255,255,.15); color:#fff; }
    .nav-section { font-size:10px; color:#c8a25a; letter-spacing:1.5px; padding:14px 16px 4px; }
    .card { background:#fff; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,.07); }
    .grad-gold { background:linear-gradient(135deg,var(--gold2),var(--gold)); }
    .btn { border:none; cursor:pointer; font-weight:600; border-radius:8px; transition:.15s; }
    .btn:hover { opacity:.9; transform:translateY(-1px); }
    .row-hover:hover { background:var(--cream2); }
    .avatar { width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:14px;flex-shrink:0; }
    input[type="text"], input[type="tel"], input[type="email"], textarea {
        border:1.5px solid #e5c97a; border-radius:8px; padding:8px 12px; font-size:13px;
        width:100%; outline:none; background:#fffbf0; color:#374151;
    }
    input:focus, textarea:focus { border-color:var(--gold); }
    .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; align-items:center; justify-content:center; z-index:50; }
    .modal-overlay.show { display:flex; }
    .modal-box { background:#fff; border-radius:14px; width:420px; max-width:92vw; box-shadow:0 12px 40px rgba(0,0,0,.25); }
</style>
</head>
<body class="flex">

<!-- ── Sidebar ─────────────────────────────────────────────────────────── -->
<div class="sidebar flex flex-col">
    <div class="flex items-center gap-2 p-4 border-b border-yellow-700/30">
<div style="width:36px;height:36px;border-radius:50%;overflow:hidden;background:#c8a25a;">
    <img src="assets/images/moti-removebg-preview.png"
         alt="Logo"
         style="width:100%;height:100%;object-fit:cover;">
</div>        <div>
            <div style="color:#fff;font-size:13px;font-weight:700;">GOURI JEWELLERS</div>
            <div style="color:#c8a25a;font-size:10px;">Premium Since 2026</div>
        </div>
    </div>

    <nav class="flex-1 py-3">
        <div class="nav-section">MAIN MENU</div>
        <a href="index.php"     class="nav-item">🏠 HOME</a>
        <a href="billing.php"   class="nav-item">🧾 Billing</a>
        <a href="stock.php"     class="nav-item">💎 Stock</a>
        <a href="customers.php" class="nav-item">👥 Customers</a>

        <div class="nav-section">ANALYTICS</div>
        <a href="reports.php"   class="nav-item">📊 Reports</a>
        <a href="income.php"    class="nav-item">📈 Income &amp; Exp</a>

        <div class="nav-section">TOOLS</div>
        <a href="whatsapp.php"  class="nav-item">💬 WhatsApp</a>
        <a href="sanchay.php"   class="nav-item">🏦 Sanchay</a>
        <a href="purchase.php"  class="nav-item">🛒 Purchase</a>
        <a href="contacts.php"  class="nav-item active">📋 Contacts</a>
        <a href="accounts.php"  class="nav-item">📒 Accounts</a>
    </nav>

    <a href="login.php" class="nav-item m-3" style="background:rgba(255,255,255,.1);">🔑 Login</a>
</div>

<!-- ── Main ───────────────────────────────────────────────────────────────── -->
<div class="flex-1 p-6 overflow-auto">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
            <h1 class="playfair text-2xl font-bold" style="color:var(--maroon);">📋 Contacts</h1>
            <p style="color:#9ca3af;font-size:13px;">Customer details — name, mobile, email &amp; address</p>
        </div>
        <button onclick="openModal()" class="btn grad-gold text-white px-4 py-2 text-sm">➕ Add Contact</button>
    </div>

    <?php if ($msg === 'saved'): ?>
    <div class="card p-3 mb-4" style="background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;font-size:13px;">✅ Contact saved successfully.</div>
    <?php elseif ($msg === 'deleted'): ?>
    <div class="card p-3 mb-4" style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;font-size:13px;">🗑️ Contact deleted.</div>
    <?php endif; ?>

    <!-- Search + count -->
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
        <form method="GET" class="flex gap-2" style="max-width:380px;width:100%;">
            <input type="text" name="search" placeholder="🔍 Search by name, mobile or email..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn grad-gold text-white px-4 text-sm">Search</button>
            <?php if ($search !== ''): ?>
            <a href="contacts.php" class="btn px-3 text-sm" style="background:#f3f4f6;color:#6b7280;display:flex;align-items:center;">✕</a>
            <?php endif; ?>
        </form>
        <div style="font-size:12px;color:var(--gold2);font-weight:600;background:var(--cream2);padding:6px 14px;border-radius:99px;">
            👥 <?= $total ?> contact<?= $total !== 1 ? 's' : '' ?>
        </div>
    </div>

    <!-- Contacts list -->
    <div class="card overflow-hidden">
        <div style="background:linear-gradient(135deg,var(--gold2),var(--gold));padding:14px 20px;">
            <div class="playfair text-lg font-bold text-white">Customer Directory</div>
        </div>

        <?php if (empty($customers)): ?>
        <div style="text-align:center;padding:48px 20px;color:#9ca3af;">
            <div style="font-size:40px;margin-bottom:12px;">📭</div>
            <div style="font-size:15px;font-weight:600;">No contacts found</div>
            <div style="font-size:13px;"><?= $search !== '' ? 'Try a different search term' : 'Add your first customer contact' ?></div>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:var(--cream2);">
                        <th style="padding:11px 16px;text-align:left;font-size:11px;color:var(--gold2);font-weight:700;text-transform:uppercase;">Customer</th>
                        <th style="padding:11px 16px;text-align:left;font-size:11px;color:var(--gold2);font-weight:700;text-transform:uppercase;">Mobile</th>
                        <th style="padding:11px 16px;text-align:left;font-size:11px;color:var(--gold2);font-weight:700;text-transform:uppercase;">Email</th>
                        <th style="padding:11px 16px;text-align:left;font-size:11px;color:var(--gold2);font-weight:700;text-transform:uppercase;">Address</th>
                        <th style="padding:11px 16px;text-align:center;font-size:11px;color:var(--gold2);font-weight:700;text-transform:uppercase;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $c):
                    $initial = strtoupper(substr(trim($c['name']) ?: '?', 0, 1));
                    $hue = (crc32($c['mobile']) % 360);
                ?>
                <tr class="row-hover" style="border-bottom:1px solid #f0e6cc;">
                    <td style="padding:12px 16px;">
                        <div class="flex items-center gap-3">
                            <div class="avatar" style="background:hsl(<?= $hue ?>,55%,45%);"><?= htmlspecialchars($initial) ?></div>
                            <span style="font-size:13px;font-weight:600;color:#374151;"><?= htmlspecialchars($c['name']) ?></span>
                        </div>
                    </td>
                    <td style="padding:12px 16px;">
                        <a href="tel:<?= htmlspecialchars($c['mobile']) ?>" style="font-size:13px;color:var(--gold2);font-weight:600;text-decoration:none;">📞 <?= htmlspecialchars($c['mobile']) ?></a>
                    </td>
                    <td style="padding:12px 16px;font-size:13px;color:#6b7280;">
                        <?= $c['email'] ? '<a href="mailto:'.htmlspecialchars($c['email']).'" style="color:#3b82f6;text-decoration:none;">✉️ '.htmlspecialchars($c['email']).'</a>' : '<span style="color:#d1d5db;">—</span>' ?>
                    </td>
                    <td style="padding:12px 16px;font-size:13px;color:#6b7280;max-width:220px;">
                        <?= $c['address'] ? htmlspecialchars($c['address']) : '<span style="color:#d1d5db;">—</span>' ?>
                    </td>
                    <td style="padding:12px 16px;text-align:center;white-space:nowrap;">
                        <button onclick='editModal(<?= json_encode($c) ?>)' class="btn" style="background:#dbeafe;color:#1e3a8a;padding:5px 10px;font-size:11px;">✏️ Edit</button>
                        <button onclick="deleteContact('<?= htmlspecialchars(addslashes($c['mobile'])) ?>','<?= htmlspecialchars(addslashes($c['name'])) ?>')" class="btn" style="background:#fee2e2;color:#991b1b;padding:5px 10px;font-size:11px;">🗑️</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /main -->

<!-- ── Add/Edit Modal ─────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal-box">
        <div style="background:linear-gradient(135deg,var(--gold2),var(--gold));padding:16px 20px;border-radius:14px 14px 0 0;display:flex;justify-content:space-between;align-items:center;">
            <span class="playfair text-lg font-bold text-white" id="modalTitle">Add Contact</span>
            <span onclick="closeModal()" style="cursor:pointer;color:#fff;font-size:18px;">✕</span>
        </div>
        <form method="POST" class="p-5 space-y-3">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="original_mobile" id="originalMobile">
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--gold2);">Name *</label>
                <input type="text" name="name" id="fName" required placeholder="Customer name">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--gold2);">Mobile *</label>
                <input type="tel" name="mobile" id="fMobile" required placeholder="10-digit mobile number">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--gold2);">Email</label>
                <input type="email" name="email" id="fEmail" placeholder="email@example.com">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--gold2);">Address</label>
                <textarea name="address" id="fAddress" rows="2" placeholder="Customer address"></textarea>
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="closeModal()" class="btn flex-1 py-2 text-sm" style="background:#f3f4f6;color:#6b7280;">Cancel</button>
                <button type="submit" class="btn flex-1 py-2 text-sm grad-gold text-white">💾 Save Contact</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Delete confirm form (hidden) ─────────────────────────────────────── -->
<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="mobile" id="deleteMobile">
</form>

<script>
function openModal() {
    document.getElementById('modalTitle').textContent = 'Add Contact';
    document.getElementById('fName').value = '';
    document.getElementById('fMobile').value = '';
    document.getElementById('fMobile').disabled = false;
    document.getElementById('fEmail').value = '';
    document.getElementById('fAddress').value = '';
    document.getElementById('modalOverlay').classList.add('show');
}
function editModal(c) {
    document.getElementById('modalTitle').textContent = 'Edit Contact';
    document.getElementById('fName').value = c.name || '';
    document.getElementById('fMobile').value = c.mobile || '';
    document.getElementById('fEmail').value = c.email || '';
    document.getElementById('fAddress').value = c.address || '';
    document.getElementById('modalOverlay').classList.add('show');
}
function closeModal() {
    document.getElementById('modalOverlay').classList.remove('show');
}
function deleteContact(mobile, name) {
    if(confirm('Delete contact "' + name + '"?')) {
        document.getElementById('deleteMobile').value = mobile;
        document.getElementById('deleteForm').submit();
    }
}
document.getElementById('modalOverlay').addEventListener('click', e => {
    if(e.target.id === 'modalOverlay') closeModal();
});
</script>

</body>
</html>