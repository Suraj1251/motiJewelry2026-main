<?php
session_start();
require_once 'config/database.php';
require_once 'config/mail_config.php';

$theme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light';

// AJAX: Search bills by mobile number
if(isset($_GET['action']) && $_GET['action'] === 'search_mobile') {
    header('Content-Type: application/json');
    $mobile = mysqli_real_escape_string($conn, trim($_GET['mobile'] ?? ''));
    if(empty($mobile)) {
        echo json_encode(['success' => false, 'message' => 'Mobile number required']);
        exit();
    }
    $q = "SELECT i.invoice_no, i.customer_name, i.customer_mobile, i.customer_address,
                 i.total_amount, i.gst_type, i.created_at
          FROM invoices i
          WHERE i.customer_mobile LIKE '%$mobile%'
          ORDER BY i.created_at DESC
          LIMIT 50";
    $res = mysqli_query($conn, $q);
    $bills = [];
    if($res) {
        while($row = mysqli_fetch_assoc($res)) {
            $bills[] = $row;
        }
    }
    echo json_encode(['success' => true, 'bills' => $bills, 'count' => count($bills)]);
    exit();
}

// AJAX: Send part-payment reminder email
if(isset($_GET['action']) && $_GET['action'] === 'send_reminder') {
    header('Content-Type: application/json');
    if($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit();
    }
    $customer_email  = trim($_POST['customer_email'] ?? '');
    $customer_name   = trim($_POST['customer_name'] ?? 'Customer');
    $customer_mobile = trim($_POST['customer_mobile'] ?? '');
    $invoice_no      = trim($_POST['invoice_no'] ?? '');
    $balance_amount  = floatval($_POST['balance_amount'] ?? 0);

    if(empty($customer_email) && !empty($customer_mobile)) {
        $safe_mobile = mysqli_real_escape_string($conn, $customer_mobile);
        $cust_res = mysqli_query($conn, "SELECT email FROM customers WHERE mobile = '$safe_mobile' LIMIT 1");
        if($cust_res && mysqli_num_rows($cust_res) > 0) {
            $cust_row = mysqli_fetch_assoc($cust_res);
            $customer_email = trim($cust_row['email'] ?? '');
        }
    }

    if(empty($customer_email)) {
        echo json_encode(['success' => false, 'message' => 'Customer email is required for reminder. Enter an email address or save the email for this mobile number in the customer record.']);
        exit();
    }
    if($balance_amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'No unpaid amount to remind.']);
        exit();
    }

    $subject = 'Payment Reminder from GOURI JEWELLERS';
    $invoice_text = $invoice_no ? 'Invoice No: ' . htmlspecialchars($invoice_no) . '<br>' : '';
    $message = '<p>Dear ' . htmlspecialchars($customer_name) . ',</p>' .
               '<p>This is a reminder that an amount of <strong>&#8377;' . number_format($balance_amount, 2) . '</strong> is still due.' .
               ($invoice_no ? ' Please refer to ' . htmlspecialchars($invoice_no) . '.' : '') . '</p>' .
               '<p>Please make the remaining payment at your earliest convenience.</p>' .
               '<p>Thank you,<br>GOURI JEWELLERS</p>';
    $sendResult = sendSMTPMail($customer_email, $subject, $message);
    if(!empty($sendResult['success'])) {
        if(!empty($invoice_no)) {
            $safe_invoice_no = mysqli_real_escape_string($conn, $invoice_no);
            mysqli_query($conn, "UPDATE invoices SET reminder_sent = 1 WHERE invoice_no = '$safe_invoice_no'");
        }
        echo json_encode(['success' => true, 'message' => 'Reminder email sent successfully to ' . htmlspecialchars($customer_email) . '.']);
    } else {
        $error = trim($sendResult['message'] ?? 'Failed to send reminder email.');
        echo json_encode(['success' => false, 'message' => 'Failed to send reminder email. ' . htmlspecialchars($error)]);
    }
    exit();
}

// Ensure reminder_sent exists on invoices for due-today filtering
$chk_reminder = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'reminder_sent'");
if($chk_reminder && mysqli_num_rows($chk_reminder) == 0) {
    mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN reminder_sent TINYINT(1) DEFAULT 0");
}

// ── NEW: AJAX: Mark invoice as paid (partial or full custom amount) ───────
if(isset($_GET['action']) && $_GET['action'] === 'mark_paid') {
    header('Content-Type: application/json');
    if($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid method']);
        exit();
    }
    $invoice_no = mysqli_real_escape_string($conn, trim($_POST['invoice_no'] ?? ''));
    $amount = floatval($_POST['amount'] ?? 0);
    if(empty($invoice_no)) {
        echo json_encode(['success' => false, 'message' => 'Invoice number required']);
        exit();
    }
    if($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Enter a valid amount']);
        exit();
    }
    $res = mysqli_query($conn, "SELECT total_amount, paid_amount FROM invoices WHERE invoice_no = '$invoice_no' LIMIT 1");
    if(!$res || mysqli_num_rows($res) === 0) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
        exit();
    }
    $row = mysqli_fetch_assoc($res);
    $total = floatval($row['total_amount']);
    $alreadyPaid = floatval($row['paid_amount']);
    $currentBalance = $total - $alreadyPaid;
    if($amount > $currentBalance + 0.01) {
        echo json_encode(['success' => false, 'message' => 'Amount exceeds balance due (₹' . number_format($currentBalance, 2) . ')']);
        exit();
    }
    $newPaid = $alreadyPaid + $amount;
    $newBalance = max($total - $newPaid, 0);
    $newStatus = ($newBalance <= 0.01) ? 'paid' : 'part';
    $dueDateSql = ($newStatus === 'paid') ? ", due_date=NULL" : "";
    $upd = mysqli_query($conn, "UPDATE invoices SET payment_status='$newStatus', paid_amount=$newPaid, balance_amount=$newBalance$dueDateSql WHERE invoice_no='$invoice_no'");
    if($upd) {
        echo json_encode([
            'success' => true,
            'message' => $newStatus === 'paid' ? ('Invoice ' . $invoice_no . ' fully paid!') : ('Payment of ₹' . number_format($amount, 2) . ' recorded for ' . $invoice_no),
            'fully_paid' => $newStatus === 'paid',
            'new_paid' => $newPaid,
            'new_balance' => $newBalance
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit();
}

// Add PDF column to invoices table if not exists
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'pdf_file'");
if($check_column && mysqli_num_rows($check_column) == 0) {
    mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN pdf_file LONGBLOB");
    mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN pdf_file_name VARCHAR(255)");
}

// Add split payment columns if not exists
$chk_cash = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'cash_paid'");
if($chk_cash && mysqli_num_rows($chk_cash) == 0) {
    mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN cash_paid DECIMAL(10,2) DEFAULT 0");
    mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN upi_paid DECIMAL(10,2) DEFAULT 0");
}

// Add cheque_paid / old_gold_value columns if not exists
$chk_cheque = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'cheque_paid'");
if($chk_cheque && mysqli_num_rows($chk_cheque) == 0) {
    mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN cheque_paid DECIMAL(10,2) DEFAULT 0");
    mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN old_gold_value DECIMAL(10,2) DEFAULT 0");
}

// Add account_paid column (for NEFT) if not exists
$chk_due_date = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'due_date'");
if($chk_due_date && mysqli_num_rows($chk_due_date) == 0) {
    mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN due_date DATE NULL");
}

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Handle PDF Upload
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_pdf'])) {
    $invoice_no = mysqli_real_escape_string($conn, $_POST['invoice_no']);
    if(isset($_FILES['invoice_pdf']) && $_FILES['invoice_pdf']['error'] == 0) {
        $file_ext = mime_content_type($_FILES['invoice_pdf']['tmp_name']);
        if($file_ext === 'application/pdf') {
            $pdf_content = addslashes(file_get_contents($_FILES['invoice_pdf']['tmp_name']));
            $pdf_file_name = mysqli_real_escape_string($conn, $_FILES['invoice_pdf']['name']);
            $update_query = "UPDATE invoices SET pdf_file = '$pdf_content', pdf_file_name = '$pdf_file_name' WHERE invoice_no = '$invoice_no'";
            if(mysqli_query($conn, $update_query)) {
                $pdf_success = "&#10003; PDF uploaded successfully for Invoice: $invoice_no";
            } else {
                $pdf_error = "&#10007; Error uploading PDF: " . mysqli_error($conn);
            }
        } else {
            $pdf_error = "&#10007; Only PDF files are allowed!";
        }
    } else {
        $pdf_error = "&#10007; Please select a PDF file!";
    }
}

// Initialize last invoice variables
$success = '';
$error = '';
$last_invoice_no = '';
$last_bill_type = '';
$last_customer_name = '';
$last_customer_mobile = '';
$last_customer_address = '';
$last_customer_gstin = '';
$last_gst_type = '';
$last_making_charge = 0;
$last_making_charge_amount = 0;
$last_hallmark = 0;
$last_pola = 0;
$last_discount = 0;
$last_items = [];
$last_subtotal = 0;
$last_gst_amount = 0;
$last_cgst_amount = 0;
$last_sgst_amount = 0;
$last_cgst_rate = 0;
$last_sgst_rate = 0;
$last_round_off = 0;
$last_total = 0;
$last_total_quantity = 0;
$last_payment_status = 'paid';
$last_paid_amount = 0;
$last_balance_amount = 0;
$last_payment_method = 'Cash';
$last_cash_paid = 0;
$last_upi_paid = 0;
$last_cheque_paid = 0;
$last_old_gold_value = 0;
$last_is_split = 0;

$logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];

// Fetch products from DB
$all_products = [];
$products_result = mysqli_query($conn, "SELECT id, name, item_name, serial_no, category, price, quantity FROM products ORDER BY category, item_name, name");
if($products_result) {
    while($p = mysqli_fetch_assoc($products_result)) {
        $all_products[] = $p;
    }
}

// Build item type options
$itemTypeOptions = [
    'Gold 22K' => [],
    'Gold 18K' => [],
    'Silver'   => [],
    'Stone'    => [],
    'Diamond'  => [],
    'Others'   => []
];
$categories = ['Gold 22K', 'Gold 18K', 'Silver', 'Stone', 'Diamond', 'Others'];
foreach ($categories as $cat) {
    $safeCat = mysqli_real_escape_string($conn, $cat);
    $res = mysqli_query($conn, "SELECT DISTINCT item_name FROM products WHERE category = '$safeCat' AND item_name != '' ORDER BY item_name");
    if($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            if (!empty($row['item_name'])) {
                $itemTypeOptions[$cat][] = $row['item_name'];
            }
        }
    }
}

// $itemTypeOptions['Gold 22K'] = array_unique(array_merge($itemTypeOptions['Gold 22K'], ['Chur','Bala','Soket Bauti','Bauti Chur','Pearl Sitahar','Pearl Choker','Baby Breslet','Churi','Necklace','Single Loket','Double Loket','Jhuladul','Gents Breslet','Chain','Jhumka','Jhumkolol','Tops','Ladies Ring','Gents Ring','Chokey','Breslet','Ladies Breslet','Tika','Takti','Mantasa','Loket','Mangal Sutra','Moti Chokey','Nosepin','Sankha','Pola','Baby Ring','Bali','Pitaring','Breslet Nova','Steu Nova','Other']));


// $itemTypeOptions['Gold 18K'] = array_unique(array_merge($itemTypeOptions['Gold 18K'], ['Chur','Moti Chokey','Mankasa','Nosepin','Sankha','Breslet Nova','Steu Nova','Pola','Bala','Churi','Necklace','Chain','Jhumka','Jhumkolol','Tops','Ladies Ring','Gents Ring','Chokey','Breslet','Ladies Breslet','Tika','Takti','Mantasa','Loket','Mangal Sutra','Baby Ring','Bali','Pitaring','Other']));


// $itemTypeOptions['Silver']   = array_unique(array_merge($itemTypeOptions['Silver'],  
//  ['Chur','Bala','Churi','Necklace','Chain','Jhumka','Tops','Ladies Ring','Gents Ring',
//  'Breslet','Tika','Loket','Mankha','Payal','Bichiya','Nosering','Baby Ring','Pat (Gross)',
//  'S- (Gross)','Nosepin (Gross)','Sankha','Pola','Silver Thali', 'Silver Bati ', 
//  'Silver Glass', 'Silver Spoon ', 'Silver Showpiece', 'B.B.C Silver', 'Mix Silver', 'Other']));
// $itemTypeOptions['Stone']    = array_unique(array_merge($itemTypeOptions['Stone'],   
//  ['Natural Pearl','Gomed','Red Coral','Nila','Panna','Jerkon','Amethist','Cats Eye','Other']));
// $itemTypeOptions['Diamond']  = array_unique(array_merge($itemTypeOptions['Diamond'],
//   ['Ladies Ring','Gents Ring','Tops','Mangal Sutra','Nose pin','Necklace','Other']));
// $itemTypeOptions['Others']   = array_unique(array_merge($itemTypeOptions['Others'], 
//   ['Shankha','Pala','Mala','Moti Mala','Trasel','Branch Fram','Braslate Pala',
//   'parl Mala','Gala','Reparing','Stamp Charg','Chur','Bala','Churi','Necklace',
//   'Chain','Jhumka','Jhumkolol','Tops','Ladies Ring','Gents Ring','Chokey',
//   'Breslet','Ladies Breslet','Tika','Takti','Mantasa','Loket','Mangal Sutra',
//   'Moti Chokey','Nosepin','Sankha','Pola','Baby Ring','Bali','Pitaring','Breslet Nova','Steu Nova']));
// ── NEW: Fetch due-today payments ─────────────────────────────────────────
$today = date('Y-m-d');
$due_today_result = mysqli_query($conn, "
    SELECT invoice_no, customer_name, customer_mobile, customer_address,
           balance_amount, paid_amount, total_amount, due_date
    FROM invoices
    WHERE due_date = '$today'
      AND balance_amount > 0
      AND payment_status IN ('part', 'unpaid')
      AND (reminder_sent = 0 OR reminder_sent IS NULL)
    ORDER BY customer_name ASC
");
$due_today_bills = [];
if($due_today_result) {
    while($drow = mysqli_fetch_assoc($due_today_result)) {
        $due_today_bills[] = $drow;
    }
}

// Handle billing submission
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_invoice'])) {
    $customer_name    = mysqli_real_escape_string($conn, $_POST['customer_name']);
    $customer_mobile  = mysqli_real_escape_string($conn, $_POST['customer_mobile']);
    $customer_address = mysqli_real_escape_string($conn, $_POST['customer_address'] ?? '');
    $customer_email   = mysqli_real_escape_string($conn, $_POST['customer_email'] ?? '');
    $gst_type         = mysqli_real_escape_string($conn, $_POST['gst_type']);
    $subtotal         = floatval($_POST['subtotal']);
    $making_charge    = floatval($_POST['making_charge'] ?? 0);
    $hallmark         = floatval($_POST['hallmark'] ?? 0);
    $pola             = floatval($_POST['pola'] ?? 0);
    $discount         = floatval($_POST['discount'] ?? 0);
    $payment_status   = mysqli_real_escape_string($conn, $_POST['payment_status'] ?? 'paid');
    $payment_method   = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'Cash');
    $paid_amount      = floatval($_POST['paid_amount'] ?? 0);
    $account_paid     = floatval($_POST['account_paid'] ?? 0);
    $cash_paid        = floatval($_POST['cash_paid'] ?? 0);
    $upi_paid         = floatval($_POST['upi_paid'] ?? 0);
    $cheque_paid      = floatval($_POST['cheque_paid'] ?? 0);
    $old_gold_value   = floatval($_POST['old_gold_value'] ?? 0);
    $is_split         = intval($_POST['is_split_payment'] ?? 0);
    $due_date = mysqli_real_escape_string($conn, $_POST['due_date'] ?? '');
    if(empty($due_date)) $due_date = 'NULL';
    else $due_date = "'" . $due_date . "'";

    if ($is_split) {
        $payment_method = 'Cash+UPI';
        $paid_amount    = $cash_paid + $upi_paid;
    }

    if(strtoupper($payment_method) === 'NEFT') {
        if($paid_amount > 0) $account_paid = $paid_amount;
    }

    $making_charge_amount = $making_charge;
    if ($gst_type === 'gst_3') {
        $cgst_rate = 1.5;
        $sgst_rate = 1.5;
    } elseif ($gst_type === 'gst_18') {
        $cgst_rate = 9;
        $sgst_rate = 9;
    } else {
        $cgst_rate = 0;
        $sgst_rate = 0;
    }
    $cgst_amount = ($subtotal * $cgst_rate) / 100;
    $sgst_amount = ($subtotal * $sgst_rate) / 100;
    $gst_amount  = $cgst_amount + $sgst_amount;
    $subtotal_before_tax = $subtotal + $making_charge_amount + $hallmark + $pola - $discount;
    $total_before_round  = $subtotal_before_tax + $gst_amount;
    $total_amount  = round($total_before_round);
    $round_off     = $total_amount - $total_before_round;

    $chkEmailColumn = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'email'");
    if($chkEmailColumn && mysqli_num_rows($chkEmailColumn) == 0) {
        mysqli_query($conn, "ALTER TABLE customers ADD COLUMN email VARCHAR(255) NULL");
    }
    $customer_query = "INSERT INTO customers (name, mobile, address, email) VALUES ('$customer_name', '$customer_mobile', '$customer_address', '$customer_email')
                       ON DUPLICATE KEY UPDATE name = '$customer_name', address = '$customer_address', email = '$customer_email'";
    mysqli_query($conn, $customer_query);

    // Ensure bill_type column exists
    $chkBillType = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'bill_type'");
    if($chkBillType && mysqli_num_rows($chkBillType) == 0) {
        mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN bill_type VARCHAR(10) DEFAULT 'invoice'");
    }

    $manual_inv = trim($_POST['manual_invoice_no'] ?? '');
    $is_memo    = isset($_POST['is_memo']) && $_POST['is_memo'] === '1';
    $bill_type  = $is_memo ? 'memo' : 'invoice';

    if($is_memo) {
        // MEMO bills are always auto-numbered, starting from 550
        $memoRes = mysqli_query($conn, "SELECT MAX(CAST(SUBSTRING(invoice_no, 6) AS UNSIGNED)) AS max_no FROM invoices WHERE invoice_no LIKE 'MEMO-%'");
        $memoRow = $memoRes ? mysqli_fetch_assoc($memoRes) : null;
        $next_memo = (!empty($memoRow['max_no'])) ? intval($memoRow['max_no']) + 1 : 550;
        if($next_memo < 550) $next_memo = 550;
        $invoice_no = 'MEMO-' . $next_memo;
    } elseif(!empty($manual_inv)) {
        $invoice_no = mysqli_real_escape_string($conn, $manual_inv);
        $dup = mysqli_query($conn, "SELECT id FROM invoices WHERE invoice_no = '$invoice_no'");
        if($dup && mysqli_num_rows($dup) > 0) {
            $error = "&#10007; Invoice No. '$invoice_no' already exists! Please use a different number.";
            goto skip_invoice;
        }
    } else {
        // Auto invoice number, sequential starting from 428
        $invRes2 = mysqli_query($conn, "SELECT MAX(CAST(invoice_no AS UNSIGNED)) AS max_no FROM invoices WHERE invoice_no REGEXP '^[0-9]+$'");
        $invRow2 = $invRes2 ? mysqli_fetch_assoc($invRes2) : null;
        $next_inv = (!empty($invRow2['max_no'])) ? intval($invRow2['max_no']) + 1 : 428;
        if($next_inv < 428) $next_inv = 428;
        $invoice_no = (string)$next_inv;
    }
    $customer_gstin = mysqli_real_escape_string($conn, $_POST['customer_gstin'] ?? '');
    $created_by = $_SESSION['user_id'];

    $chk1 = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'paid_amount'");
    if($chk1 && mysqli_num_rows($chk1) == 0) {
        mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN paid_amount DECIMAL(10,2) DEFAULT 0");
        mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN balance_amount DECIMAL(10,2) DEFAULT 0");
    }
    $chk2 = mysqli_query($conn, "SHOW COLUMNS FROM invoices LIKE 'payment_method'");
    if($chk2 && mysqli_num_rows($chk2) == 0) {
        mysqli_query($conn, "ALTER TABLE invoices ADD COLUMN payment_method VARCHAR(20) DEFAULT 'Cash'");
    }

    $paid_amount += $cheque_paid + $old_gold_value;

    if($paid_amount >= $total_amount && $payment_status !== 'unpaid') {
        $payment_status = 'paid';
    }

    $balance_amount = ($payment_status === 'paid') ? 0 : max(0, $total_amount - $paid_amount);
    if($payment_status === 'paid') {
        $paid_amount = $total_amount;
    }

    if (!$is_split) {
        if ($payment_method === 'Cash') {
            $cash_paid = $paid_amount;
        } elseif ($payment_method === 'UPI') {
            $upi_paid = $paid_amount;
        } elseif (strtoupper($payment_method) === 'NEFT') {
            $account_paid = $paid_amount;
        }
    }

    if($is_split && $paid_amount >= $total_amount) {
        $payment_status = 'paid';
        $balance_amount = 0;
    }

    $invoice_query = "INSERT INTO invoices (invoice_no, customer_name, customer_mobile, customer_address, customer_gstin, gst_type, subtotal, gst_amount, total_amount, payment_status, payment_method, paid_amount, balance_amount, cash_paid, upi_paid, account_paid, cheque_paid, old_gold_value, due_date, created_by, bill_type)
              VALUES ('$invoice_no', '$customer_name', '$customer_mobile', '$customer_address', '$customer_gstin', '$gst_type', $subtotal, $gst_amount, $total_amount, '$payment_status', '$payment_method', $paid_amount, $balance_amount, $cash_paid, $upi_paid, $account_paid, $cheque_paid, $old_gold_value, $due_date, $created_by, '$bill_type')";
    if(mysqli_query($conn, $invoice_query)) {
        $invoice_id = mysqli_insert_id($conn);
        $items = json_decode($_POST['items'], true);
        $col_prod_name = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'product_name'")) > 0;
        if(!$col_prod_name) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN product_name VARCHAR(200) NULL");
        $col_serial = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'serial_no'")) > 0;
        if(!$col_serial) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN serial_no VARCHAR(100) NULL");
        $col_hsn = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM invoice_items LIKE 'hsn_code'")) > 0;
        if(!$col_hsn) mysqli_query($conn, "ALTER TABLE invoice_items ADD COLUMN hsn_code VARCHAR(50) NULL");
        $col_qty_decimal = false;
        $colRes = mysqli_query($conn, "SHOW COLUMNS FROM invoice_items WHERE Field='quantity'");
        if($colRes) {
            $colRow = mysqli_fetch_assoc($colRes);
            if($colRow && stripos($colRow['Type'] ?? '', 'decimal') !== false) $col_qty_decimal = true;
        }
        if(!$col_qty_decimal) mysqli_query($conn, "ALTER TABLE invoice_items MODIFY COLUMN quantity DECIMAL(10,3) NULL");

        if(is_array($items)) {
            foreach($items as $item) {
                $product_id = $item['product_id'] ?? '';
                $quantity   = floatval($item['quantity'] ?? 0);
                $price      = floatval($item['price'] ?? 0);
                $total      = floatval($item['total'] ?? 0);
                $manual_name = mysqli_real_escape_string($conn, trim($item['name'] ?? $item['product'] ?? ''));
                $manual_serial = mysqli_real_escape_string($conn, trim($item['serial'] ?? $item['serial_no'] ?? ''));
                $manual_hsn = mysqli_real_escape_string($conn, trim($item['hsn'] ?? $item['hsn_code'] ?? ''));

                if($product_id === 'other' || !is_numeric($product_id)) {
                    $item_query = "INSERT INTO invoice_items (invoice_id, product_id, product_name, serial_no, hsn_code, quantity, price, total) VALUES ($invoice_id, NULL, '".$manual_name."', '".$manual_serial."', '".$manual_hsn."', $quantity, $price, $total)";
                    mysqli_query($conn, $item_query);
                    continue;
                }
                $pid = intval($product_id);
                $item_query = "INSERT INTO invoice_items (invoice_id, product_id, quantity, price, total)
                               VALUES ($invoice_id, $pid, $quantity, $price, $total)";
                mysqli_query($conn, $item_query);
                mysqli_query($conn, "UPDATE products SET quantity = quantity - $quantity WHERE id = $pid");
            }
        }
        $total_qty = 0;
        if(is_array($items)) foreach($items as $item) { $total_qty += floatval($item['quantity'] ?? 0); }

        if($bill_type === 'memo') {
            $success = "&#10003; MEMO created successfully! MEMO No: " . str_replace('MEMO-', '', $invoice_no) . " | Amount: &#8377;" . number_format($total_amount, 2);
        } else {
            $success = "&#10003; Invoice created successfully! Invoice No: $invoice_no | Amount: &#8377;" . number_format($total_amount, 2);
        }
        $last_invoice_no       = $invoice_no;
        $last_bill_type        = $bill_type;
        $last_customer_name    = $customer_name;
        $last_customer_mobile  = $customer_mobile;
        $last_customer_address = $customer_address;
        $last_customer_gstin   = $customer_gstin;
        $last_gst_type         = $gst_type;
        $last_making_charge    = $making_charge;
        $last_making_charge_amount = $making_charge_amount;
        $last_hallmark         = $hallmark;
        $last_pola             = $pola;
        $last_discount         = $discount;
        $last_items            = is_array($items) ? $items : [];
        $last_subtotal         = $subtotal;
        $last_gst_amount       = $gst_amount;
        $last_cgst_amount      = $cgst_amount;
        $last_sgst_amount      = $sgst_amount;
        $last_cgst_rate        = $cgst_rate;
        $last_sgst_rate        = $sgst_rate;
        $last_round_off        = $round_off;
        $last_total            = $total_amount;
        $last_total_quantity   = $total_qty;
        $last_payment_status   = $payment_status;
        $last_payment_method   = $payment_method;
        $last_paid_amount      = $paid_amount;
        $last_balance_amount   = $balance_amount;
        $last_cash_paid        = $cash_paid;
        $last_upi_paid         = $upi_paid;
        $last_cheque_paid      = $cheque_paid;
        $last_old_gold_value   = $old_gold_value;
        $last_is_split         = $is_split;
    } else {
        $error = "&#10007; Error: " . mysqli_error($conn);
    }
    skip_invoice:
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Billing and Invoice Management for Gouri Jewellers">
    <meta name="keywords" content="Gouri Jewellers, Billing, Invoice, GST, Jewellery Shop">
    <meta name="author" content="MANU GUPTA">
    <title>Billing - Gouri Jewellers</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');
        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3,.gold-font { font-family: 'Playfair Display', serif; }

        /* SIDEBAR */
        .sidebar {
            position: fixed; top: 0; left: 0; width: 240px; height: 100vh;
            background: linear-gradient(180deg, #7a4e0a 0%, #b5730e 40%, #d68b16 100%);
            z-index: 1000; display: flex; flex-direction: column;
            box-shadow: 4px 0 24px rgba(0,0,0,0.25);
            transition: transform 0.35s cubic-bezier(.4,0,.2,1);
            overflow-y: auto; overflow-x: hidden;
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }
        .sidebar-logo {
            padding: 22px 18px 16px; border-bottom: 1px solid rgba(255,255,255,0.18);
            display: flex; align-items: center; gap: 12px; flex-shrink: 0;
        }
        .sidebar-logo img { width: 44px; height: 44px; object-fit: contain; border-radius: 50%; background: rgba(255,255,255,0.1); padding: 3px; }
        .sidebar-logo-text h2 { color: #fff; font-size: 13px; font-weight: 700; line-height: 1.3; font-family: 'Playfair Display', serif; }
        .sidebar-logo-text p { color: rgba(255,255,255,0.65); font-size: 10px; margin-top: 1px; }
        .sidebar-nav { flex: 1; padding: 10px 0; }
        .sidebar-section-label { padding: 10px 20px 4px; color: rgba(255,255,255,0.45); font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 11px 20px; color: rgba(255,255,255,0.85); text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s ease; border-left: 3px solid transparent; position: relative; }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.13); color: #fff; border-left-color: rgba(255,255,255,0.8); padding-left: 26px; }
        .sidebar-nav a.active { background: rgba(255,255,255,0.22); color: #fff; border-left-color: #fff; font-weight: 700; }
        .sidebar-nav a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }
        .sidebar-divider { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 16px; }
        .sidebar-user { padding: 14px 16px 18px; border-top: 1px solid rgba(255,255,255,0.18); background: rgba(0,0,0,0.12); flex-shrink: 0; }
        .sidebar-user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .sidebar-user-info i { color: rgba(255,255,255,0.9); font-size: 26px; }
        .sidebar-user-info .user-details p { color: #fff; font-size: 12px; font-weight: 600; }
        .sidebar-user-info .user-details span { color: rgba(255,255,255,0.55); font-size: 10px; }
        .sidebar-logout { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; background: rgba(239,68,68,0.75); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; transition: background 0.2s; }
        .sidebar-logout:hover { background: #ef4444; color: #fff; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; backdrop-filter: blur(2px); }
        .sidebar-overlay.active { display: block; }
        .page-wrapper { margin-left: 240px; min-height: 100vh; transition: margin-left 0.35s ease; }
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
        }
        @media (min-width: 769px) { .mobile-burger { display: none !important; } }

        /* GENERAL */
        body { background: #F5F5F5; margin: 0; padding: 0; }
        .jewel-card { background: linear-gradient(145deg, #fdf6e3, #f5ead0); border-radius: 16px; border: 1px solid rgba(181,115,14,0.2); box-shadow: 0 4px 20px rgba(181,115,14,0.08); }
        .jewel-input { background: #fff; border: 1.5px solid rgba(181,115,14,0.3); color: #3a1f00; font-size: 13px; transition: border-color 0.2s, box-shadow 0.2s; }
        .jewel-input:focus { outline: none; border-color: #d68b16; box-shadow: 0 0 0 3px rgba(214,139,22,0.15); }
        .jewel-table { border-collapse: collapse; width: 100%; }
        .jewel-table thead tr { background: linear-gradient(135deg, #7a4e0a, #d68b16); }
        .jewel-table thead th { color: #fff; font-size: 11px; padding: 8px 6px; text-align: left; }
        .jewel-table tbody tr { border-bottom: 1px solid rgba(181,115,14,0.12); }
        .jewel-table tbody tr:hover { background: rgba(214,139,22,0.05); }
        .btn-gold { background: linear-gradient(135deg, #d68b16, #b5730e); border: none; color: #fff; font-weight: 700; cursor: pointer; transition: all 0.2s ease; }
        .btn-gold:hover { background: linear-gradient(135deg, #e8a020, #c8830e); box-shadow: 0 4px 16px rgba(214,139,22,0.35); transform: translateY(-1px); }
        .remove-btn { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); border-radius: 6px; padding: 3px 10px; font-size: 11px; cursor: pointer; transition: all 0.2s; }
        .remove-btn:hover { background: #ef4444; color: #fff; }

        /* PRODUCT SELECT TABS */
        .add-mode-tabs { display: flex; gap: 0; margin-bottom: 12px; border-radius: 10px; overflow: hidden; border: 1.5px solid rgba(181,115,14,0.3); }
        .add-mode-tab { flex: 1; padding: 9px 8px; text-align: center; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s; background: #fff; color: #7a4e0a; border: none; }
        .add-mode-tab.active { background: linear-gradient(135deg, #d68b16, #b5730e); color: #fff; }
        .add-mode-panel { display: none; }
        .add-mode-panel.active { display: block; }

        /* SPLIT PAYMENT */
        .split-payment-box { background: linear-gradient(145deg, #f0f9ff, #e0f2fe); border: 1.5px solid rgba(37,99,235,0.2); border-radius: 14px; padding: 16px; margin-top: 12px; }
        .split-input-cash { border: 1.5px solid rgba(5,150,105,0.4) !important; color: #065f46 !important; }
        .split-input-cash:focus { border-color: #059669 !important; box-shadow: 0 0 0 3px rgba(5,150,105,0.15) !important; }
        .split-input-upi { border: 1.5px solid rgba(37,99,235,0.4) !important; color: #1e3a8a !important; }
        .split-input-upi:focus { border-color: #2563eb !important; box-shadow: 0 0 0 3px rgba(37,99,235,0.15) !important; }
        .split-progress-wrap { background: #e5e7eb; border-radius: 999px; height: 10px; overflow: hidden; margin: 10px 0; display: flex; }
        .split-bar-cash { height: 100%; background: linear-gradient(90deg, #059669, #34d399); transition: width 0.35s ease; }
        .split-bar-upi { height: 100%; background: linear-gradient(90deg, #2563eb, #60a5fa); transition: width 0.35s ease; }
        .split-legend { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
        .split-legend-item { display: flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 600; }
        .split-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .split-summary-row { display: flex; justify-content: space-between; align-items: center; font-size: 12px; padding: 3px 0; }

        /* DUE TODAY SECTION */
        .due-today-section { border: 2px solid #fca5a5; border-radius: 16px; overflow: hidden; margin-bottom: 24px; }
        .due-today-header { background: linear-gradient(135deg, #dc2626, #b91c1c); padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; }
        .due-today-grid { background: #fff9f9; padding: 16px; display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
        .due-card { background: #fff; border: 1px solid #fca5a5; border-radius: 12px; padding: 14px; position: relative; transition: box-shadow 0.2s; }
        .due-card:hover { box-shadow: 0 4px 16px rgba(220,38,38,0.12); }
        .due-avatar { width: 40px; height: 40px; border-radius: 50%; background: #fef2f2; border: 1.5px solid #fca5a5; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; color: #dc2626; flex-shrink: 0; }
        .due-action-btn { flex: 1; padding: 8px 6px; border-radius: 8px; font-size: 11px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .due-btn-remind { background: #fef3c7; color: #92400e; border: 1px solid #fbbf24; }
        .due-btn-remind:hover { background: #fde68a; }
        .due-btn-remind:disabled { opacity: 0.6; cursor: default; }
        .due-btn-paid { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .due-btn-paid:hover { background: #a7f3d0; }
        .due-btn-paid:disabled { opacity: 0.6; cursor: default; }
        @keyframes bellRing {
            0%,100%{transform:rotate(0)}
            20%{transform:rotate(-18deg)}
            40%{transform:rotate(18deg)}
            60%{transform:rotate(-10deg)}
            80%{transform:rotate(10deg)}
        }
        .bell-ring { animation: bellRing 2s ease-in-out infinite; display: inline-block; }

        /* LOADING OVERLAY */
        @keyframes ornFloat { 0%,100%{transform:rotate(0deg) scale(1);opacity:0.15} 50%{transform:rotate(20deg) scale(1.15);opacity:0.28} }
        @keyframes haloPulse { 0%,100%{opacity:0.3;transform:scale(1)} 50%{opacity:1;transform:scale(1.1)} }
        @keyframes gemGlowPulse { 0%,100%{filter:drop-shadow(0 0 8px #d68b16)} 50%{filter:drop-shadow(0 0 22px #ff9900)} }
        @keyframes titleGold { from{color:#d68b16} to{color:#f5c842} }
        @keyframes barSlide { 0%{transform:translateX(-100%)} 100%{transform:translateX(480%)} }
        @keyframes dotBounce { 0%,100%{opacity:0.3;transform:scale(0.7)} 50%{opacity:1;transform:scale(1.2)} }
        @keyframes notifSlide { from{transform:translateX(400px);opacity:0} to{transform:translateX(0);opacity:1} }
        .jewel-sparkle { position: fixed; border-radius: 50%; pointer-events: none; z-index: 0; animation: sparkleFloat linear infinite; }
        @keyframes sparkleFloat { 0%{transform:translateY(100vh) scale(0);opacity:0} 10%{opacity:1} 90%{opacity:0.5} 100%{transform:translateY(-10vh) scale(1);opacity:0} }

        @media print {
            body * { visibility: hidden; }
            .print-invoice, .print-invoice * { visibility: visible; }
            .print-invoice { position: absolute; left: 0; top: 0; width: 100%; }
            .no-print { display: none !important; }
            .sidebar, .sidebar-overlay, nav.nav-gold { display: none !important; }
        }
    </style>
</head>
<body class="<?php echo $theme == 'light' ? 'light-theme' : 'dark-theme'; ?>">

<script>
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
function createJewelSparkles() {
    const colors = ['#d68b16','#b5730e','#800020','#c9a96e','#f5c842'];
    document.querySelectorAll('.jewel-sparkle').forEach(s => s.remove());
    for(let i = 0; i < 40; i++) {
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
window.addEventListener('load', function() {
    createJewelSparkles();
    setTimeout(function() {
        const ov = document.getElementById('loadingOverlay');
        if(ov) { ov.style.opacity = '0'; ov.style.visibility = 'hidden'; setTimeout(()=>ov.style.display='none', 500); }
    }, 1800);
});
</script>

<!-- Loading Overlay -->
<div id="loadingOverlay" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999;display:flex;justify-content:center;align-items:center;overflow:hidden;transition:opacity 0.6s ease,visibility 0.6s ease;background:radial-gradient(ellipse at 50% 60%, #1a0a00 0%, #0d0500 100%);">
    <div style="position:relative;z-index:10;text-align:center;">
        <div style="position:relative;width:110px;height:110px;margin:0 auto 28px;">
            <div style="position:absolute;inset:-12px;border-radius:50%;border:2px solid rgba(214,139,22,0.4);animation:haloPulse 1.5s ease-in-out infinite;"></div>
            <div style="position:absolute;inset:-24px;border-radius:50%;border:1px solid rgba(214,139,22,0.2);animation:haloPulse 1.5s ease-in-out infinite 0.5s;"></div>
            <img src="./assets/images/moti-removebg-preview.png" alt="Gouri Jewellers Logo" style="width:100%;height:100%;object-fit:contain;filter:drop-shadow(0 0 8px #d68b16);animation:gemGlowPulse 1.5s ease-in-out infinite;">
        </div>
        <div style="color:#d68b16;font-size:22px;letter-spacing:6px;font-family:'Playfair Display',serif;margin-bottom:6px;animation:titleGold 2s ease infinite alternate;">GOURI JEWELLERS</div>
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
</div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<div class="sidebar" id="mainSidebar">
    <div class="sidebar-logo">
        <?php
        $logo_found = false;
        foreach($logo_paths as $path) {
            if(file_exists($path)) {
                echo '<img src="'.$path.'" alt="Logo">';
                $logo_found = true; break;
            }
        }
        if(!$logo_found) echo '<i class="fas fa-gem" style="color:#fff;font-size:30px;flex-shrink:0;"></i>';
        ?>
        <div class="sidebar-logo-text">
            <h2>GOURI JEWELLERS</h2>
            <p>Premium Since 2026</p>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="sidebar-section-label">Main Menu</div>

        <a href="index.php" >
            <i class="fas fa-home"></i> HOME
        </a>
        <a href="billing.php">
            <i class="fas fa-receipt" class="active"></i> BILLING
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
        <!-- <a href="sbook.php">
            <i class="fas fa-book"></i> SANCHAY
        </a> -->
        <a href="purchase.php">
            <i class="fas fa-book"></i> PURCHASE
        </a>
        <a href="contacts.php">
            <i class="fas fa-address-book"></i> CONTACTS
        </a>
        <a href="accounts.php">
            <i class="fas fa-calculator"></i> ACCOUNTS
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

<!-- TOP NAVBAR -->
<nav class="nav-gold shadow-lg sticky top-0 z-50 no-print" style="margin-left:240px;">
    <div class="container mx-auto px-4 sm:px-6 py-3">
        <div class="flex justify-between items-center">
            <div class="flex items-center gap-4">
                <div class="mobile-burger" style="display:none;">
                    <div class="burger-menu" id="burgerMenu" onclick="toggleSidebar()">
                        <span></span><span></span><span></span>
                    </div>
                </div>
                <span class="font-bold text-white text-sm hidden sm:inline" style="font-family:'Playfair Display',serif;">
                    <i class="fas fa-receipt mr-2"></i>Billing
                </span>
            </div>
            <span class="text-sm font-medium text-white">
                <i class="fas fa-user mr-1"></i>
                <?php echo htmlspecialchars($_SESSION['user_name']); ?>
            </span>
        </div>
    </div>
</nav>

<!-- PAGE WRAPPER -->
<div class="page-wrapper">
<div class="container mx-auto px-4 sm:px-6 py-6 sm:py-8 no-print">

    <?php if($success): ?>
        <div class="mb-4 p-4 rounded-lg" style="background:#f0fdf4;border:1px solid #86efac;color:#166534;"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if($error): ?>
        <div class="mb-4 p-4 rounded-lg" style="background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;"><?php echo $error; ?></div>
    <?php endif; ?>
    <?php if(isset($pdf_success)): ?>
        <div class="mb-4 p-4 rounded-lg" style="background:#f0fdf4;border:1px solid #86efac;color:#166534;"><?php echo $pdf_success; ?></div>
    <?php endif; ?>
    <?php if(isset($pdf_error)): ?>
        <div class="mb-4 p-4 rounded-lg" style="background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;"><?php echo $pdf_error; ?></div>
    <?php endif; ?>

    <!-- Page Title -->
    <div class="mb-6">
        <h2 class="text-2xl sm:text-3xl font-bold" style="color:#800020;font-family:'Playfair Display',serif;">
            <i class="fas fa-receipt mr-2" style="color:#d68b16;"></i> Billing
        </h2>
        <p class="text-sm mt-1" style="color:#7a4e0a;">Create invoices and manage customer transactions</p>
    </div>

    <!-- ══ PAYMENT DUE TODAY SECTION ══ -->

<?php if(!empty($due_today_bills)): ?>
    <div class="due-today-section">
        <!-- Header -->
        <div class="due-today-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <span class="bell-ring" style="font-size:22px;color:#fff;" title="Due Today">&#128276;</span>
                <div style="flex:1;min-width:0;">
                    <div style="color:#fff;font-weight:700;font-size:15px;font-family:'Playfair Display',serif;">
                        Payment Due Today
                    </div>
                    <div style="color:rgba(255,255,255,0.78);font-size:11px;">
                        <?php echo count($due_today_bills); ?> customer<?php echo count($due_today_bills) > 1 ? 's' : ''; ?> have pending balance due today
                    </div>
                </div>
                <button type="button" class="due-close-btn" onclick="closeDueTodaySection()" title="Hide this section"
                    style="background:rgba(255,255,255,0.18);border:none;color:#fff;font-size:18px;line-height:1;width:32px;height:32px;border-radius:999px;cursor:pointer;">
                    &times;
                </button>
            </div>
            <span style="background:rgba(255,255,255,0.22);color:#fff;font-size:12px;font-weight:700;padding:4px 14px;border-radius:20px;">
                <?php echo date('d M Y'); ?>
            </span>
        </div>

        <!-- Cards Grid -->
        <div class="due-today-grid">
            <?php foreach($due_today_bills as $db):
                $words = explode(' ', trim($db['customer_name']));
                $initials = strtoupper(substr($words[0], 0, 1));
                if(count($words) >= 2) $initials .= strtoupper(substr($words[1], 0, 1));
                $pct = $db['total_amount'] > 0 ? min(100, round(($db['paid_amount'] / $db['total_amount']) * 100)) : 0;
                $safe_inv  = htmlspecialchars($db['invoice_no']);
                $safe_name = htmlspecialchars(addslashes($db['customer_name']));
                $safe_mob  = htmlspecialchars($db['customer_mobile']);
                $balance   = floatval($db['balance_amount']);
            ?>
            <div class="due-card" id="duecard-<?php echo $safe_inv; ?>">

                <!-- Customer info row -->
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                    <div class="due-avatar"><?php echo $initials; ?></div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:700;font-size:13px;color:#991b1b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?php echo htmlspecialchars($db['customer_name']); ?>
                        </div>
                        <div style="font-size:11px;color:#9ca3af;">
                            <?php echo htmlspecialchars($db['customer_mobile']); ?>
                            &nbsp;&middot;&nbsp;
                            <span style="color:#b5730e;font-weight:600;"><?php echo $safe_inv; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Amount boxes -->
                <div style="display:flex;gap:8px;margin-bottom:12px;">
                    <div style="flex:1;background:#fef2f2;border-radius:8px;padding:8px 10px;text-align:center;">
                        <div style="font-size:10px;color:#9ca3af;margin-bottom:2px;">Balance Due</div>
                        <div style="font-size:15px;font-weight:700;color:#dc2626;">
                            &#8377;<?php echo number_format($db['balance_amount'], 2); ?>
                        </div>
                    </div>
                    <div style="flex:1;background:#f0fdf4;border-radius:8px;padding:8px 10px;text-align:center;">
                        <div style="font-size:10px;color:#9ca3af;margin-bottom:2px;">Invoice Total</div>
                        <div style="font-size:13px;font-weight:600;color:#059669;">
                            &#8377;<?php echo number_format($db['total_amount'], 2); ?>
                        </div>
                    </div>
                </div>

                <!-- Progress bar -->
                <div style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;font-size:10px;color:#9ca3af;margin-bottom:3px;">
                        <span>Already paid: &#8377;<?php echo number_format($db['paid_amount'], 2); ?></span>
                        <span><?php echo $pct; ?>%</span>
                    </div>
                    <div style="background:#fee2e2;border-radius:999px;height:7px;overflow:hidden;">
                        <div style="height:100%;width:<?php echo $pct; ?>%;background:linear-gradient(90deg,#059669,#34d399);border-radius:999px;transition:width 0.4s ease;"></div>
                    </div>
                </div>

                <!-- Action buttons -->
                <div style="display:flex;gap:8px;">
                    <button type="button" class="due-action-btn due-btn-remind"
                        id="remind-btn-<?php echo $safe_inv; ?>"
                        onclick="sendDueReminder('<?php echo $safe_inv; ?>', '<?php echo $safe_name; ?>', '<?php echo $safe_mob; ?>', <?php echo $balance; ?>)">
                        &#128231; Send Reminder
                    </button>
                    <button type="button" class="due-action-btn due-btn-paid"
                        id="paid-btn-<?php echo $safe_inv; ?>"
                        onclick="markDueAsPaid('<?php echo $safe_inv; ?>', '<?php echo $safe_name; ?>', '<?php echo $safe_mob; ?>', <?php echo $db['total_amount']; ?>, <?php echo $db['paid_amount']; ?>, <?php echo $balance; ?>)">
                        &#10003; Mark as Paid
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>


<!-- ============================================================ -->
<!-- NEW: Payment Modal (paste once, anywhere after the section    -->
<!-- above — e.g. right before </body>)                            -->
<!-- ============================================================ -->

<div id="paymentModalOverlay" class="payment-modal-overlay" onclick="if(event.target===this) closePaymentModal()">
  <div class="payment-modal">
    <div class="payment-modal-header">
      <h3>Mark Payment</h3>
      <button onclick="closePaymentModal()">&times;</button>
    </div>
    <div class="payment-modal-body">
      <div style="font-weight:700;color:#991b1b;" id="pmCustomerName"></div>
      <div style="font-size:12px;color:#9ca3af;margin-bottom:10px;" id="pmCustomerMob"></div>
      <div class="pm-rows">
        <div><span>Invoice Total</span><span id="pmTotal"></span></div>
        <div><span>Already Paid</span><span id="pmAlreadyPaid"></span></div>
        <div><span>Balance Due</span><span id="pmBalance"></span></div>
      </div>
      <label style="font-size:12px;color:#6b7280;display:block;margin-top:10px;">Amount Receiving Now (&#8377;)</label>
      <input type="number" id="pmAmountInput" min="0" step="0.01" oninput="updateRemainingPreview()">
      <div style="font-size:13px;font-weight:600;color:#dc2626;">
        Remaining After This Payment: <span id="pmRemainingPreview"></span>
      </div>
      <div id="pmError" style="color:#dc2626;font-size:12px;margin-top:6px;"></div>
    </div>
    <div class="payment-modal-footer">
      <button onclick="closePaymentModal()">Cancel</button>
      <button onclick="submitPayment()">Confirm Payment</button>
    </div>
  </div>
</div>


<!-- ============================================================ -->
<!-- NEW: CSS — paste inside your existing <style> tag              -->
<!-- ============================================================ -->
<style>
.payment-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;}
.payment-modal{background:#fff;border-radius:12px;width:360px;max-width:90%;padding:20px;box-shadow:0 10px 40px rgba(0,0,0,0.2);}
.payment-modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;}
.payment-modal-header h3{margin:0;font-family:'Playfair Display',serif;color:#991b1b;}
.payment-modal-header button{background:none;border:none;font-size:20px;cursor:pointer;color:#9ca3af;}
.pm-rows div{display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;color:#374151;}
#pmAmountInput{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:15px;margin:8px 0;box-sizing:border-box;}
.payment-modal-footer{display:flex;gap:10px;margin-top:14px;}
.payment-modal-footer button{flex:1;padding:10px;border-radius:8px;border:none;cursor:pointer;font-weight:600;}
.payment-modal-footer button:first-child{background:#f3f4f6;color:#374151;}
.payment-modal-footer button:last-child{background:#059669;color:#fff;}
</style>


<!-- ============================================================ -->
<!-- NEW: JS — paste inside your existing <script> tag              -->
<!-- ============================================================ -->
<script>
let currentPaymentData = {};

function markDueAsPaid(invoiceNo, customerName, mobile, totalAmount, paidAmount, balanceAmount) {
  currentPaymentData = {
    invoice_no: invoiceNo,
    total: parseFloat(totalAmount),
    paid: parseFloat(paidAmount),
    balance: parseFloat(balanceAmount)
  };

  document.getElementById('pmCustomerName').textContent = customerName;
  document.getElementById('pmCustomerMob').textContent = mobile;
  document.getElementById('pmTotal').textContent = '₹' + currentPaymentData.total.toFixed(2);
  document.getElementById('pmAlreadyPaid').textContent = '₹' + currentPaymentData.paid.toFixed(2);
  document.getElementById('pmBalance').textContent = '₹' + currentPaymentData.balance.toFixed(2);

  const input = document.getElementById('pmAmountInput');
  input.value = currentPaymentData.balance.toFixed(2);
  input.max = currentPaymentData.balance;
  document.getElementById('pmError').textContent = '';
  updateRemainingPreview();

  document.getElementById('paymentModalOverlay').style.display = 'flex';
}

function closePaymentModal() {
  document.getElementById('paymentModalOverlay').style.display = 'none';
}

function updateRemainingPreview() {
  const amount = parseFloat(document.getElementById('pmAmountInput').value) || 0;
  const remaining = Math.max(currentPaymentData.balance - amount, 0);
  document.getElementById('pmRemainingPreview').textContent = '₹' + remaining.toFixed(2);
}

function submitPayment() {
  const amount = parseFloat(document.getElementById('pmAmountInput').value);
  const errorEl = document.getElementById('pmError');
  errorEl.textContent = '';

  if (isNaN(amount) || amount <= 0) {
    errorEl.textContent = 'Please enter a valid amount.';
    return;
  }
  if (amount > currentPaymentData.balance + 0.01) {
    errorEl.textContent = 'Amount cannot exceed balance due.';
    return;
  }

  const btn = document.querySelector('.payment-modal-footer button:last-child');
  btn.disabled = true;
  btn.textContent = 'Processing...';

  fetch(window.location.pathname + '?action=mark_paid', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    credentials: 'same-origin',
    body: 'invoice_no=' + encodeURIComponent(currentPaymentData.invoice_no) + '&amount=' + encodeURIComponent(amount)
  })
  .then(res => res.json())
  .then(data => {
    btn.disabled = false;
    btn.textContent = 'Confirm Payment';
    if (data.success) {
      closePaymentModal();
      showNotif('✅ ' + data.message, 'success');
      if (data.fully_paid) {
        const card = document.getElementById('duecard-' + currentPaymentData.invoice_no);
        if (card) {
          card.style.transition = 'opacity 0.3s, height 0.3s, margin 0.3s, padding 0.3s';
          card.style.opacity = '0';
          card.style.height = '0';
          card.style.margin = '0';
          card.style.padding = '0';
          setTimeout(() => { card.remove(); hideDueTodaySectionIfEmpty(); }, 300);
        }
      } else {
        location.reload();
      }
    } else {
      errorEl.textContent = data.message || 'Something went wrong.';
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Confirm Payment';
    errorEl.textContent = 'Network error. Try again.';
  });
}
</script>
    <!-- ══ END PAYMENT DUE TODAY SECTION ══ -->

    <!-- Search Bill by Mobile -->
    <div class="jewel-card p-4 sm:p-5 mb-6">
        <h3 class="text-base font-bold mb-3" style="color:#7a4e0a;">
            <i class="fas fa-search mr-2" style="color:#d68b16;"></i> Search Bill by Mobile Number
        </h3>
        <div class="flex flex-col sm:flex-row gap-3">
            <input type="tel" id="searchMobile" placeholder="&#128241; Enter Customer Mobile Number..."
                class="jewel-input flex-1 rounded-lg px-4 py-2 text-sm" maxlength="15"
                oninput="this.value=this.value.replace(/[^0-9]/g,'')">
            <button onclick="searchBillsByMobile()" class="btn-gold px-6 py-2 rounded-lg text-sm font-bold">&#128269; Search</button>
            <button onclick="clearSearch()" class="px-4 py-2 rounded-lg text-sm font-semibold"
                style="background:#fff;border:1.5px solid rgba(181,115,14,0.4);color:#7a4e0a;">&#10006; Clear</button>
        </div>
        <div id="searchResults" class="mt-4 hidden">
            <div id="searchResultsContent"></div>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Billing Form -->
        <div class="lg:col-span-2">
            <div class="jewel-card p-4 sm:p-6">
                <h2 class="text-xl sm:text-2xl font-bold mb-5" style="color:#800020;font-family:'Playfair Display',serif;">
                    <?php foreach($logo_paths as $path) { if(file_exists($path)) { echo '<img src="'.$path.'" style="width:32px;height:32px;object-fit:contain;display:inline-block;vertical-align:middle;margin-right:8px;">'; break; } } ?>
                    Create New Invoice
                </h2>

                <form method="POST" id="billingForm" enctype="multipart/form-data">

                    <!-- Invoice Number -->
                    <div class="mb-4 p-3 rounded-xl" style="background:rgba(214,139,22,0.05);border:1px solid rgba(214,139,22,0.2);">
                        <div class="flex items-center gap-3 mb-2 flex-wrap">
                            <label class="text-sm font-semibold" style="color:#7a4e0a;">&#128290; Invoice Number</label>
                            <label id="manualInvoiceLabel" class="flex items-center gap-2 cursor-pointer text-xs" style="color:#9ca3af;">
                                <input type="checkbox" id="manualInvoiceToggle" onchange="toggleManualInvoice()" style="accent-color:#d68b16;">
                                Enter Manual Invoice No.?
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer text-xs" style="color:#cc4400;font-weight:600;">
                                <input type="checkbox" id="memoBillToggle" name="is_memo" value="1" onchange="toggleMemoBill()" style="accent-color:#cc4400;">
                                MEMO Bill? (No Invoice No.)
                            </label>
                        </div>
                        <div id="manualInvoiceDiv" style="display:none;">
                            <input type="text" name="manual_invoice_no" id="manualInvoiceNo"
                                class="jewel-input w-full rounded-lg px-3 py-2 text-sm" placeholder="e.g. 428">
                            <p class="text-xs mt-1" style="color:#9ca3af;">&#9888;&#65039; If empty, auto-generated starting from 428</p>
                        </div>
                        <div id="autoInvoiceInfo" class="text-xs" style="color:#9ca3af;">Auto-generated (sequential, starting from 428)</div>
                        <div id="memoInvoiceInfo" class="text-xs" style="display:none;color:#cc4400;font-weight:600;"></div>
                    </div>

                    <!-- Customer Details -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                        <div>
                            <label class="block mb-1 text-sm font-semibold" style="color:#7a4e0a;">Customer Name *</label>
                            <input type="text" name="customer_name" id="customerName" required
                                class="jewel-input w-full rounded-lg px-3 py-2 text-sm" placeholder="Full Name">
                        </div>
                        <div>
                            <label class="block mb-1 text-sm font-semibold" style="color:#7a4e0a;">Mobile Number *</label>
                            <input type="tel" name="customer_mobile" id="customerMobile" required
                                class="jewel-input w-full rounded-lg px-3 py-2 text-sm" placeholder="10-digit mobile">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block mb-1 text-sm font-semibold" style="color:#7a4e0a;">Address</label>
                            <input type="text" name="customer_address" id="customerAddress"
                                class="jewel-input w-full rounded-lg px-3 py-2 text-sm" placeholder="Customer Address">
                        </div>
                        <div>
                            <label class="block mb-1 text-sm font-semibold" style="color:#7a4e0a;">Email <span style="color:#9ca3af;font-size:11px;">(Optional, required for reminder email)</span></label>
                            <input type="email" name="customer_email" id="customerEmail"
                                class="jewel-input w-full rounded-lg px-3 py-2 text-sm" placeholder="customer@email.com">
                        </div>
                        <div>
                            <label class="block mb-1 text-sm font-semibold" style="color:#7a4e0a;">GSTIN <span style="color:#9ca3af;font-size:11px;">(Optional)</span></label>
                            <input type="text" name="customer_gstin" id="customerGstin"
                                class="jewel-input w-full rounded-lg px-3 py-2 text-sm" placeholder="e.g. 19AEPPM9851A1Z4"
                                maxlength="15" style="text-transform:uppercase;"
                                oninput="this.value=this.value.toUpperCase()">
                        </div>
                    </div>

                    <!-- ADD ITEM SECTION -->
                    <div class="mb-4 p-4 rounded-xl" style="background:rgba(214,139,22,0.04);border:1.5px solid rgba(214,139,22,0.2);">
                        <h3 class="text-sm font-bold mb-3" style="color:#800020;">
                            <i class="fas fa-plus-circle mr-1" style="color:#d68b16;"></i> Add Items
                        </h3>

                        <!-- Mode tabs -->
                        <div class="add-mode-tabs">
                            <button type="button" class="add-mode-tab active" id="tabStock" onclick="switchTab('stock')">
                                &#128230; From Stock
                            </button>
                            <button type="button" class="add-mode-tab" id="tabCategory" onclick="switchTab('category')">
                                &#127991;&#65039; By Category
                            </button>
                            <button type="button" class="add-mode-tab" id="tabManual" onclick="switchTab('manual')">
                                &#9999;&#65039; Manual Entry
                            </button>
                        </div>

                        <!-- PANEL 1: From Stock -->
                        <div class="add-mode-panel active" id="panelStock">
                            <p class="text-xs mb-2" style="color:#9ca3af;">
                                <?php if(count($all_products) > 0): ?>
                                    <?php echo count($all_products); ?> product(s) in stock. Search by name or serial number.
                                <?php else: ?>
                                    &#9888;&#65039; No products found in stock. Add products via the Stock page, or use Manual Entry.
                                <?php endif; ?>
                            </p>
                            <div class="flex gap-2 mb-2">
                                <input type="text" id="serialSearch" placeholder="&#128269; Search by Serial No. or Name..."
                                    class="jewel-input flex-1 rounded-lg px-3 py-2 text-sm"
                                    oninput="filterProductSelect(this.value)"
                                    onkeydown="if(event.key==='Enter'){event.preventDefault();searchBySerial();}">
                                <button type="button" onclick="clearSerialSearch()"
                                    class="px-3 py-2 rounded-lg text-sm"
                                    style="background:#fff;border:1.5px solid rgba(181,115,14,0.3);color:#7a4e0a;">&#10006;</button>
                            </div>
                            <div id="serialSearchResult" class="mb-2 hidden p-2 rounded-lg text-xs"
                                style="background:rgba(214,139,22,0.08);border:1px solid rgba(214,139,22,0.2);"></div>
                            <div class="flex flex-col sm:flex-row gap-2 mb-2">
                                <select id="productSelect" class="jewel-input flex-1 rounded-lg px-3 py-2 text-sm"
                                    onchange="onProductSelectChange()" size="1">
                                    <option value="">-- Select Product --</option>
                                    <?php if(count($all_products) > 0): ?>
                                        <?php foreach($all_products as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"
                                            data-price="<?php echo $p['price']; ?>"
                                            data-name="<?php echo htmlspecialchars($p['name']); ?>"
                                            data-serial="<?php echo htmlspecialchars($p['serial_no']); ?>"
                                            data-category="<?php echo htmlspecialchars($p['category']); ?>"
                                            data-item-name="<?php echo htmlspecialchars($p['item_name'] ?: $p['name']); ?>"
                                            data-qty="<?php echo $p['quantity']; ?>">
                                            <?php
                                            $display = $p['item_name'] ? $p['item_name'] . ' (' . $p['name'] . ')' : $p['name'];
                                            $display .= ' | SN:' . $p['serial_no'];
                                            $display .= ' | &#8377;' . number_format($p['price'], 2);
                                            $display .= ' | Stock:' . $p['quantity'];
                                            echo htmlspecialchars($display);
                                            ?>
                                        </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="" disabled>No products in stock — use Manual Entry tab</option>
                                    <?php endif; ?>
                                </select>
                                <input type="number" id="stockQty" placeholder="GMS/Qty" step="0.001" min="0.001"
                                    class="jewel-input w-28 rounded-lg px-3 py-2 text-sm">
                                <button type="button" onclick="addStockItem()"
                                    class="btn-gold px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap">&#10133; Add</button>
                            </div>
                            <div id="selectedProductInfo" class="hidden p-2 rounded-lg text-xs"
                                style="background:#f0fdf4;border:1px solid #86efac;color:#065f46;"></div>
                        </div>

                        <!-- PANEL 2: By Category -->
                        <div class="add-mode-panel" id="panelCategory">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Category</label>
                                    <select id="itemCategory" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" onchange="updateItemTypes()">
                                        <option value="">-- Select Category --</option>
                                        <option value="Gold 22K">Gold 22K</option>
                                        <option value="Gold 18K">Gold 18K</option>
                                        <option value="Silver">Silver</option>
                                        <option value="Stone">Stone</option>
                                        <option value="Diamond">Diamond</option>
                                        <!-- <option value="Others">Others</option> -->
                                    </select>
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Item Type</label>
                                    <select id="itemType" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" onchange="onItemTypeChange()">
                                        <option value="">-- Select Category first --</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Weight (GMS)/Quantity</label>
                                    <input type="number" id="catQty" placeholder="Enter grams" step="0.001" min="0.001"
                                        class="jewel-input w-full rounded-lg px-3 py-2 text-sm">
                                </div>
                                <div id="itemTypePriceWrapper">
                                    <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">
                                        Rate per GMS (&#8377;)/Rate per Quantity (&#8377;) <span id="catRateNote" class="font-normal" style="color:#9ca3af;"></span>
                                    </label>
                                    <input type="number" id="catRate" placeholder="Rate (auto from shop)" step="0.01" min="0"
                                        class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="calculateTotal()">
                                </div>
                            </div>
                            <button type="button" onclick="addCategoryItem()"
                                class="btn-gold w-full py-2 rounded-lg text-sm font-semibold">&#10133; Add Category Item</button>
                        </div>

                        <!-- PANEL 3: Manual Entry -->
                        <div class="add-mode-panel" id="panelManual">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                <div class="sm:col-span-2">
                                    <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Item Description *</label>
                                    <input type="text" id="manualName" placeholder="e.g. Gold Chain 22K, Silver Bangles..."
                                        class="jewel-input w-full rounded-lg px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Weight (GMS)/Quantity <span style="color:#9ca3af;">(0 for fixed price)</span></label>
                                    <input type="number" id="manualGms" placeholder="0" step="0.001" min="0" value="0"
                                        class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="calcManualTotal()">
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Rate per GMS (&#8377;)/Rate per Quantity (&#8377;) <span style="color:#9ca3af;">(0 for fixed price)</span></label>
                                    <input type="number" id="manualRate" placeholder="0.00" step="0.01" min="0" value="0"
                                        class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="calcManualTotal()">
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Total Amount (&#8377;) *</label>
                                    <input type="number" id="manualTotal" placeholder="Enter total amount" step="0.01" min="0.01"
                                        class="jewel-input w-full rounded-lg px-3 py-2 text-sm"
                                        style="border-color:rgba(5,150,105,0.4);color:#065f46;" oninput="calculateTotal()">
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">HSN Code <span style="color:#9ca3af;">(Optional)</span></label>
                                    <input type="text" id="manualHsn" placeholder="71131910" value="71131910"
                                        class="jewel-input w-full rounded-lg px-3 py-2 text-sm">
                                </div>
                            </div>
                            <button type="button" onclick="addManualItem()"
                                class="btn-gold w-full py-2 rounded-lg text-sm font-semibold">&#10133; Add Manual Item</button>
                        </div>
                    </div>

                    <!-- GST Type -->
                    <div class="mb-4">
                        <label class="block mb-1 text-sm font-semibold" style="color:#7a4e0a;">GST Type</label>
                        <select name="gst_type" id="gstType" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" onchange="calculateTotal()">
                            <option value="non_gst">Non-GST (0% Tax)</option>
                            <option value="gst_3">GST (3% — 1.5% CGST + 1.5% SGST)</option>
                            <option value="gst_18">GST (18% — 9% CGST + 9% SGST)</option>
                        </select>
                    </div>

                    <!-- Extra Charges -->
                    <div class="grid grid-cols-3 gap-3 mb-4">
                        <div>
                            <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Making Charge (&#8377;)</label>
                            <input type="number" id="makingCharge" value="0" step="1" min="0"
                                class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="calculateTotal()">
                        </div>
                        <div>
                            <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Hallmark (&#8377;)</label>
                            <input type="number" id="hallmark" value="0" step="10" min="0"
                                class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="calculateTotal()">
                        </div>
                        <div>
                            <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Discount (&#8377;)</label>
                            <input type="number" id="discount" value="0" step="10" min="0"
                                class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="calculateTotal()">
                        </div>
                    </div>

                    <!-- Items Table -->
                    <div class="overflow-x-auto mb-4">
                        <table class="jewel-table rounded-xl overflow-hidden">
                            <thead>
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs">#</th>
                                    <th class="px-3 py-2 text-left text-xs">Product / Description</th>
                                    <th class="px-3 py-2 text-center text-xs">GMS/Qty</th>
                                    <th class="px-3 py-2 text-right text-xs">Rate</th>
                                    <th class="px-3 py-2 text-right text-xs">Amount</th>
                                    <th class="px-3 py-2 text-center text-xs">GST</th>
                                    <th class="px-3 py-2 text-xs"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsList">
                                <tr id="emptyRow">
                                    <td colspan="7" style="text-align:center;padding:20px;color:#9ca3af;font-size:12px;">
                                        No items added yet — use the tabs above to add products
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Totals -->
                    <div class="p-4 rounded-xl" style="background:rgba(214,139,22,0.06);border:1px solid rgba(214,139,22,0.18);">
                        <div class="flex justify-end">
                            <div class="w-full sm:w-80 space-y-1">
                                <div class="flex justify-between text-sm" style="color:#7a4e0a;">
                                    <span>Subtotal</span><span id="subtotal" class="font-semibold">&#8377;0.00</span>
                                </div>
                                <div class="flex justify-between text-sm" style="color:#b5730e;">
                                    <span>Making Charge</span><span id="makingChargeAmount">&#8377;0.00</span>
                                </div>
                                <div class="flex justify-between text-sm" style="color:#059669;">
                                    <span>Hallmark</span><span id="hallmarkAmount">&#8377;0.00</span>
                                </div>
                                <div class="flex justify-between text-sm" style="color:#dc2626;">
                                    <span>Discount</span><span id="discountAmount">- &#8377;0.00</span>
                                </div>
                                <div class="flex justify-between text-sm" style="color:#2563eb;" id="cgstRow">
                                    <span>CGST (<span id="cgstPercent">1.5</span>%)</span><span id="cgstAmount">&#8377;0.00</span>
                                </div>
                                <div class="flex justify-between text-sm" style="color:#2563eb;" id="sgstRow">
                                    <span>SGST (<span id="sgstPercent">1.5</span>%)</span><span id="sgstAmount">&#8377;0.00</span>
                                </div>
                                <div style="height:1px;background:rgba(181,115,14,0.25);margin:8px 0;"></div>
                                <div class="flex justify-between font-bold text-xl" style="color:#800020;">
                                    <span>Grand Total</span><span id="grandTotal">&#8377;0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Hidden form fields -->
                    <input type="hidden" name="subtotal" id="hiddenSubtotal" value="0">
                    <input type="hidden" name="gst_amount" id="hiddenGst" value="0">
                    <input type="hidden" name="total_amount" id="hiddenTotal" value="0">
                    <input type="hidden" name="items" id="hiddenItems" value="[]">
                    <input type="hidden" name="making_charge" id="hiddenMakingCharge" value="0">
                    <input type="hidden" name="hallmark" id="hiddenHallmark" value="0">
                    <input type="hidden" name="pola" value="0">
                    <input type="hidden" name="discount" id="hiddenDiscount" value="0">
                    <input type="hidden" name="cash_paid" id="hiddenCashPaid" value="0">
                    <input type="hidden" name="upi_paid" id="hiddenUpiPaid" value="0">
                    <input type="hidden" name="is_split_payment" id="hiddenIsSplit" value="0">

                    <!-- PAYMENT STATUS -->
                    <div class="mt-5">
                        <label class="block mb-2 text-sm font-bold" style="color:#7a4e0a;">&#128179; Payment Status</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block mb-1 text-xs font-semibold" style="color:#b5730e;">Payment Type</label>
                                <select name="payment_status" id="paymentStatus"
                                    class="jewel-input w-full rounded-lg px-3 py-2 text-sm" onchange="togglePartPayment()">
                                    <option value="paid">Paid (Full Payment)</option>
                                    <option value="part">Part Payment (Due)</option>
                                    <option value="unpaid">Advanced (Credit)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block mb-1 text-xs font-semibold" style="color:#b5730e;">Payment Method</label>
                                <select name="payment_method" id="paymentMethod"
                                    class="jewel-input w-full rounded-lg px-3 py-2 text-sm" onchange="toggleSplitPayment()">
                                    <option value="Cash">&#128181; Cash</option>
                                    <option value="UPI">&#128241; UPI</option>
                                    <option value="NEFT">&#127974; NEFT</option>
                                    <option value="Split">&#128181;+&#128241; Split (Cash + UPI)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Cheque / Old Gold Value (optional, applies to any payment method) -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="block mb-1 text-xs font-semibold" style="color:#b5730e;">Cheque Amount (&#8377;) <span style="color:#9ca3af;font-weight:400;">(if any)</span></label>
                                <input type="number" name="cheque_paid" id="chequePaidInput" value="0" step="1" min="0"
                                    class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="updateBalanceFromPart()">
                            </div>
                            <div>
                                <label class="block mb-1 text-xs font-semibold" style="color:#b5730e;">Customer Old Gold Value (&#8377;) <span style="color:#9ca3af;font-weight:400;">(if exchanged)</span></label>
                                <input type="number" name="old_gold_value" id="oldGoldValueInput" value="0" step="1" min="0"
                                    class="jewel-input w-full rounded-lg px-3 py-2 text-sm" oninput="updateBalanceFromPart()">
                            </div>
                        </div>

                        <!-- Split Payment Box -->
                        <div id="splitPaymentDiv" style="display:none;" class="split-payment-box">
                            <div class="flex items-center gap-2 mb-3">
                                <span>&#128181;</span>
                                <p class="text-sm font-bold" style="color:#1e3a8a;">Split Payment Details</p>
                                <span>&#128241;</span>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#065f46;">Cash Amount (&#8377;)</label>
                                    <input type="number" id="cashAmount" value="0" step="1" min="0"
                                        class="jewel-input split-input-cash w-full rounded-lg px-3 py-2 text-sm"
                                        oninput="onSplitInput('cash')">
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#1e3a8a;">UPI Amount (&#8377;)</label>
                                    <input type="number" id="upiAmount" value="0" step="1" min="0"
                                        class="jewel-input split-input-upi w-full rounded-lg px-3 py-2 text-sm"
                                        oninput="onSplitInput('upi')">
                                </div>
                            </div>
                            <div class="flex gap-2 mb-3 flex-wrap" style="display:none;" id="quickSplitButtons">
                                <span class="text-xs font-semibold self-center" style="color:#6b7280;">Quick:</span>
                                <button type="button" onclick="quickSplit(50)" class="text-xs px-3 py-1 rounded-full font-semibold" style="background:rgba(37,99,235,0.1);border:1px solid rgba(37,99,235,0.3);color:#1d4ed8;">50/50</button>
                                <button type="button" onclick="quickSplit(25)" class="text-xs px-3 py-1 rounded-full font-semibold" style="background:rgba(37,99,235,0.1);border:1px solid rgba(37,99,235,0.3);color:#1d4ed8;">25/75</button>
                                <button type="button" onclick="quickSplit(75)" class="text-xs px-3 py-1 rounded-full font-semibold" style="background:rgba(37,99,235,0.1);border:1px solid rgba(37,99,235,0.3);color:#1d4ed8;">75/25</button>
                                <button type="button" onclick="quickSplit(0)"  class="text-xs px-3 py-1 rounded-full font-semibold" style="background:rgba(5,150,105,0.1);border:1px solid rgba(5,150,105,0.3);color:#065f46;">All Cash</button>
                                <button type="button" onclick="quickSplit(100)" class="text-xs px-3 py-1 rounded-full font-semibold" style="background:rgba(37,99,235,0.1);border:1px solid rgba(37,99,235,0.3);color:#1e3a8a;">All UPI</button>
                            </div>
                            <div class="split-legend">
                                <div class="split-legend-item"><div class="split-dot" style="background:#059669;"></div><span style="color:#065f46;">Cash</span></div>
                                <div class="split-legend-item"><div class="split-dot" style="background:#2563eb;"></div><span style="color:#1e3a8a;">UPI</span></div>
                            </div>
                            <div class="split-progress-wrap">
                                <div class="split-bar-cash" id="splitProgressCash" style="width:0%;"></div>
                                <div class="split-bar-upi"  id="splitProgressUpi"  style="width:0%;"></div>
                            </div>
                            <div class="p-3 rounded-xl" style="background:rgba(255,255,255,0.7);border:1px solid rgba(37,99,235,0.1);">
                                <div class="split-summary-row"><span style="color:#374151;font-weight:600;">Grand Total</span><span id="splitGrandTotal" style="color:#800020;font-weight:800;">&#8377;0.00</span></div>
                                <div style="height:1px;background:#e5e7eb;margin:4px 0;"></div>
                                <div class="split-summary-row"><span style="color:#059669;">&#128181; Cash</span><span id="splitCashDisplay" style="color:#059669;font-weight:700;">&#8377;0.00</span></div>
                                <div class="split-summary-row"><span style="color:#2563eb;">&#128241; UPI</span><span id="splitUpiDisplay" style="color:#2563eb;font-weight:700;">&#8377;0.00</span></div>
                                <div style="height:1px;background:#e5e7eb;margin:4px 0;"></div>
                                <div class="split-summary-row"><span style="color:#374151;font-weight:600;">Total Paid</span><span id="splitPaidTotal" style="color:#059669;font-weight:700;">&#8377;0.00</span></div>
                                <div class="split-summary-row"><span style="color:#dc2626;font-weight:600;">Balance Due</span><span id="splitRemaining" style="color:#dc2626;font-weight:700;">&#8377;0.00</span></div>
                            </div>
                            <div id="splitStatusBadge" class="mt-2 text-center text-xs font-bold py-2 rounded-lg hidden"></div>
                        </div>

                        <!-- Part payment paid amount + due date -->
                        <div id="partAmountDiv" style="display:none;" class="mt-3">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#b5730e;">&#128181; Paid Amount (&#8377;)</label>
                                    <input type="number" name="paid_amount" id="paidAmount" value="0" step="1" min="0"
                                        class="jewel-input w-full rounded-lg px-3 py-2 text-sm"
                                        placeholder="How much paid now?" oninput="updateBalanceFromPart()">
                                </div>
                                <div>
                                    <label class="block mb-1 text-xs font-semibold" style="color:#b5730e;">&#128197; Due Date <span style="color:#9ca3af;font-weight:400;">(when customer will pay)</span></label>
                                    <input type="date" name="due_date" id="dueDate"
                                        class="jewel-input w-full rounded-lg px-3 py-2 text-sm"
                                        min="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                            <div id="dueDateHint" class="mt-1 text-xs hidden" style="color:#d97706;">
                                &#128197; <span id="dueDateText"></span>
                            </div>
                        </div>

                        <!-- Balance display -->
                        <div id="balanceDisplay" style="display:none;" class="mt-2 p-3 rounded-lg text-sm"
                            style="background:rgba(251,191,36,0.1);border:1px solid rgba(251,191,36,0.3);">
                            <span style="color:#d97706;">&#9888;&#65039; Balance Amount: <strong id="balanceAmt">&#8377;0.00</strong></span>
                        </div>
                        <button type="button" id="reminderButton" onclick="sendPaymentReminder()"
                            class="btn-b mt-3 py-2 px-4 rounded-lg font-semibold"
                            style="display:none;background:#fde68a;color:#92400e;border:1px solid #facc15;">
                            &#128231; Send Reminder Email
                        </button>
                    </div>

                    <div class="mt-6">
                        <button type="submit" name="create_invoice" id="submitBtn"
                            class="btn-gold w-full py-3 rounded-xl font-bold text-lg"
                            style="background:linear-gradient(135deg,#800020,#d68b16);font-family:'Playfair Display',serif;letter-spacing:1px;">
                            &#10024; Generate Invoice &#10024;
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Column -->
        <div>
            <!-- My Shop Rates -->
            <div class="jewel-card p-4 sm:p-5 mt-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-bold" style="color:#800020;font-family:'Playfair Display',serif;">
                        <i class="fas fa-store mr-2" style="color:#d68b16;"></i> My Shop Rates
                    </h3>
                    <span class="text-xs px-2 py-1 rounded-lg" id="shopRateSaveStatus"
                        style="background:rgba(214,139,22,0.1);border:1px solid rgba(214,139,22,0.3);color:#b5730e;">Not saved</span>
                </div>
                <?php
                $shopFields = [
                    ['key'=>'gold24','label'=>'Gold 24K','color'=>'#cc4400','dispId'=>'shopGold24Display','inputId'=>'shopGold24Input','step'=>'1'],
                    ['key'=>'gold22','label'=>'Gold 22K','color'=>'#d68b16','dispId'=>'shopGold22Display','inputId'=>'shopGold22Input','step'=>'1'],
                    ['key'=>'gold18','label'=>'Gold 18K','color'=>'#b5730e','dispId'=>'shopGold18Display','inputId'=>'shopGold18Input','step'=>'1'],
                    ['key'=>'silver','label'=>'Silver',  'color'=>'#6b7280','dispId'=>'shopSilverDisplay', 'inputId'=>'shopSilverInput', 'step'=>'0.5'],
                    ['key'=>'diamond','label'=>'Diamond','color'=>'#2563eb','dispId'=>'shopDiamondDisplay','inputId'=>'shopDiamondInput','step'=>'1'],
                ];
                foreach($shopFields as $f): ?>
                <div class="mb-3">
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-xs font-semibold" style="color:<?php echo $f['color']; ?>;">
                            <?php echo $f['label']; ?> <span style="color:#9ca3af;font-weight:400;">(per 10g)</span>
                        </label>
                        <span class="text-xs font-bold" style="color:<?php echo $f['color']; ?>;" id="<?php echo $f['dispId']; ?>">&#8212;</span>
                    </div>
                    <div class="flex gap-2">
                        <input type="number" id="<?php echo $f['inputId']; ?>" placeholder="Enter your shop price"
                            step="<?php echo $f['step']; ?>" min="0" class="jewel-input flex-1 rounded-lg px-3 py-2 text-sm"
                            oninput="previewShopRate('<?php echo $f['key']; ?>')">
                        <button onclick="saveShopRate('<?php echo $f['key']; ?>')"
                            class="btn-gold px-3 py-2 rounded-lg text-xs font-bold">Save</button>
                    </div>
                </div>
                <?php endforeach; ?>
                <button onclick="saveAllShopRates()" class="btn-gold w-full py-2 rounded-lg text-sm font-bold mt-1">
                    &#128190; Save All Rates
                </button>
                <div class="p-3 rounded-xl mt-3" style="background:rgba(214,139,22,0.05);border:1px solid rgba(181,115,14,0.12);">
                    <div class="text-xs font-semibold mb-2" style="color:#b5730e;">&#9878;&#65039; Shop Value Calculator</div>
                    <div class="flex gap-2">
                        <select id="shopMetalSelect" class="jewel-input flex-1 rounded-lg px-2 py-1 text-xs" onchange="calcShopValue()">
                            <option value="gold24">Gold 24K</option>
                            <option value="gold22">Gold 22K</option>
                            <option value="gold18">Gold 18K</option>
                            <option value="silver">Silver</option>
                            <option value="diamond">Diamond</option>
                        </select>
                        <input type="number" id="shopMetalGrams" placeholder="GMS" step="0.001" min="0"
                            class="jewel-input w-20 rounded-lg px-2 py-1 text-xs" oninput="calcShopValue()">
                    </div>
                    <div class="text-center mt-2 font-bold text-sm" id="shopCalcResult" style="color:#059669;">&#8212;</div>
                </div>
                <p class="text-xs mt-2 text-center" style="color:#9ca3af;" id="shopRateLastSaved">Rates saved in your browser</p>
            </div>
            <br>

          

            <!-- Live Metal Rates -->
            <div class="jewel-card p-4 sm:p-5 mt-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-bold" style="color:#800020;font-family:'Playfair Display',serif;">
                        <i class="fas fa-coins mr-2" style="color:#d68b16;"></i> Live Metal Rates
                    </h3>
                    <div class="flex items-center gap-2">
                        <span id="metalPriceStatus" class="text-xs" style="color:#b5730e;">&#8635; Loading...</span>
                        <button onclick="fetchMetalPrices()" class="text-xs px-2 py-1 rounded-lg"
                            style="background:rgba(214,139,22,0.12);border:1px solid rgba(214,139,22,0.3);color:#d68b16;cursor:pointer;">&#128260;</button>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div class="rounded-xl p-3 text-center" style="background:rgba(214,139,22,0.08);border:1px solid rgba(214,139,22,0.3);">
                        <div class="text-xs font-semibold" style="color:#d68b16;">Gold 24K</div>
                        <div class="text-sm font-bold mt-1" style="color:#7a4e0a;" id="gold24Price">&#8212;</div>
                        <div class="text-xs mt-1" style="color:#9ca3af;" id="gold24Change">per 10g</div>
                    </div>
                    <div class="rounded-xl p-3 text-center" style="background:rgba(214,139,22,0.05);border:1px solid rgba(181,115,14,0.25);">
                        <div class="text-xs font-semibold" style="color:#b5730e;">Gold 22K</div>
                        <div class="text-sm font-bold mt-1" style="color:#7a4e0a;" id="gold22Price">&#8212;</div>
                        <div class="text-xs mt-1" style="color:#9ca3af;" id="gold22Change">per 10g</div>
                    </div>
                    <div class="rounded-xl p-3 text-center" style="background:rgba(192,192,192,0.08);border:1px solid rgba(192,192,192,0.2);">
                        <div class="text-xs font-semibold" style="color:#6b7280;">Silver</div>
                        <div class="text-sm font-bold mt-1" style="color:#374151;" id="silverPrice">&#8212;</div>
                        <div class="text-xs mt-1" style="color:#9ca3af;" id="silverChange">per 10g</div>
                    </div>
                    <div class="rounded-xl p-3 text-center" style="background:rgba(229,228,226,0.05);border:1px solid rgba(229,228,226,0.18);">
                        <div class="text-xs font-semibold" style="color:#6b7280;">Platinum</div>
                        <div class="text-sm font-bold mt-1" style="color:#374151;" id="platinumPrice">&#8212;</div>
                        <div class="text-xs mt-1" style="color:#9ca3af;" id="platinumChange">per 10g</div>
                    </div>
                </div>
                <div class="p-3 rounded-xl" style="background:rgba(214,139,22,0.05);border:1px solid rgba(181,115,14,0.12);">
                    <div class="text-xs font-semibold mb-2" style="color:#b5730e;">&#9878;&#65039; Quick Value Calculator</div>
                    <div class="flex gap-2">
                        <select id="metalSelect" class="jewel-input flex-1 rounded-lg px-2 py-1 text-xs" onchange="calcMetalValue()">
                            <option value="gold24">Gold 24K</option>
                            <option value="gold22">Gold 22K</option>
                            <option value="silver">Silver</option>
                            <option value="platinum">Platinum</option>
                        </select>
                        <input type="number" id="metalGrams" placeholder="GMS" step="0.001" min="0"
                            class="jewel-input w-20 rounded-lg px-2 py-1 text-xs" oninput="calcMetalValue()">
                    </div>
                    <div class="text-center mt-2 font-bold text-sm" id="metalCalcResult" style="color:#059669;">&#8212;</div>
                </div>
                <p class="text-xs mt-2 text-center" style="color:#9ca3af;" id="metalUpdateInfo">Fetching Indian market rates...</p>
            </div>
       <br>
         <!-- EMI Calculator -->
            <div class="jewel-card p-4 sm:p-6 sticky top-24">
                <h3 class="text-lg font-bold mb-4" style="color:#800020;font-family:'Playfair Display',serif;">
                    <i class="fas fa-calculator mr-2" style="color:#d68b16;"></i> EMI Calculator
                </h3>
                <div class="space-y-3">
                    <div>
                        <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Loan Amount (&#8377;)</label>
                        <input type="number" id="loanAmount" class="jewel-input w-full rounded-lg px-3 py-2 text-sm" placeholder="Enter amount">
                    </div>
                    <div>
                        <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Interest Rate (%/year)</label>
                        <input type="number" id="interestRate" value="12" class="jewel-input w-full rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block mb-1 text-xs font-semibold" style="color:#7a4e0a;">Tenure (Months)</label>
                        <input type="number" id="tenure" value="6" class="jewel-input w-full rounded-lg px-3 py-2 text-sm">
                    </div>
                    <button type="button" onclick="calculateEMI()" class="btn-gold w-full py-2 rounded-lg font-semibold">Calculate EMI</button>
                    <div id="emiResult" class="text-center p-3 rounded-lg hidden"
                        style="background:linear-gradient(135deg,rgba(214,139,22,0.08),rgba(128,0,32,0.05));border:1px solid rgba(214,139,22,0.2);">
                        <p class="text-xs font-semibold" style="color:#7a4e0a;">Monthly EMI:</p>
                        <p class="text-2xl font-bold" style="color:#800020;" id="emiAmount">&#8377;0</p>
                        <p class="text-xs mt-1" style="color:#b5730e;">Total Payment: <span id="totalPayment">&#8377;0</span></p>
                    </div>
                </div>
            </div>
 </div>
    </div><!-- /grid -->

 

    <!-- PDF Upload (after invoice creation) -->
    <?php if(!empty($last_invoice_no)): ?>
    <div class="mt-6 jewel-card p-4 sm:p-6">
        <h3 class="text-lg font-bold mb-4" style="color:#800020;">
            <i class="fas fa-file-pdf mr-2" style="color:#dc2626;"></i> Upload Invoice PDF
        </h3>
        <form method="POST" enctype="multipart/form-data" class="flex flex-col sm:flex-row gap-3">
            <input type="hidden" name="invoice_no" value="<?php echo htmlspecialchars($last_invoice_no); ?>">
            <input type="file" name="invoice_pdf" accept="application/pdf" required
                class="jewel-input rounded-lg px-3 py-2 text-sm flex-1">
            <button type="submit" name="upload_pdf" class="btn-gold px-6 py-2 rounded-lg font-semibold">&#128228; Upload PDF</button>
        </form>
    </div>
    <?php endif; ?>

</div><!-- /container -->
</div><!-- /page-wrapper -->

<!-- PRINT INVOICE -->
<?php if(!empty($success) && !empty($last_invoice_no) && $last_total > 0): ?>
<?php
if(!empty($last_invoice_no)) {
    $inv_no_esc = mysqli_real_escape_string($conn, $last_invoice_no);
    $invRes = mysqli_query($conn, "SELECT cash_paid, upi_paid, account_paid, cheque_paid, old_gold_value, round_off, balance_amount, due_date, payment_method, paid_amount, total_amount, customer_gstin FROM invoices WHERE invoice_no = '$inv_no_esc' LIMIT 1");
    if($invRes && mysqli_num_rows($invRes) > 0) {
        $invRow = mysqli_fetch_assoc($invRes);
        $last_cash_paid      = floatval($invRow['cash_paid']      ?? $last_cash_paid);
        $last_upi_paid       = floatval($invRow['upi_paid']       ?? $last_upi_paid);
        $last_account_paid   = floatval($invRow['account_paid']   ?? 0);
        $last_cheque_paid    = floatval($invRow['cheque_paid']    ?? $last_cheque_paid);
        $last_old_gold_value = floatval($invRow['old_gold_value'] ?? $last_old_gold_value);
        $last_round_off      = floatval($invRow['round_off']      ?? $last_round_off);
        $last_customer_gstin = trim($invRow['customer_gstin']  ?? $last_customer_gstin);
        $last_balance_amount = floatval($invRow['balance_amount'] ?? $last_balance_amount);
        $last_due_date       = !empty($invRow['due_date']) ? $invRow['due_date'] : '';
        $last_payment_method = $invRow['payment_method'] ?? $last_payment_method;
        $last_paid_amount    = floatval($invRow['paid_amount']    ?? $last_paid_amount);
        $last_total          = floatval($invRow['total_amount']   ?? $last_total);
    }
}
$upi_card          = $last_upi_paid + ($last_account_paid ?? 0);
$net_amount        = $last_subtotal;
$gross_amount      = $last_total;
$processing_charge = $last_making_charge_amount;
$others_charge_val = $last_hallmark - $last_discount;
$last_customer_gstin = $last_customer_gstin ?? '';
?>

<style>
@page { size: A4; margin: 8mm; }
@media print {
  html, body {
    width: auto !important;
    height: auto !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
  body > :not(#mgInvoicePrint) {
    display: none !important;
  }
  #mgInvoicePrint {

    display: block !important;
    position: static !important;
    width: 100% !important;
    max-width: 210mm !important;
    min-height: auto !important;
    margin: 0 auto !important;
    padding: 0 !important;
    border: none !important;
    page-break-after: avoid !important;
    page-break-inside: avoid !important;
    overflow: visible !important;
    box-shadow: none !important;
    background: #fff !important;
    font-size: 10px !important;
    line-height: 1.1 !important;
    transform-origin: top left !important;
 
  }
  #mgInvoicePrint, #mgInvoicePrint * {
    visibility: visible !important;
    color: inherit !important;
    box-sizing: border-box !important;
  }
  #mgInvoicePrint img {
    max-width: 100% !important;
    width: 100% !important;
    height: auto !important;
    max-height: none !important;
    object-fit: contain !important;
    display: block !important;
  }
  #mgInvoicePrint > div:first-child {
    overflow: visible !important;
  }
  #mgInvoicePrint table, #mgInvoicePrint tr, #mgInvoicePrint td, #mgInvoicePrint th, #mgInvoicePrint div {
    page-break-inside: avoid !important;
  }
  #mgInvoicePrint th, #mgInvoicePrint td {
    padding: 4px !important;
    font-size: 9px !important;
  }
  #mgInvoicePrint .no-print { display: none !important; }
  #mgInvoicePrint .split-payment-box,
  #mgInvoicePrint .payment-modal,
  #mgInvoicePrint .due-card { page-break-inside: avoid !important; }
}
#mgInvoicePrint {
  font-family: Arial, Helvetica, sans-serif;
  width: min(100%, 780px);
  max-width: 210mm;
  margin: 32px auto 0;
  background: #ffffff;
  border: 2px solid #cc4400;
  box-sizing: border-box;
  color: #222;
  font-size: 12px;
  position: relative;
  overflow: hidden;
}
#mgInvoicePrint * { box-sizing: border-box; }
#mgInvoicePrint > * { position: relative; z-index: 1; }
</style>

<div id="mgInvoicePrint">
  <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;z-index:0;opacity:0.08;">
    <img src="assets/images/moti-removebg-preview.png" alt="" style="width:500%;max-width:650px;height:auto;object-fit:contain;filter:grayscale(1);">
</div>

  <!-- TOP HEADER IMAGE -->
  <div style="line-height:0; overflow:hidden;">
    <img src="assets/images/copy.jpeg" alt="Maa Gouri Jewellers Header"
         style="width:100%;display:block;height:auto;max-height:none;object-fit:contain;"
         onerror="this.style.display='none'">
  </div>

  <!-- MEMO NO + DATE -->
  <div style="display:flex;align-items:center;justify-content:space-between;
              padding:6px 12px; background:#fff;">
    <div style="display:flex;align-items:center;gap:8px;">
      <div style="font-size:20px;font-weight:900;color:#cc4400;
                  border:1px solid #cc0000;min-width:120px;padding:3px 10px;border-radius:3px;">
        <?php if(($last_bill_type ?? '') === 'memo'): ?>
          MEMO <?php echo htmlspecialchars(str_replace('MEMO-', '', $last_invoice_no)); ?>
        <?php else: ?>
          <?php echo htmlspecialchars($last_invoice_no); ?>
        <?php endif; ?>
      </div>
    </div>
    <div style="font-size:12px;font-weight:700;color:#8B1A1A;display:flex;align-items:center;gap:6px;
            border:1px solid #cc0000;border-radius:3px;padding:3px 10px;width:fit-content;">
      DATE :
      <span style="min-width:150px;display:inline-block;font-size:13px;font-weight:900;color:#222;text-align:center;">
        <?php echo date('d / m / Y'); ?>
      </span>
    </div>
  </div>

  <!-- CUSTOMER INFO + RATE BOX -->
  <div style="display:flex;flex-wrap:wrap;align-items:flex-start;padding:8px;gap:10px;background:#fff;">

    <!-- LEFT: Customer Fields Box -->
<div style="flex:1;border:2px solid #e8601a;border-radius:8px;margin-right:6px;padding:36px 16px;display:flex;flex-direction:column;gap:12px;">

  <!-- Name -->
  <div style="display:flex;align-items:center;gap:8px;">
    <span style="width:22px;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
      <svg width="18" height="20" viewBox="0 0 18 20" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="9" cy="6" r="4" stroke="#070707" stroke-width="1.8"/>
        <path d="M1 19c0-4.4 3.6-8 8-8s8 3.6 8 8" stroke="#040404" stroke-width="1.8" stroke-linecap="round"/>
      </svg>
    </span>
    <span style="font-weight:700;font-size:13px;color:blue;min-width:108px;flex-shrink:0;">Name</span>
    <span style="font-weight:700;font-size:13px;color:#8B1A1A;margin-right:6px;flex-shrink:0;">:</span>
    <span style="flex:1;border:none;border-bottom:1.5px dotted #999;display:block;min-height:18px;font-size:12px;color:#222;padding-bottom:1px;"><?php echo htmlspecialchars($last_customer_name); ?></span>
  </div>
  <!-- Address -->
  <div style="display:flex;align-items:center;gap:8px;">
    <span style="width:22px;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
      <svg width="18" height="20" viewBox="0 0 18 20" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M9 1C6.24 1 4 3.24 4 6C4 9.5 9 17 9 17S14 9.5 14 6C14 3.24 11.76 1 9 1ZM9 8C8.45 8 8 7.55 8 7C8 6.45 8.45 6 9 6C9.55 6 10 6.45 10 7C10 7.55 9.55 8 9 8Z" stroke="#000000" stroke-width="1.2" fill="none"/>
      </svg>
    </span>
    <span style="font-weight:700;font-size:13px;color:blue;min-width:108px;flex-shrink:0;">Address</span>
    <span style="font-weight:700;font-size:13px;color:#8B1A1A;margin-right:6px;flex-shrink:0;">:</span>
    <span style="flex:1;border:none;border-bottom:1.5px dotted #999;display:block;min-height:18px;font-size:12px;color:#222;padding-bottom:1px;"><?php echo htmlspecialchars($last_customer_address ?? ''); ?></span>
  </div>
  <!-- Mobile -->
  <div style="display:flex;align-items:center;gap:8px;">
    <span style="width:22px;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
      <svg width="17" height="17" viewBox="0 0 17 17" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M3.2 1C2 1 1 2 1 3.2c0 1.1.4 2.1.9 3C3.3 8.8 5.5 11 8.3 12.4c.9.5 1.9.9 3 .9 1.2 0 2.2-1 2.2-2.2v-.7c0-.5-.3-1-.8-1.2l-1.8-.6c-.5-.2-1 0-1.3.4l-.6.8c-1.3-.7-2.5-1.9-3.2-3.2l.8-.6C6.9 5.6 7 5 6.9 4.6L6.3 2.8C6.1 2.3 5.6 2 5.1 2L3.2 1z" stroke="#000000" stroke-width="1.6" stroke-linejoin="round"/>
      </svg>
    </span>
    <span style="font-weight:700;font-size:13px;color:blue;min-width:108px;flex-shrink:0;">Mobile</span>
    <span style="font-weight:700;font-size:13px;color:#8B1A1A;margin-right:6px;flex-shrink:0;">:</span>
    <span style="flex:1;border:none;border-bottom:1.5px dotted #999;display:block;min-height:18px;font-size:12px;color:#222;padding-bottom:1px;"><?php echo htmlspecialchars($last_customer_mobile); ?></span>
  </div>

  <!-- Customer GST No. -->
  <div style="display:flex;align-items:center;gap:8px;">
    <span style="width:22px;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
      <svg width="18" height="17" viewBox="0 0 18 17" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="1" y="1" width="16" height="15" rx="2" stroke="#000000" stroke-width="1.6"/>
        <path d="M1 5.5h16" stroke="#030303" stroke-width="1.3" stroke-linecap="round"/>
        <path d="M5 1v4.5M9 1v4.5" stroke="#040404" stroke-width="1.3" stroke-linecap="round"/>
        <circle cx="4.5" cy="9.5" r="0.9" fill="#000000"/>
        <circle cx="9" cy="9.5" r="0.9" fill="#131212"/>
        <circle cx="13.5" cy="9.5" r="0.9" fill="#000000"/>
        <circle cx="4.5" cy="13" r="0.9" fill="#000000"/>
        <circle cx="9" cy="13" r="0.9" fill="#030303"/>
      </svg>
    </span>
    <span style="font-weight:700;font-size:13px;color:blue;min-width:128px;flex-shrink:0;">Customer GST No.</span>
    <span style="font-weight:700;font-size:13px;color:#8B1A1A;margin-right:6px;flex-shrink:0;">:</span>
    <span style="flex:1;border:none;border-bottom:1.5px dotted #999;display:block;min-height:18px;font-size:12px;color:#222;padding-bottom:1px;"><?php echo htmlspecialchars($last_customer_gstin ?? ''); ?></span>
  </div>



</div><!-- /left customer box -->

    <!-- RIGHT: Rate Box -->
    <div style="flex:1 1 220px;min-width:220px;max-width:100%;border:2px solid #e8601a;border-radius:8px;overflow:hidden;background:#fff;">
      <?php
      $rateItems = [
          ['label'=>'24K Rate',    'id'=>'rate24kVal',    'key'=>'gold24'],
          ['label'=>'22K Rate',    'id'=>'rate22kVal',    'key'=>'gold22'],
          ['label'=>'18K Rate',    'id'=>'rate18kVal',    'key'=>'gold18'],
          ['label'=>'Silver Rate', 'id'=>'rateSilverVal', 'key'=>'silver'],
      ];
      $totalRates = count($rateItems);
      foreach($rateItems as $ri => $rItem):
      ?>
      <div style="display:flex;align-items:center;padding:11px 14px;<?php echo ($ri < $totalRates-1) ? 'border-bottom:1px solid #f5a06a;' : ''; ?>">
        <span style="font-weight:700;font-size:13px;color:#8B1A1A;min-width:82px;flex-shrink:0;"><?php echo $rItem['label']; ?></span>
        <span style="font-weight:700;font-size:13px;color:#8B1A1A;margin:0 8px;flex-shrink:0;">:</span>
        <span id="<?php echo $rItem['id']; ?>" data-rate-key="<?php echo $rItem['key']; ?>"
              style="flex:1;border-bottom:2px solid #8B1A1A;display:block;height:16px;font-size:12px;font-weight:700;color:#222;text-align:right;padding-right:2px;"></span>
      </div>
      <?php endforeach; ?>
    </div><!-- /rate box -->

  </div><!-- /customer + rates -->

<!-- ITEMS TABLE -->
<div style="border:1.5px solid #cc4400;border-radius:10px;padding:0px 0px;overflow:hidden;margin:0 7px;">
  <table style="width:100%;border-collapse:collapse;font-size:12px;">
    <thead>
      <tr>
        <th style="background:#8B1A1A;color:#fff;padding:9px 10px;text-align:center;font-weight:700;
                   width:22%;border-right:1px solid rgba(255,255,255,0.3);">DESCRIPTION</th>
        <th style="background:#79641B;color:#fff;padding:9px 6px;text-align:center;font-size:11px;
                   font-weight:700;width:10%;border-right:1px solid rgba(255,255,255,0.3);">HSN<br>CODE</th>
        <th style="background:#556B2F;color:#fff;padding:9px 6px;text-align:center;font-size:11px;
                   font-weight:700;width:10%;border-right:1px solid rgba(255,255,255,0.3);">WEIGHT<br>(gm.)</th>
        <th style="background:#CD5705;color:#fff;padding:9px 6px;text-align:center;font-size:11px;
                   font-weight:700;width:12%;border-right:1px solid rgba(255,255,255,0.3);">PROCESSING<br>CHARGE</th>
        <th style="background:#CD5705;color:#fff;padding:9px 6px;text-align:center;font-size:11px;
                   font-weight:700;width:10%;border-right:1px solid rgba(255,255,255,0.3);">OTHERS<br>CHARGE</th>
        <th style="background:#2F5A1A;color:#fff;padding:9px 6px;text-align:center;font-size:11px;
                   font-weight:700;width:36%;">AMOUNT<br>(&#8377;)</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $rowCount = 0;
      foreach($last_items as $item):
        $qty    = floatval($item['quantity'] ?? 0);
        $itotal = floatval($item['total'] ?? 0);
        $iname  = htmlspecialchars($item['name'] ?? '');
        $ihsn   = htmlspecialchars($item['hsn'] ?? '71131910');
        $rowCount++;
      ?>
      <tr style="border-bottom:1px solid #f0c0a0;">
        <td style="padding:8px 10px;vertical-align:top;"><?php echo $iname; ?></td>
        <td style="padding:8px 6px;text-align:center;vertical-align:top;"><?php echo $ihsn; ?></td>
        <td style="padding:8px 6px;text-align:center;vertical-align:top;"><?php echo $qty > 0 ? number_format($qty,3) : ''; ?></td>
        <td style="padding:8px 6px;text-align:center;vertical-align:top;"><?php echo ($rowCount === 1 && $processing_charge > 0) ? number_format($processing_charge,2) : ''; ?></td>
        <td style="padding:8px 6px;text-align:center;vertical-align:top;">&nbsp;</td>
        <td style="padding:8px 10px;text-align:right;vertical-align:top;font-weight:600;white-space:nowrap;"><?php echo number_format($itotal,2); ?></td>
      </tr>
      <?php endforeach; ?>
      <?php for($b=0; $b < max(0, 17-$rowCount); $b++): ?>
<tr style="border-bottom:1px solid #f0c0a0;height:56px;">
        <td>&nbsp;</td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
        <td></td>
      </tr>
      <?php endfor; ?>
    </tbody>
    <tfoot>
      <tr style="background:#fff8f0;">
        <td style="padding:7px 10px;">&nbsp;</td>
        <td></td>
        <td style="text-align:center;font-weight:700;color:#cc4400;"><?php echo number_format($last_total_quantity,3); ?></td>
        <td style="text-align:center;font-weight:700;color:#cc4400;"><?php echo $processing_charge > 0 ? number_format($processing_charge,2) : ''; ?></td>
        <td style="text-align:center;font-weight:700;color:#cc4400;"><?php echo $others_charge_val != 0 ? number_format($others_charge_val,2) : ''; ?></td>
        <td style="text-align:right;font-weight:900;color:#cc4400;padding:7px 10px;font-size:13px;white-space:nowrap;"><?php echo number_format($last_total,2); ?></td>
      </tr>
    </tfoot>
  </table>
</div>

  <!-- BOTTOM SECTION -->
  <div style="display:flex;flex-wrap:wrap;min-height:170px;">

    <!-- BOTTOM LEFT -->
<div style="flex:1 1 320px;min-width:320px;max-width:100%;border:1px solid #1a3a7a;border-radius:8px;display:flex;flex-direction:column;padding:0px 8px;overflow:hidden;margin:5px 8px;min-height:90px;">

  <div style="padding:5px 10px 0;">
    <div style="background:#1a3a7a;display:inline-block;padding:2px 8px;border-radius:3px;margin-bottom:3px;">
      <span style="color:#fff;font-weight:700;font-size:10px;letter-spacing:.05em;">PAYMENT</span>
    </div>
  </div>

  <div style="padding:6px 10px;">
    <div style="display:flex;align-items:center;margin-bottom:4px;">
      <span style="font-weight:700;font-size:10px;color:#222;min-width:80px;">Type</span>
      <span style="color:#222;margin:0 2px;font-size:10px;">:</span>
      <span style="flex:1;border-bottom:1px dotted #aaa;padding-bottom:1px;font-size:10px;font-weight:600;"><?php echo htmlspecialchars($last_payment_method ?: 'Cash'); ?></span>
    </div>
    <div style="display:flex;align-items:center;margin-bottom:4px;">
      <span style="font-weight:700;font-size:10px;color:#222;min-width:80px;\">Date</span>
      <span style="color:#222;margin:0 2px;font-size:10px;">:</span>
      <span style="flex:1;border-bottom:1px dotted #aaa;padding-bottom:1px;font-size:10px;"><?php echo date('d-m-Y'); ?></span>
    </div>
    <div style="display:flex;align-items:center;">
      <span style="font-weight:700;font-size:10px;color:#222;min-width:80px;\">Amount</span>
      <span style="color:#222;margin:0 2px;font-size:10px;">:</span>
      <span style="flex:1;border-bottom:1px dotted #aaa;padding-bottom:1px;font-size:11px;font-weight:700;color:#cc4400;">₹<?php echo number_format($last_paid_amount,2); ?></span>
    </div>
  </div>

  <div style="padding:8px 10px 25px;flex:1;">
    <div style="background:#1a3a7a;display:inline-block;padding:2px 8px;border-radius:3px;margin-bottom:3px;">
      <span style="color:#fff;font-size:8px;font-weight:700;\">TERMS & CONDITIONS</span>
    </div>
    <ol style="margin:0;padding-left:14px;font-size:8px;color:#333;line-height:1.3;\">
      <li>E. &amp; O.E.</li>
      <li>Payment within due date.</li>
      <li>Include invoice in payment note.</li>
      <li>Disputes: Paschim Medinipur jurisdiction.</li>
    </ol>
  </div>

  <div style="padding:2px 10px 0;">
    <div style="background:#1a3a7a;display:inline-block;padding:2px 8px;border-radius:3px;margin-bottom:3px;">
      <span style="color:#fff;font-size:8px;font-weight:700;">PAYMENT MODES</span>
    </div>
  </div>

  <!-- 4 Payment Mode Boxes -->
  <div style="display:flex;padding:0 8px 8px;gap:3px;">
    <div style="flex:1;border:1.5px solid #e8a050;border-radius:5px;padding:5px 2px;text-align:center;background:#fffbf0;">
      <div style="width:24px;height:24px;border:2px solid #e8a050;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 2px;background:#fff;font-size:12px;">&#128181;</div>
      <div style="font-size:8px;font-weight:700;color:#222;">Cash</div>
      <div style="font-size:9px;font-weight:600;color:#1a3a7a;">₹<?php echo number_format($last_cash_paid,2); ?></div>
    </div>
    <div style="flex:1;border:1.5px solid #e8a050;border-radius:5px;padding:5px 2px;text-align:center;background:#fffbf0;">
      <div style="width:24px;height:24px;border:2px solid #e8a050;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 2px;background:#fff;font-size:12px;">&#128196;</div>
      <div style="font-size:8px;font-weight:700;color:#222;">Cheque</div>
      <div style="font-size:9px;font-weight:600;color:#1a3a7a;">₹<?php echo number_format($last_cheque_paid,2); ?></div>
    </div>
    <div style="flex:1;border:1.5px solid #e8a050;border-radius:5px;padding:5px 2px;text-align:center;background:#fffbf0;">
      <div style="width:24px;height:24px;border:2px solid #e8a050;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 2px;background:#fff;font-size:12px;">&#128179;</div>
      <div style="font-size:8px;font-weight:700;color:#222;">UPI / NEFT</div>
      <div style="font-size:9px;font-weight:600;color:#1a3a7a;">₹<?php echo number_format($upi_card,2); ?></div>
    </div>
    <div style="flex:1;border:1.5px solid #e8a050;border-radius:5px;padding:5px 2px;text-align:center;background:#fffbf0;">
      <div style="width:24px;height:24px;border:2px solid #e8a050;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 2px;background:#fff;font-size:12px;">&#128142;</div>
      <div style="font-size:7px;font-weight:700;color:#222;line-height:1;">Old<br>Gold</div>
      <div style="font-size:9px;font-weight:600;color:#1a3a7a;">₹<?php echo number_format($last_old_gold_value,2); ?></div>
    </div>
  </div>

  <!-- BANK DETAILS SECTION -->
  <div style="border:1px solid #1a3a7a;border-radius:5px;margin:4px 2px;overflow:hidden;">
    <div style="background:#1a3a7a;padding:3px 6px;">
      <span style="color:#fff;font-weight:700;font-size:7px;letter-spacing:.05em;">BANK</span>
    </div>
    <div style="padding:4px 6px;font-size:7px;color:#222;line-height:1.2;">
      <div><span style="font-weight:700;">Bank Name:</span> SBI</div>
      <div><span style="font-weight:700;">Branch:</span> Pingla</div>
      <div><span style="font-weight:700;">Current A/C No:</span> 44138024224</div>
      <div><span style="font-weight:700;">IFSC CODE:</span> SBIN0014095</div>
    </div>
  </div>

</div><!-- /bottom left -->

    <!-- BOTTOM RIGHT -->
    <div style="flex:1 1 320px;min-width:320px;display:flex;flex-direction:column;">

      <!-- AMOUNT CALCULATION SECTION -->
      <div style="border:1px solid #cc0000;border-radius:5px;margin:4px 5px;overflow:hidden;">
        <div style="background:#8B1A1A;padding:4px 8px;">
          <span style="color:#fff;font-weight:700;font-size:9px;letter-spacing:.05em;">AMOUNT CALCULATION</span>
        </div>

        <?php
        $calcRows = [
          ['label'=>'Net Amount',        'val'=>$net_amount],
          ['label'=>'Discount',          'val'=>$last_discount],
          ['label'=>'CGST',              'val'=>$last_cgst_amount],
          ['label'=>'SGST',              'val'=>$last_sgst_amount],
          ['label'=>'Round Off',         'val'=>$last_round_off],
        ];
        foreach($calcRows as $cr): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 8px;border-bottom:1px solid #f0d0c0;font-size:8px;">
          <span style="color:#222;flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;"><?php echo $cr['label']; ?></span>
          <span style="color:#222;min-width:45px;text-align:right;">₹<?php echo number_format($cr['val'],2); ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- GROSS AMOUNT SECTION -->
      <div style="border:1px solid #cc0000;border-radius:5px;margin:4px 5px;overflow:hidden;">
        <div style="background:#2F5A1A;padding:4px 8px;">
          <span style="color:#fff;font-weight:700;font-size:9px;letter-spacing:.05em;">GROSS AMOUNT</span>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 8px;border-bottom:1px solid #f0d0c0;font-size:8px;">
          <span style="color:#cc4400;font-weight:700;">Gross Amount</span>
          <span style="color:#cc4400;font-weight:700;min-width:45px;text-align:right;">₹<?php echo number_format($gross_amount,2); ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 8px;border-bottom:1px solid #f0d0c0;font-size:8px;">
          <span style="color:#222;">Amount</span>
          <span style="color:#222;min-width:45px;text-align:right;">₹<?php echo number_format($last_paid_amount,2); ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 8px;font-size:8px;">
          <span style="color:#222;">Amount Paid In Full</span>
          <span style="color:#222;min-width:45px;text-align:right;">₹<?php echo number_format($last_balance_amount,2); ?></span>
        </div>
      </div>

      <!-- RUPEES IN WORDS SECTION -->
      <div style="border:1px solid #cc0000;border-radius:5px;margin:4px 5px;padding:5px 8px;overflow:hidden;font-size:7px;">
        <div style="color:#1a3a7a;font-weight:700;margin-bottom:2px;">Rupees (in words):</div>
        <div style="color:#333;border-bottom:1px dotted #aaa;padding-bottom:2px;min-height:12px;line-height:1.2;"><?php echo convertNumberToWords($last_total); ?></div>
        <div style="border-bottom:1px dotted #aaa;height:8px;margin-top:3px;"></div>
      </div>

      <!-- SIGNATURE SECTION -->
      <div style="border:2px solid #000;border-radius:5px;margin:4px 5px;flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;padding:10px;">
        <div style="width:100%;margin-bottom:5px;min-height:40px;">&nbsp;</div>
        <span style="font-size:8px;color:#222;font-weight:700;">Authorized Signature</span>
      </div>

    </div><!-- /bottom right -->

  </div><!-- /bottom section -->

  <!-- FOOTER -->
  <div style="background:linear-gradient(180deg,#5b9bd5 0%,#1a5fa8 100%);
              padding:14px 30px;display:flex;justify-content:space-between;
              align-items:center;border-top:3px solid #e8a050;">
    <span style="font-size:12px;color:#fff;font-weight:500;">Customer's Signature</span>
    <!-- <span style="font-size:12px;color:#fff;font-weight:500;">Authorised Signature</span> -->
  </div>

</div><!-- /#mgInvoicePrint -->

<!-- Print Button -->
<div style="text-align:center;margin:20px 0;" class="no-print">
  <button onclick="printInvoiceSinglePage()"
    style="background:linear-gradient(135deg,#800020,#d68b16);color:#fff;border:none;
           padding:12px 40px;border-radius:8px;cursor:pointer;font-weight:bold;
           font-size:15px;letter-spacing:1px;">
    &#128424;&#65039; Print Invoice
  </button>
</div>

<?php endif; ?>

<!-- JAVASCRIPT -->
<script>
const ALL_PRODUCTS = <?php echo json_encode($all_products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const itemTypeOptions = <?php echo json_encode($itemTypeOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const defaultItemTypeOptions = {
    'Gold 22K': ['Chur','Bala','Churi','Single Loket','Double Loket','Pearl Choker','JhulaDul','Bauti chur','Soket Bauti','Necklace','Gold Choker','Chain','Jhumka','Tops','Ladies Ring','Gents Ring','Gents Breslet','Ladies Breslet','Pearl sitahar','Tika','Takti','Mantasa','Nosepin','Baby Ring','Baby Breslet','Bali','Pitaring','Breslet Noya','Stell Noya'],
    'Gold 18K': ['Chur','Bala','Churi','Single Loket','Double Loket','Pearl Choker','JhulaDul','Bauti chur','Soket Bauti','Necklace','Gold Choker','Chain','Jhumka','Tops','Ladies Ring','Gents Ring','Gents Breslet','Ladies Breslet','Pearl sitahar','Tika','Takti','Mantasa','Nosepin','Baby Ring','Baby Breslet','Bali','Pitaring','Breslet Noya','Stell Noya'],
    'Silver':   ['Thali','Bati','Glass','Spoon','Showpiece','B.B.C Silver','Mix Silver'],
    'Stone':    ['Natural Pearl','Gomed','Red Coral','Nila','Panna','Jerkon','Amethist','Cats Eye'],
    'Diamond':  ['Ladies Ring','Gents Ring','Tops','Mangal Sutra','Nose pin','Necklace'],
};
const mergedItemTypeOptions = {};
Object.keys(defaultItemTypeOptions).forEach(category => {
    const dbOptions = Array.isArray(itemTypeOptions[category]) ? itemTypeOptions[category] : [];
    mergedItemTypeOptions[category] = Array.from(new Set([...dbOptions, ...defaultItemTypeOptions[category]]));
});
Object.keys(itemTypeOptions).forEach(category => {
    if(!mergedItemTypeOptions[category]) {
        mergedItemTypeOptions[category] = itemTypeOptions[category];
    }
});

let items = [];
let currentTab = 'stock';

// Tab switching
function switchTab(tab) {
    currentTab = tab;
    ['stock','category','manual'].forEach(t => {
        document.getElementById('tab' + t.charAt(0).toUpperCase() + t.slice(1)).classList.toggle('active', t === tab);
        document.getElementById('panel' + t.charAt(0).toUpperCase() + t.slice(1)).classList.toggle('active', t === tab);
    });
}

// Serial / name filter for stock select
function filterProductSelect(query) {
    const select = document.getElementById('productSelect');
    const infoDiv = document.getElementById('selectedProductInfo');
    query = query.trim().toLowerCase();
    infoDiv.classList.add('hidden');
    while(select.options.length > 1) select.remove(1);
    const filtered = query.length > 0
        ? ALL_PRODUCTS.filter(p =>
            (p.serial_no || '').toLowerCase().includes(query) ||
            (p.name || '').toLowerCase().includes(query) ||
            (p.item_name || '').toLowerCase().includes(query) ||
            (p.category || '').toLowerCase().includes(query)
          )
        : ALL_PRODUCTS;
    if(filtered.length === 0) {
        const opt = document.createElement('option');
        opt.value = ''; opt.disabled = true;
        opt.textContent = query ? 'No match — try Manual Entry tab' : 'No products in stock';
        select.appendChild(opt);
        return;
    }
    filtered.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.dataset.price    = p.price;
        opt.dataset.name     = p.name;
        opt.dataset.serial   = p.serial_no;
        opt.dataset.category = p.category;
        opt.dataset.itemName = p.item_name || p.name;
        opt.dataset.qty      = p.quantity;
        const label = (p.item_name ? p.item_name + ' (' + p.name + ')' : p.name) +
            ' | SN:' + (p.serial_no || '-') +
            ' | \u20B9' + parseFloat(p.price).toFixed(2) +
            ' | Stock:' + p.quantity;
        opt.textContent = label;
        select.appendChild(opt);
    });
    if(filtered.length === 1) {
        select.value = filtered[0].id;
        onProductSelectChange();
    }
}

function clearSerialSearch() {
    document.getElementById('serialSearch').value = '';
    document.getElementById('serialSearchResult').classList.add('hidden');
    document.getElementById('selectedProductInfo').classList.add('hidden');
    filterProductSelect('');
}

function searchBySerial() {
    const query = document.getElementById('serialSearch').value.trim();
    if(!query) return;
    filterProductSelect(query);
    const result = document.getElementById('serialSearchResult');
    const select = document.getElementById('productSelect');
    if(select.options.length > 1) {
        result.textContent = '\u2705 Found ' + (select.options.length - 1) + ' result(s). Select and enter GMS.';
        result.style.color = '#059669';
    } else {
        result.textContent = '\u274C No product found for "' + query + '". Use Manual Entry tab.';
        result.style.color = '#dc2626';
    }
    result.classList.remove('hidden');
}

function onProductSelectChange() {
    const select = document.getElementById('productSelect');
    const infoDiv = document.getElementById('selectedProductInfo');
    const opt = select.options[select.selectedIndex];
    if(!opt || !opt.value) { infoDiv.classList.add('hidden'); return; }
    const name  = opt.dataset.itemName || opt.dataset.name;
    const price = parseFloat(opt.dataset.price) || 0;
    const stock = parseFloat(opt.dataset.qty)   || 0;
    const cat   = opt.dataset.category || '';
    const shopRatePerGram = getShopRateForCategory(cat);
    infoDiv.innerHTML = '<strong>' + name + '</strong> &middot; SN: ' + opt.dataset.serial + ' &middot; Category: ' + cat + '<br>' +
        'Rate: \u20B9' + price.toFixed(2) + '/gm (DB) ' + (shopRatePerGram > 0 ? '&middot; Shop Rate: \u20B9' + shopRatePerGram.toFixed(2) + '/gm' : '') + '<br>' +
        'Available Stock: <strong>' + stock + ' GMS</strong>';
    infoDiv.classList.remove('hidden');
}

function addStockItem() {
    const select = document.getElementById('productSelect');
    const productId = select.value;
    const qty = parseFloat(document.getElementById('stockQty').value) || 0;
    if(!productId) { alert('Please select a product from the list.'); return; }
    if(qty <= 0)   { alert('Please enter a valid weight / quantity (GMS).'); return; }
    const opt = select.options[select.selectedIndex];
    const stock = parseFloat(opt.dataset.qty || 0);
    if(stock <= 0) {
        if(!confirm('This item shows 0 or negative stock. Add anyway?')) return;
    } else if(qty > stock) {
        if(!confirm('Quantity (' + qty + ') exceeds stock (' + stock + '). Continue?')) return;
    }
    const name     = opt.dataset.itemName || opt.dataset.name;
    const cat      = opt.dataset.category || '';
    const dbPrice  = parseFloat(opt.dataset.price) || 0;
    const shopRate = getShopRateForCategory(cat);
    const finalRate = shopRate > 0 ? shopRate : dbPrice;
    items.push({
        product_id: productId,
        name: name,
        item_type: '',
        hsn: '7113',
        quantity: qty,
        price: finalRate,
        total: parseFloat((finalRate * qty).toFixed(2)),
        gst_applicable: document.getElementById('gstType').value !== 'non_gst'
    });
    updateItemsList();
    calculateTotal();
    document.getElementById('stockQty').value = '';
    document.getElementById('selectedProductInfo').classList.add('hidden');
    showNotif('\u2705 Added: ' + name + ' (' + qty + ' GMS)', 'success');
}

function updateItemTypes() {
    const category = document.getElementById('itemCategory').value;
    const itemTypeSelect = document.getElementById('itemType');
    itemTypeSelect.innerHTML = '<option value="">-- Select Item Type --</option>';
    if(!category) return;
    const options = mergedItemTypeOptions[category] || [];
    options.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item; opt.textContent = item;
        itemTypeSelect.appendChild(opt);
    });
    const ratePerGram = getShopRateForCategory(category);
    const rateInput = document.getElementById('catRate');
    const rateNote  = document.getElementById('catRateNote');
    if(ratePerGram > 0) {
        rateInput.value = ratePerGram.toFixed(2);
        rateNote.textContent = '(auto from shop: \u20B9' + ratePerGram.toFixed(2) + '/gm)';
    } else {
        rateInput.value = '';
        rateNote.textContent = '(set shop rate or enter manually)';
    }
    onItemTypeChange();
}

function onItemTypeChange() {
    const cat = document.getElementById('itemCategory').value;
    const type = document.getElementById('itemType').value;
    const rateInput = document.getElementById('catRate');
    const rateNote  = document.getElementById('catRateNote');
    if(!type) return;
    const shopRate = getShopRateForCategory(cat);
    if(shopRate > 0) {
        rateInput.value = shopRate.toFixed(2);
        rateNote.textContent = '(shop rate)';
    }
}

function addCategoryItem() {
    const cat    = document.getElementById('itemCategory').value;
    const type   = document.getElementById('itemType').value;
    const qty    = parseFloat(document.getElementById('catQty').value) || 0;
    const rate   = parseFloat(document.getElementById('catRate').value) || 0;
    if(!cat)  { alert('Please select a product category.'); return; }
    if(!type) { alert('Please select an item type.'); return; }
    if(qty <= 0)  { alert('Please enter weight in GMS.'); return; }
    if(rate <= 0) { alert('Please enter rate per gram (or save shop rates).'); return; }
    items.push({
        product_id: 'other',
        name: cat + ' \u2013 ' + type,
        item_type: type,
        hsn: '7113',
        quantity: qty,
        price: rate,
        total: parseFloat((rate * qty).toFixed(2)),
        is_item_only: true,
        gst_applicable: document.getElementById('gstType').value !== 'non_gst'
    });
    updateItemsList();
    calculateTotal();
    document.getElementById('catQty').value  = '';
    document.getElementById('itemType').value = '';
    showNotif('\u2705 Added: ' + cat + ' ' + type, 'success');
}

function calcManualTotal() {
    const gms  = parseFloat(document.getElementById('manualGms').value)  || 0;
    const rate = parseFloat(document.getElementById('manualRate').value) || 0;
    if(gms > 0 && rate > 0) {
        document.getElementById('manualTotal').value = (gms * rate).toFixed(2);
    }
    calculateTotal();
}

function addManualItem() {
    const name  = document.getElementById('manualName').value.trim();
    const gms   = parseFloat(document.getElementById('manualGms').value)   || 0;
    const rate  = parseFloat(document.getElementById('manualRate').value)  || 0;
    const total = parseFloat(document.getElementById('manualTotal').value) || 0;
    const hsn   = document.getElementById('manualHsn').value.trim() || '71131910';
    if(!name)    { alert('Please enter an item description.'); return; }
    if(total <= 0) { alert('Please enter a valid total amount (\u20B9).'); return; }
    items.push({
        product_id: 'other',
        name: name,
        item_type: '',
        hsn: hsn,
        quantity: gms > 0 ? gms : 0,
        price: (gms > 0 && rate > 0) ? rate : 0,
        total: total,
        is_manual: true,
        gst_applicable: document.getElementById('gstType').value !== 'non_gst'
    });
    updateItemsList();
    calculateTotal();
    document.getElementById('manualName').value  = '';
    document.getElementById('manualGms').value   = '0';
    document.getElementById('manualRate').value  = '0';
    document.getElementById('manualTotal').value = '';
    document.getElementById('manualHsn').value   = '71131910';
    showNotif('\u2705 Added: ' + name, 'success');
}

function updateItemsList() {
    const tbody = document.getElementById('itemsList');
    if(items.length === 0) {
        tbody.innerHTML = '<tr id="emptyRow"><td colspan="7" style="text-align:center;padding:20px;color:#9ca3af;font-size:12px;">No items added yet \u2014 use the tabs above to add products</td></tr>';
        return;
    }
    let html = '';
    items.forEach((item, idx) => {
        const icon = item.is_manual ? '\u270F\uFE0F' : (item.is_item_only ? '\uD83C\uDFF7\uFE0F' : '\uD83D\uDC8E');
        const gstChecked = item.gst_applicable ? 'checked' : '';
        const badge = item.is_manual ? '<span style="color:#9ca3af;font-size:10px;">[Manual]</span>' :
                      item.is_item_only ? '<span style="color:#b5730e;font-size:10px;">[Category]</span>' : '';
        html += '<tr>' +
            '<td class="px-2 py-2 text-xs text-center" style="color:#9ca3af;">' + (idx+1) + '</td>' +
            '<td class="px-2 py-2 text-xs" style="color:#374151;">' + icon + ' ' + htmlEsc(item.name) +
                (item.item_type ? '<span style="color:#b5730e;font-size:10px;"> [' + htmlEsc(item.item_type) + ']</span>' : '') +
                badge + '<div style="color:#9ca3af;font-size:10px;">HSN: ' + (item.hsn || '71131910') + '</div></td>' +
            '<td class="px-2 py-2 text-center text-xs" style="color:#6b7280;">' + (item.quantity > 0 ? item.quantity : '\u2014') + '</td>' +
            '<td class="px-2 py-2 text-right text-xs" style="color:#374151;">' + (item.price > 0 ? '\u20B9' + item.price.toFixed(2) : '\u2014') + '</td>' +
            '<td class="px-2 py-2 text-right text-xs font-semibold" style="color:#7a4e0a;">\u20B9' + item.total.toFixed(2) + '</td>' +
            '<td class="px-2 py-2 text-center text-xs"><input type="checkbox" ' + gstChecked + ' onchange="toggleItemGst(' + idx + ')" style="accent-color:#d68b16;" title="Apply GST"></td>' +
            '<td class="px-2 py-2"><button onclick="removeItem(' + idx + ')" class="remove-btn">\u2715</button></td>' +
            '</tr>';
    });
    tbody.innerHTML = html;
}

function htmlEsc(str) {
    if(!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function removeItem(index) { items.splice(index, 1); updateItemsList(); calculateTotal(); }
function toggleItemGst(index) { if(items[index]) { items[index].gst_applicable = !items[index].gst_applicable; calculateTotal(); } }

function calculateTotal() {
    const subtotal = items.reduce((sum, item) => sum + item.total, 0);
    const makingAmt = parseFloat(document.getElementById('makingCharge').value) || 0;
    const hallmark  = parseFloat(document.getElementById('hallmark').value)     || 0;
    const discount  = parseFloat(document.getElementById('discount').value)     || 0;
    const gstType   = document.getElementById('gstType').value;
    const gstBase = items.reduce((sum, item) => sum + (item.gst_applicable ? item.total : 0), 0);
    let cgstRate = 0, sgstRate = 0;
    if (gstType === 'gst_3') {
        cgstRate = 0.015;
        sgstRate = 0.015;
    } else if (gstType === 'gst_18') {
        cgstRate = 0.09;
        sgstRate = 0.09;
    }
    const cgst = gstBase * cgstRate;
    const sgst = gstBase * sgstRate;
    const grand = subtotal + makingAmt + hallmark + cgst + sgst - discount;
    const fmt = v => '\u20B9' + v.toFixed(2);
    document.getElementById('subtotal').textContent       = fmt(subtotal);
    document.getElementById('makingChargeAmount').textContent = fmt(makingAmt);
    document.getElementById('hallmarkAmount').textContent = fmt(hallmark);
    document.getElementById('discountAmount').textContent = '- ' + fmt(discount);
    document.getElementById('cgstAmount').textContent     = fmt(cgst);
    document.getElementById('sgstAmount').textContent     = fmt(sgst);
    document.getElementById('grandTotal').textContent     = fmt(grand);
    document.getElementById('cgstPercent').textContent    = (cgstRate * 100).toFixed(1);
    document.getElementById('sgstPercent').textContent    = (sgstRate * 100).toFixed(1);
    document.getElementById('cgstRow').style.display = (gstType === 'gst_3' || gstType === 'gst_18') ? '' : 'none';
    document.getElementById('sgstRow').style.display = (gstType === 'gst_3' || gstType === 'gst_18') ? '' : 'none';
    document.getElementById('hiddenSubtotal').value  = subtotal;
    document.getElementById('hiddenGst').value       = cgst + sgst;
    document.getElementById('hiddenTotal').value     = grand;
    document.getElementById('hiddenItems').value     = JSON.stringify(items);
    document.getElementById('hiddenMakingCharge').value = makingAmt;
    document.getElementById('hiddenHallmark').value  = hallmark;
    document.getElementById('hiddenDiscount').value  = discount;
    if(document.getElementById('paymentMethod').value === 'Split') {
        document.getElementById('splitGrandTotal').textContent = fmt(grand);
        updateSplitDisplay();
    }
    updateBalanceFromPart();
}

function toggleManualInvoice() {
    const checked = document.getElementById('manualInvoiceToggle').checked;
    document.getElementById('manualInvoiceDiv').style.display = checked ? 'block' : 'none';
    document.getElementById('autoInvoiceInfo').style.display  = checked ? 'none'  : 'block';
}

function toggleMemoBill() {
    const memoChecked  = document.getElementById('memoBillToggle').checked;
    const manualToggle = document.getElementById('manualInvoiceToggle');
    const manualDiv     = document.getElementById('manualInvoiceDiv');
    const autoInfo       = document.getElementById('autoInvoiceInfo');
    const memoInfo        = document.getElementById('memoInvoiceInfo');

    if (memoChecked) {
        manualToggle.checked = false;
        manualToggle.disabled = true;
        manualDiv.style.display = 'none';
        autoInfo.style.display = 'none';
        memoInfo.style.display = 'block';
    } else {
        manualToggle.disabled = false;
        memoInfo.style.display = 'none';
        toggleManualInvoice();
    }
}

// Shop Rates
const shopRates = { gold24:0, gold22:0, gold18:0, silver:0, diamond:0 };
const shopDisplayIds = { gold24:'shopGold24Display', gold22:'shopGold22Display', gold18:'shopGold18Display', silver:'shopSilverDisplay', diamond:'shopDiamondDisplay' };
const shopInputIds   = { gold24:'shopGold24Input',   gold22:'shopGold22Input',   gold18:'shopGold18Input',   silver:'shopSilverInput',   diamond:'shopDiamondInput'   };

function loadShopRates() {
    ['gold24','gold22','gold18','silver','diamond'].forEach(k => {
        const val = localStorage.getItem('shopRate_' + k);
        if(val && parseFloat(val) > 0) {
            shopRates[k] = parseFloat(val);
            document.getElementById(shopInputIds[k]).value = val;
            document.getElementById(shopDisplayIds[k]).textContent = '\u20B9' + parseFloat(val).toLocaleString('en-IN');
        }
    });
    const saved = localStorage.getItem('shopRateSavedAt');
    if(saved) {
        document.getElementById('shopRateSaveStatus').textContent = '\u2714 Saved';
        document.getElementById('shopRateLastSaved').textContent  = 'Last saved: ' + saved;
    }
    calcShopValue();
    fillInvoiceRateBox();
}

function previewShopRate(key) {
    const val = parseFloat(document.getElementById(shopInputIds[key]).value) || 0;
    shopRates[key] = val;
    document.getElementById(shopDisplayIds[key]).textContent = val > 0 ? '\u20B9' + val.toLocaleString('en-IN') : '\u2014';
}

function saveShopRate(key) {
    const val = parseFloat(document.getElementById(shopInputIds[key]).value) || 0;
    if(val <= 0) { alert('Please enter a valid price!'); return; }
    shopRates[key] = val;
    localStorage.setItem('shopRate_' + key, val);
    const now = new Date().toLocaleString('en-IN');
    localStorage.setItem('shopRateSavedAt', now);
    document.getElementById(shopDisplayIds[key]).textContent = '\u20B9' + val.toLocaleString('en-IN');
    document.getElementById('shopRateSaveStatus').textContent = '\u2714 Saved';
    document.getElementById('shopRateLastSaved').textContent  = 'Last saved: ' + now;
    calcShopValue();
    fillInvoiceRateBox();
}

function saveAllShopRates() {
    let saved = 0;
    ['gold24','gold22','gold18','silver','diamond'].forEach(k => {
        const val = parseFloat(document.getElementById(shopInputIds[k]).value) || 0;
        if(val > 0) {
            shopRates[k] = val;
            localStorage.setItem('shopRate_' + k, val);
            document.getElementById(shopDisplayIds[k]).textContent = '\u20B9' + val.toLocaleString('en-IN');
            saved++;
        }
    });
    if(saved === 0) { alert('Please enter at least one price!'); return; }
    const now = new Date().toLocaleString('en-IN');
    localStorage.setItem('shopRateSavedAt', now);
    document.getElementById('shopRateSaveStatus').textContent = '\u2714 All Saved';
    document.getElementById('shopRateLastSaved').textContent  = 'Last saved: ' + now;
    calcShopValue();
    fillInvoiceRateBox();
}

function fillInvoiceRateBox() {
    const stKeys = { gold24:'shopRate_gold24', gold22:'shopRate_gold22', gold18:'shopRate_gold18', silver:'shopRate_silver' };
    document.querySelectorAll('[data-rate-key]').forEach(span => {
        const key = span.getAttribute('data-rate-key');
        const val = parseFloat(localStorage.getItem(stKeys[key])) || 0;
        if(val > 0) {
            span.textContent = '\u20B9' + val.toLocaleString('en-IN', {minimumFractionDigits:2});
        }
    });
}

function getShopRateForCategory(category) {
    category = (category || '').trim();
    const map = { 'Gold 22K':'gold22', 'Gold 18K':'gold18', 'Silver':'silver', 'Diamond':'diamond' };
    const key = map[category];
    if(!key) return 0;
    const inputVal = parseFloat(document.getElementById(shopInputIds[key]).value) || 0;
    if(inputVal > 0) shopRates[key] = inputVal;
    return (shopRates[key] || 0) / 10;
}

function calcShopValue() {
    const metal = document.getElementById('shopMetalSelect').value;
    const grams = parseFloat(document.getElementById('shopMetalGrams').value) || 0;
    const ratePerGram = (shopRates[metal] || 0) / 10;
    const el = document.getElementById('shopCalcResult');
    if(grams > 0 && ratePerGram > 0) {
        el.textContent = '\u2248 \u20B9' + Math.round(ratePerGram * grams).toLocaleString('en-IN');
        el.style.color = '#059669';
    } else if(!shopRates[metal] || shopRates[metal] <= 0) {
        el.textContent = 'Set shop rate first'; el.style.color = '#9ca3af';
    } else {
        el.textContent = '\u2014';
    }
}

// EMI Calculator
function calculateEMI() {
    const P = parseFloat(document.getElementById('loanAmount').value) || 0;
    const r = (parseFloat(document.getElementById('interestRate').value) || 0) / 12 / 100;
    const n = parseFloat(document.getElementById('tenure').value) || 0;
    if(P > 0 && r > 0 && n > 0) {
        const emi = P * r * Math.pow(1+r,n) / (Math.pow(1+r,n) - 1);
        document.getElementById('emiAmount').textContent   = '\u20B9' + emi.toFixed(2);
        document.getElementById('totalPayment').textContent = '\u20B9' + (emi * n).toFixed(2);
        document.getElementById('emiResult').classList.remove('hidden');
    } else {
        alert('Please fill all EMI fields correctly.');
    }
}

// Live Metal Rates
const metalRates = { gold24:0, gold22:0, silver:0, platinum:0 };

async function fetchMetalPrices() {
    const statusEl = document.getElementById('metalPriceStatus');
    statusEl.textContent = '\u21BB Fetching...';
    try {
        const res  = await fetch('metal_rates.php?t=' + Date.now());
        const data = await res.json();
        if(!data.success) throw new Error('API error');
        metalRates.gold24   = data.gold24   / 10;
        metalRates.gold22   = data.gold22   / 10;
        metalRates.silver   = data.silver   / 10;
        metalRates.platinum = data.platinum / 10;
        const fmt = v => '\u20B9' + Math.round(v).toLocaleString('en-IN');
        document.getElementById('gold24Price').textContent   = fmt(data.gold24);
        document.getElementById('gold22Price').textContent   = fmt(data.gold22);
        document.getElementById('silverPrice').textContent   = fmt(data.silver);
        document.getElementById('platinumPrice').textContent = fmt(data.platinum);
        ['gold24Change','gold22Change','silverChange','platinumChange'].forEach(id => {
            document.getElementById(id).textContent = data.fallback ? '\u26A0 Approx' : 'per 10g';
            document.getElementById(id).style.color = data.fallback ? '#d97706' : '#9ca3af';
        });
        statusEl.textContent = data.fallback ? '\u26A0 Approx' : (data.cached ? '\u25CF Cached' : '\u25CF Live');
        statusEl.style.color = data.fallback ? '#d97706' : '#059669';
        const infoEl = document.getElementById('metalUpdateInfo');
        if(infoEl) infoEl.textContent = 'Source: ' + data.source + ' \u00B7 ' + data.updated;
        calcMetalValue();
    } catch(err) {
        statusEl.textContent = '\u2717 Offline'; statusEl.style.color = '#dc2626';
        document.getElementById('gold24Price').textContent   = '\u20B91,56,170';
        document.getElementById('gold22Price').textContent   = '\u20B91,43,052';
        document.getElementById('silverPrice').textContent   = '\u20B92,750';
        document.getElementById('platinumPrice').textContent = '\u20B959,690';
        metalRates.gold24=15617; metalRates.gold22=14305; metalRates.silver=275; metalRates.platinum=5969;
        calcMetalValue();
    }
}

function calcMetalValue() {
    const metal = document.getElementById('metalSelect').value;
    const grams = parseFloat(document.getElementById('metalGrams').value) || 0;
    const rate  = metalRates[metal] || 0;
    const el    = document.getElementById('metalCalcResult');
    el.textContent = grams > 0 && rate > 0 ? '\u2248 \u20B9' + Math.round(rate * grams).toLocaleString('en-IN') : '\u2014';
    el.style.color = '#059669';
}

fetchMetalPrices();
setInterval(fetchMetalPrices, 10 * 60 * 1000);

// Split Payment
function toggleSplitPayment() {
    const method = document.getElementById('paymentMethod').value;
    const splitDiv = document.getElementById('splitPaymentDiv');
    if(method === 'Split') {
        splitDiv.style.display = 'block';
        document.getElementById('hiddenIsSplit').value = '1';
        updateSplitDisplay();
    } else {
        splitDiv.style.display = 'none';
        document.getElementById('hiddenIsSplit').value = '0';
        document.getElementById('cashAmount').value = 0;
        document.getElementById('upiAmount').value  = 0;
        document.getElementById('hiddenCashPaid').value = 0;
        document.getElementById('hiddenUpiPaid').value  = 0;
    }
    togglePartPayment();
}

function onSplitInput(changedField) {
    const grand = parseFloat(document.getElementById('grandTotal').textContent.replace('\u20B9','').replace(/,/g,'')) || 0;
    let cash = parseFloat(document.getElementById('cashAmount').value) || 0;
    let upi  = parseFloat(document.getElementById('upiAmount').value)  || 0;
    cash = Math.min(Math.max(0, cash), grand);
    document.getElementById('cashAmount').value = cash;
    upi = Math.min(Math.max(0, upi), grand);
    document.getElementById('upiAmount').value = upi;
    document.getElementById('hiddenCashPaid').value = cash;
    document.getElementById('hiddenUpiPaid').value  = upi;
    document.getElementById('paidAmount').value = (cash + upi).toFixed(2);
    updateSplitDisplay();
    updateBalanceFromPart();
}

function quickSplit(cashPercent) {
    const grand = parseFloat(document.getElementById('grandTotal').textContent.replace('\u20B9','').replace(/,/g,'')) || 0;
    if(grand <= 0) { alert('Please add items first!'); return; }
    const cash = parseFloat((grand * cashPercent / 100).toFixed(2));
    const upi  = parseFloat((grand - cash).toFixed(2));
    document.getElementById('cashAmount').value = cash;
    document.getElementById('upiAmount').value  = upi;
    document.getElementById('hiddenCashPaid').value = cash;
    document.getElementById('hiddenUpiPaid').value  = upi;
    document.getElementById('paidAmount').value = grand.toFixed(2);
    updateSplitDisplay();
    updateBalanceFromPart();
}

function updateSplitDisplay() {
    const grand = parseFloat(document.getElementById('grandTotal').textContent.replace('\u20B9','').replace(/,/g,'')) || 0;
    const cash  = parseFloat(document.getElementById('cashAmount').value) || 0;
    const upi   = parseFloat(document.getElementById('upiAmount').value)  || 0;
    const paid  = cash + upi;
    const remaining = Math.max(0, grand - paid);
    const fmt = v => '\u20B9' + v.toLocaleString('en-IN', {minimumFractionDigits:2});
    document.getElementById('splitGrandTotal').textContent  = fmt(grand);
    document.getElementById('splitCashDisplay').textContent = fmt(cash);
    document.getElementById('splitUpiDisplay').textContent  = fmt(upi);
    document.getElementById('splitPaidTotal').textContent   = fmt(paid);
    document.getElementById('splitRemaining').textContent   = fmt(remaining);
    const cashPct = grand > 0 ? Math.min(100, (cash / grand) * 100) : 0;
    const upiPct  = grand > 0 ? Math.min(100, (upi  / grand) * 100) : 0;
    document.getElementById('splitProgressCash').style.width = cashPct + '%';
    document.getElementById('splitProgressUpi').style.width  = upiPct  + '%';
    const badge = document.getElementById('splitStatusBadge');
    if(paid <= 0) {
        badge.classList.add('hidden');
    } else if(remaining <= 0.01) {
        badge.classList.remove('hidden');
        badge.style.cssText = 'background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;display:block;text-align:center;padding:8px;border-radius:8px;font-size:12px;font-weight:700;';
        badge.innerHTML = '\u2705 Fully Paid \u2014 Cash ' + fmt(cash) + ' + UPI ' + fmt(upi);
    } else {
        badge.classList.remove('hidden');
        badge.style.cssText = 'background:#fef3c7;color:#92400e;border:1px solid #fcd34d;display:block;text-align:center;padding:8px;border-radius:8px;font-size:12px;font-weight:700;';
        badge.innerHTML = '\u26A0\uFE0F Balance Remaining: ' + fmt(remaining);
    }
}

// Part Payment
function togglePartPayment() {
    const status = document.getElementById('paymentStatus').value;
    const method = document.getElementById('paymentMethod').value;
    const partDiv = document.getElementById('partAmountDiv');
    const balDiv  = document.getElementById('balanceDisplay');
    const grand = parseFloat(document.getElementById('grandTotal').textContent.replace('\u20B9','').replace(/,/g,'')) || 0;
    if(method === 'Split') {
        const cash = parseFloat(document.getElementById('cashAmount').value) || 0;
        const upi  = parseFloat(document.getElementById('upiAmount').value)  || 0;
        const paid = cash + upi;
        const remaining = Math.max(0, grand - paid);
        if(remaining > 0) {
            partDiv.style.display = 'block';
            balDiv.style.display  = 'block';
            document.getElementById('balanceAmt').textContent = '\u20B9' + remaining.toFixed(2);
            document.getElementById('paidAmount').value = paid.toFixed(2);
            if(status === 'paid' && paid > 0) {
                document.getElementById('paymentStatus').value = 'part';
            }
        } else {
            partDiv.style.display = 'none';
            balDiv.style.display  = 'none';
        }
        updateReminderButtonVisibility();
        return;
    }
    if(status === 'part') {
        partDiv.style.display = 'block';
        balDiv.style.display  = 'block';
    } else if(status === 'unpaid') {
        partDiv.style.display = 'none';
        balDiv.style.display  = 'block';
        document.getElementById('balanceAmt').textContent = '\u20B9' + grand.toFixed(2);
    } else {
        partDiv.style.display = 'none';
        balDiv.style.display  = 'none';
    }
    updateReminderButtonVisibility();
}

function updateDueDateHint() {
    const val = document.getElementById('dueDate').value;
    const hint = document.getElementById('dueDateHint');
    const text = document.getElementById('dueDateText');
    if(!val) { hint.classList.add('hidden'); return; }
    const d = new Date(val + 'T00:00:00');
    const today = new Date(); today.setHours(0,0,0,0);
    const diff = Math.round((d - today) / (1000 * 60 * 60 * 24));
    if(diff < 0) {
        text.textContent = 'Date is in the past \u2014 please select a future date.';
        hint.style.color = '#dc2626';
    } else if(diff === 0) {
        text.textContent = 'Due today!';
        hint.style.color = '#d97706';
    } else {
        text.textContent = 'Customer expected to pay in ' + diff + ' day(s) \u2014 on ' +
            d.toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'});
        hint.style.color = '#059669';
    }
    hint.classList.remove('hidden');
}

function updateBalanceFromPart() {
    const method = document.getElementById('paymentMethod').value;
    const status = document.getElementById('paymentStatus').value;
    const grand  = parseFloat(document.getElementById('grandTotal').textContent.replace('\u20B9','').replace(/,/g,'')) || 0;
    const partDiv = document.getElementById('partAmountDiv');
    const balDiv  = document.getElementById('balanceDisplay');
    const extraPaid = (parseFloat(document.getElementById('chequePaidInput')?.value) || 0) + (parseFloat(document.getElementById('oldGoldValueInput')?.value) || 0);
    let balance = 0;

    if(method === 'Split') {
        const cash = parseFloat(document.getElementById('cashAmount').value) || 0;
        const upi  = parseFloat(document.getElementById('upiAmount').value)  || 0;
        const paid = cash + upi + extraPaid;
        const remaining = Math.max(0, grand - paid);
        balance = remaining;
        if(remaining > 0) {
            partDiv.style.display = 'block';
            balDiv.style.display  = 'block';
            document.getElementById('paidAmount').value = (cash + upi).toFixed(2);
            document.getElementById('balanceAmt').textContent = '\u20B9' + remaining.toFixed(2);
            if(status === 'paid' && paid > 0) {
                document.getElementById('paymentStatus').value = 'part';
            }
        } else {
            partDiv.style.display = 'none';
            balDiv.style.display  = 'none';
        }
    } else {
        if(status === 'part') {
            const paid = (parseFloat(document.getElementById('paidAmount').value) || 0) + extraPaid;
            balance = Math.max(0, grand - paid);
            document.getElementById('balanceAmt').textContent = '\u20B9' + balance.toFixed(2);
            partDiv.style.display = 'block';
            balDiv.style.display  = 'block';
        } else if(status === 'unpaid') {
            balance = Math.max(0, grand - extraPaid);
            document.getElementById('balanceAmt').textContent = '\u20B9' + balance.toFixed(2);
            partDiv.style.display = 'none';
            balDiv.style.display  = 'block';
        } else {
            balance = 0;
            partDiv.style.display = 'none';
            balDiv.style.display  = 'none';
        }
    }
    updateReminderButtonVisibility();
}

function updateReminderButtonVisibility() {
    const status = document.getElementById('paymentStatus').value;
    const method = document.getElementById('paymentMethod').value;
    const email = document.getElementById('customerEmail').value.trim();
    const mobile = document.getElementById('customerMobile').value.trim();
    const balanceText = document.getElementById('balanceAmt').textContent.replace(/\u20B9|,/g, '');
    const balance = parseFloat(balanceText) || 0;
    const button = document.getElementById('reminderButton');
    if((status === 'part' || status === 'unpaid' || (method === 'Split' && balance > 0)) && balance > 0 && (email !== '' || mobile !== '')) {
        button.style.display = 'inline-flex';
    } else {
        button.style.display = 'none';
    }
}

function sendPaymentReminder() {
    const email = document.getElementById('customerEmail').value.trim();
    const name = document.getElementById('customerName').value.trim() || 'Customer';
    const mobile = document.getElementById('customerMobile').value.trim();
    const invoiceNo = document.getElementById('manualInvoiceNo').value.trim();
    const balanceText = document.getElementById('balanceAmt').textContent.replace(/\u20B9|,/g, '');
    const balance = parseFloat(balanceText) || 0;
    if(balance <= 0) { alert('There is no due amount to remind.'); return; }
    if(email === '' && mobile === '') { alert('Please enter customer email or mobile number to send the reminder.'); return; }
    const payload = new URLSearchParams();
    payload.append('customer_email', email);
    payload.append('customer_name', name);
    payload.append('customer_mobile', mobile);
    payload.append('invoice_no', invoiceNo);
    payload.append('balance_amount', balance.toFixed(2));
    fetch(window.location.pathname + '?action=send_reminder', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: payload.toString(),
        credentials: 'same-origin'
    })
    .then(async response => {
        const text = await response.text();
        if(!response.ok) throw new Error(text || 'HTTP ' + response.status);
        try { return JSON.parse(text); } catch(err) { throw new Error('Invalid JSON response: ' + text); }
    })
    .then(data => {
        if(data.success) { showNotif(data.message, 'success'); }
        else { showNotif(data.message, 'error'); }
    })
    .catch(error => { showNotif('\u26A0\uFE0F ' + (error?.message || 'Reminder email could not be sent.'), 'error'); });
}

// ── NEW: Due Today — Send Reminder ────────────────────────────────────────
function sendDueReminder(invoiceNo, customerName, customerMobile, balanceAmount) {
    const btn = document.getElementById('remind-btn-' + invoiceNo);
    if(!btn) return;
    btn.disabled = true;
    btn.textContent = '\u23F3 Sending...';

    const payload = new URLSearchParams();
    payload.append('customer_name', customerName);
    payload.append('customer_mobile', customerMobile);
    payload.append('invoice_no', invoiceNo);
    payload.append('balance_amount', balanceAmount.toFixed(2));

    fetch(window.location.pathname + '?action=send_reminder', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: payload.toString(),
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            const card = document.getElementById('duecard-' + invoiceNo);
            if(card) {
                card.style.transition = 'opacity 0.3s, height 0.3s, margin 0.3s, padding 0.3s';
                card.style.opacity = '0';
                card.style.height = '0';
                card.style.margin = '0';
                card.style.padding = '0';
                setTimeout(() => {
                    card.remove();
                    hideDueTodaySectionIfEmpty();
                }, 300);
            } else {
                hideDueTodaySectionIfEmpty();
            }
            showNotif(data.message, 'success');
        } else {
            btn.disabled = false;
            btn.textContent = '\uD83D\uDCE7 Send Reminder';
            showNotif(data.message || 'Could not send reminder.', 'error');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.textContent = '\uD83D\uDCE7 Send Reminder';
        showNotif('Network error. Please try again.', 'error');
    });
}

function hideDueTodaySectionIfEmpty() {
    const section = document.querySelector('.due-today-section');
    if(!section) return;
    const grid = section.querySelector('.due-today-grid');
    if(!grid) return;
    if(grid.querySelectorAll('.due-card').length === 0) {
        section.style.display = 'none';
    }
}

function closeDueTodaySection() {
    const section = document.querySelector('.due-today-section');
    if(!section) return;
    section.style.transition = 'opacity 0.3s, height 0.3s, margin 0.3s, padding 0.3s';
    section.style.opacity = '0';
    section.style.height = '0';
    section.style.margin = '0';
    section.style.padding = '0';
    setTimeout(() => section.style.display = 'none', 300);
}

// Notification helper
function showNotif(msg, type) {
    const d = document.createElement('div');
    d.style.cssText = 'position:fixed;top:80px;right:20px;padding:12px 18px;border-radius:8px;font-size:12px;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,0.15);animation:notifSlide 0.3s ease;background:' + (type==='success'?'#d1fae5':'#fef3c7') + ';color:' + (type==='success'?'#065f46':'#92400e') + ';border:1px solid ' + (type==='success'?'#6ee7b7':'#fcd34d') + ';max-width:280px;';
    d.innerHTML = msg;
    document.body.appendChild(d);
    setTimeout(() => { d.style.opacity='0'; d.style.transition='opacity 0.3s'; setTimeout(()=>d.remove(),300); }, 3500);
}
function fitInvoiceToOnePage() {
    const el = document.getElementById('mgInvoicePrint');
    if (!el) return;

    // reset first so we measure natural size
    el.style.transform = 'none';

    const pxPerMM = 96 / 25.4;
    const pageW = (210 - 16) * pxPerMM; // A4 width minus 8mm+8mm margin
    const pageH = (297 - 16) * pxPerMM; // A4 height minus 8mm+8mm margin

    const rect = el.getBoundingClientRect();

    const scaleX = pageW / rect.width;
    const scaleY = pageH / rect.height;

    el.style.transformOrigin = 'top left';
    el.style.transform = `scale(${scaleX}, ${scaleY})`;
    el.style.width = rect.width + 'px';   // lock width so scale math stays correct
}

function resetInvoiceScale() {
    const el = document.getElementById('mgInvoicePrint');
    if (!el) return;
    el.style.transform = 'none';
    el.style.width = '';
}

function printInvoiceSinglePage() {
    fitInvoiceToOnePage();
    window.print();
}

window.addEventListener('beforeprint', fitInvoiceToOnePage);
window.addEventListener('afterprint', resetInvoiceScale);
// Mobile search
function searchBillsByMobile() {
    const mobile = document.getElementById('searchMobile').value.trim();
    if(mobile.length < 5) { alert('Please enter at least 5 digits!'); return; }
    const resultsDiv = document.getElementById('searchResults');
    const contentDiv = document.getElementById('searchResultsContent');
    contentDiv.innerHTML = '<p style="color:#b5730e;font-size:13px;">\uD83D\uDD0D Searching...</p>';
    resultsDiv.classList.remove('hidden');
    fetch('billing.php?action=search_mobile&mobile=' + encodeURIComponent(mobile))
        .then(r => r.json())
        .then(data => {
            if(!data.success) { contentDiv.innerHTML = '<p style="color:#dc2626;">\u274C ' + data.message + '</p>'; return; }
            if(data.count === 0) { contentDiv.innerHTML = '<p style="color:#d97706;">\u26A0\uFE0F No bills found for: <strong>' + mobile + '</strong></p>'; return; }
            let html = '<p style="color:#059669;font-size:13px;margin-bottom:12px;">\u2705 <strong>' + data.count + '</strong> bill(s) found for <strong>' + data.bills[0].customer_name + '</strong></p>';
            html += '<div class="overflow-x-auto"><table style="width:100%;border-collapse:collapse;font-size:12px;">' +
                '<thead><tr style="background:linear-gradient(135deg,#7a4e0a,#d68b16);">' +
                '<th style="padding:8px;color:#fff;text-align:left;">Invoice No</th>' +
                '<th style="padding:8px;color:#fff;text-align:left;">Customer</th>' +
                '<th style="padding:8px;color:#fff;text-align:left;">Amount</th>' +
                '<th style="padding:8px;color:#fff;text-align:left;">Date</th>' +
                '<th style="padding:8px;color:#fff;text-align:center;">Action</th>' +
                '</tr></thead><tbody>';
            data.bills.forEach((bill,i) => {
                const date = new Date(bill.created_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
                html += '<tr style="background:' + (i%2===0?'#fdf6e3':'#f5ead0') + ';border-bottom:1px solid rgba(181,115,14,0.15);">' +
                    '<td style="padding:8px;color:#7a4e0a;font-weight:600;">' + bill.invoice_no + '</td>' +
                    '<td style="padding:8px;color:#374151;">' + bill.customer_name + '<br><small style="color:#9ca3af;">' + bill.customer_mobile + '</small></td>' +
                    '<td style="padding:8px;color:#059669;font-weight:700;">\u20B9' + parseFloat(bill.total_amount).toLocaleString('en-IN',{minimumFractionDigits:2}) + '</td>' +
                    '<td style="padding:8px;color:#6b7280;">' + date + '</td>' +
                    '<td style="padding:8px;text-align:center;">' +
                    '<a href="view_pdf.php?invoice_no=' + encodeURIComponent(bill.invoice_no) + '" target="_blank" ' +
                    'style="background:linear-gradient(135deg,#800020,#d68b16);color:#fff;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:bold;text-decoration:none;">\uD83D\uDDA8\uFE0F Print</a>' +
                    '</td></tr>';
            });
            html += '</tbody></table></div>';
            contentDiv.innerHTML = html;
        })
        .catch(() => { contentDiv.innerHTML = '<p style="color:#dc2626;">\u274C Network error. Please try again.</p>'; });
}

function clearSearch() {
    document.getElementById('searchMobile').value = '';
    document.getElementById('searchResults').classList.add('hidden');
    document.getElementById('searchResultsContent').innerHTML = '';
}

document.getElementById('searchMobile').addEventListener('keydown', e => { if(e.key === 'Enter') searchBillsByMobile(); });

// Form submit validation
document.getElementById('billingForm').addEventListener('submit', function(e) {
    if(items.length === 0) { e.preventDefault(); alert('\u274C Please add at least one product!'); return false; }
    if(!document.getElementById('customerName').value.trim()) { e.preventDefault(); alert('\u274C Please enter customer name!'); return false; }
    if(!document.getElementById('customerMobile').value.trim()) { e.preventDefault(); alert('\u274C Please enter customer mobile number!'); return false; }
    if(document.getElementById('paymentMethod').value === 'Split') {
        const grand = parseFloat(document.getElementById('grandTotal').textContent.replace('\u20B9','').replace(/,/g,'')) || 0;
        const cash  = parseFloat(document.getElementById('cashAmount').value) || 0;
        const upi   = parseFloat(document.getElementById('upiAmount').value)  || 0;
        if(cash + upi > grand + 0.5) { e.preventDefault(); alert('\u26A0\uFE0F Split total (\u20B9' + (cash+upi).toFixed(2) + ') cannot exceed Grand Total (\u20B9' + grand.toFixed(2) + ')!'); return false; }
    }
    document.getElementById('hiddenItems').value = JSON.stringify(items);
});

function fitInvoiceToOnePage() {
    const el = document.getElementById('mgInvoicePrint');
    if (!el) return;

    // reset first so we measure natural (unzoomed) size
    el.style.zoom = 1;

    const pxPerMM = 96 / 25.4;
    // Use the smaller of A4 / Letter dimensions so it fits either paper size
    const pageH = (279.4 - 16) * pxPerMM; // Letter height (shorter than A4) minus 8mm+8mm margin
    const pageW = (210   - 16) * pxPerMM; // A4 width (narrower than Letter) minus 8mm+8mm margin

    const rect = el.getBoundingClientRect();
    const scale = Math.min(1, pageH / rect.height, pageW / rect.width);

    if (scale < 1) {
        el.style.zoom = scale;
    }
}

function resetInvoiceScale() {
    const el = document.getElementById('mgInvoicePrint');
    if (!el) return;
    el.style.zoom = 1;
}

function printInvoiceSinglePage() {
    fitInvoiceToOnePage();
    window.print();
}

window.addEventListener('beforeprint', fitInvoiceToOnePage);
window.addEventListener('afterprint', resetInvoiceScale);

function resetInvoiceScale() {
    const el = document.getElementById('mgInvoicePrint');
    if (!el) return;
    el.style.transform = '';
    el.style.width = '';
}

function printInvoiceSinglePage() {
    fitInvoiceToOnePage();
    window.print();
}

// Safety net in case the browser fires the native print dialog another way
window.addEventListener('beforeprint', fitInvoiceToOnePage);
window.addEventListener('afterprint', resetInvoiceScale);

// Init
loadShopRates();
updateItemsList();
updateReminderButtonVisibility();
document.getElementById('customerEmail').addEventListener('input', updateReminderButtonVisibility);
document.getElementById('dueDate').addEventListener('change', updateDueDateHint);
document.getElementById('customerMobile').addEventListener('input', updateReminderButtonVisibility);
document.getElementById('paymentStatus').addEventListener('change', updateReminderButtonVisibility);
if(ALL_PRODUCTS.length > 0) { filterProductSelect(''); }
</script>
</body>
</html>

<?php
function convertNumberToWords($number) {
    if($number <= 0) return 'Zero Rupees Only';
    $amount = round($number);
    $rupees = (int)$amount;
    $words = [
        0=>'',1=>'One',2=>'Two',3=>'Three',4=>'Four',5=>'Five',6=>'Six',7=>'Seven',8=>'Eight',9=>'Nine',
        10=>'Ten',11=>'Eleven',12=>'Twelve',13=>'Thirteen',14=>'Fourteen',15=>'Fifteen',16=>'Sixteen',
        17=>'Seventeen',18=>'Eighteen',19=>'Nineteen',20=>'Twenty',30=>'Thirty',40=>'Forty',50=>'Fifty',
        60=>'Sixty',70=>'Seventy',80=>'Eighty',90=>'Ninety'
    ];
    $result = '';
    if($rupees >= 10000000) { $c=floor($rupees/10000000); $result.=($c<20?$words[$c]:$words[floor($c/10)*10].($c%10?' '.$words[$c%10]:'')).' Crore '; $rupees%=10000000; }
    if($rupees >= 100000)   { $c=floor($rupees/100000);   $result.=($c<20?$words[$c]:$words[floor($c/10)*10].($c%10?' '.$words[$c%10]:'')).' Lakh ';  $rupees%=100000; }
    if($rupees >= 1000)     { $c=floor($rupees/1000);     $result.=($c<20?$words[$c]:$words[floor($c/10)*10].($c%10?' '.$words[$c%10]:'')).' Thousand '; $rupees%=1000; }
    if($rupees >= 100)      { $result.=$words[floor($rupees/100)].' Hundred '; $rupees%=100; }
    if($rupees > 0) {
        if($rupees < 20) $result.=$words[$rupees].' ';
        else $result.=$words[floor($rupees/10)*10].($rupees%10?' '.$words[$rupees%10]:'').' ';
    }
    return trim($result).' Rupees Only';
}
?>