<?php
session_start();
require_once 'config/database.php';

if(!isset($_SESSION['user_id'])) {
    exit(json_encode([]));
}

$mobile = mysqli_real_escape_string($conn, $_GET['mobile']);
$result = mysqli_query($conn, "SELECT invoice_no, total_amount, created_at, 
                               CASE WHEN pdf_file IS NOT NULL THEN 1 ELSE 0 END as has_pdf 
                               FROM invoices WHERE customer_mobile = '$mobile' ORDER BY created_at DESC");

$invoices = [];
while($row = mysqli_fetch_assoc($result)) {
    $invoices[] = [
        'invoice_no' => $row['invoice_no'],
        'total_amount' => number_format($row['total_amount'], 2),
        'date' => date('d M Y', strtotime($row['created_at'])),
        'has_pdf' => (int)$row['has_pdf']
    ];
}

header('Content-Type: application/json');
echo json_encode($invoices);
?>