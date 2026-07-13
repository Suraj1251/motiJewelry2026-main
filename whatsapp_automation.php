<?php
session_start();
require_once 'config/database.php';

if(!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Create/alter tables
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM whatsapp_settings LIKE 'reminder_days'");
if(mysqli_num_rows($check_column) == 0) {
    mysqli_query($conn, "ALTER TABLE whatsapp_settings ADD COLUMN reminder_days INT DEFAULT 3");
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS whatsapp_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_type VARCHAR(50) DEFAULT 'greenapi',
    api_url VARCHAR(255),
    api_token VARCHAR(255),
    instance_id VARCHAR(100),
    reminder_days INT DEFAULT 3,
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS whatsapp_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_number VARCHAR(20),
    recipient_name VARCHAR(100),
    message_type VARCHAR(50),
    message_content TEXT,
    media_file_path TEXT,
    media_file_name VARCHAR(255),
    status VARCHAR(20),
    api_response TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Ensure columns exist for older DB schemas
$check = mysqli_query($conn, "SHOW COLUMNS FROM whatsapp_logs LIKE 'media_file_path'");
if(mysqli_num_rows($check) == 0) {
    mysqli_query($conn, "ALTER TABLE whatsapp_logs ADD COLUMN media_file_path TEXT");
}
$check2 = mysqli_query($conn, "SHOW COLUMNS FROM whatsapp_logs LIKE 'media_file_name'");
if(mysqli_num_rows($check2) == 0) {
    mysqli_query($conn, "ALTER TABLE whatsapp_logs ADD COLUMN media_file_name VARCHAR(255)");
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS advance_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT,
    customer_name VARCHAR(100),
    customer_mobile VARCHAR(15),
    advance_amount DECIMAL(10,2) DEFAULT 0,
    advance_date DATE,
    due_date DATE,
    reminder_days INT DEFAULT 3,
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$upload_dir = 'uploads/whatsapp_media/';
if(!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

function sendWhatsAppMessage($phone, $message, $conn, $mediaFile = null, $mediaType = 'text') {
    $settings = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM whatsapp_settings WHERE status = 'active' LIMIT 1"));
    if(!$settings) return ['success' => false, 'error' => 'No active API settings found.'];

    $phone = preg_replace('/[^0-9]/', '', $phone);
    if(substr($phone, 0, 1) == '0') $phone = substr($phone, 1);
    if(substr($phone, 0, 2) != '91') $phone = '91' . $phone;

    $result = ['success' => false, 'error' => 'Unknown error'];

    if($settings['api_type'] == 'greenapi') {
        $instanceId = trim($settings['instance_id']);
        $apiToken   = trim($settings['api_token']);
        $baseUrl    = rtrim(trim($settings['api_url']), '/');

        if($mediaType != 'text' && $mediaFile && file_exists($mediaFile)) {
            $url = "{$baseUrl}/waInstance{$instanceId}/sendFileByUpload/{$apiToken}";
            $ch = curl_init();
            curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['chatId' => $phone.'@c.us', 'file' => new CURLFile($mediaFile), 'caption' => $message],
                CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false, CURLOPT_TIMEOUT => 120]);
            $response  = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            $result = $curl_error ? ['success'=>false,'error'=>'CURL: '.$curl_error]
                                  : ($http_code==200||$http_code==201 ? ['success'=>true,'response'=>$response]
                                  : ['success'=>false,'error'=>"HTTP {$http_code}: ".$response]);
        } else {
            $url = "{$baseUrl}/waInstance{$instanceId}/sendMessage/{$apiToken}";
            $ch = curl_init();
            curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['chatId'=>$phone.'@c.us','message'=>$message]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 60]);
            $response  = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $result = ($http_code==200||$http_code==201) ? ['success'=>true,'response'=>$response] : ['success'=>false,'error'=>"HTTP {$http_code}"];
        }
    }
    return $result;
}

function logWhatsAppMessage($number,$name,$type,$message,$mediaFile,$mediaName,$status,$response,$conn) {
    $q = function($v) use ($conn) { return mysqli_real_escape_string($conn, is_null($v)?'':$v); };
    mysqli_query($conn,"INSERT INTO whatsapp_logs (recipient_number,recipient_name,message_type,message_content,media_file_path,media_file_name,status,api_response,sent_at) VALUES ('{$q($number)}','{$q($name)}','{$q($type)}','{$q($message)}','{$q($mediaFile)}','{$q($mediaName)}','{$q($status)}','{$q($response)}',NOW())");
}

// Save API Settings
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['save_api_settings'])) {
    $api_type    = mysqli_real_escape_string($conn, $_POST['api_type']);
    $api_url     = rtrim(mysqli_real_escape_string($conn, $_POST['api_url']), '/');
    $api_token   = mysqli_real_escape_string($conn, $_POST['api_token']);
    $instance_id = mysqli_real_escape_string($conn, $_POST['instance_id']??'');
    $reminder_days = intval($_POST['reminder_days']??3);
    mysqli_query($conn,"UPDATE whatsapp_settings SET status='inactive'");
    if(mysqli_query($conn,"INSERT INTO whatsapp_settings (api_type,api_url,api_token,instance_id,reminder_days,status) VALUES ('$api_type','$api_url','$api_token','$instance_id',$reminder_days,'active')"))
        $api_success = "✅ API settings saved successfully!";
    else $api_error = "❌ Error: ".mysqli_error($conn);
}

// Send Single
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['send_single'])) {
    $number        = $_POST['number']??'';
    $message       = $_POST['message']??'';
    $customer_name = $_POST['customer_name']??'';
    $media_type    = $_POST['single_media_type']??'text';
    $media_file_path = $media_file_name = '';

    if(isset($_FILES['single_media_file']) && $_FILES['single_media_file']['error']==0) {
        $allowed = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/avi','video/mov'];
        if(in_array(mime_content_type($_FILES['single_media_file']['tmp_name']), $allowed)) {
            $ext = pathinfo($_FILES['single_media_file']['name'], PATHINFO_EXTENSION);
            $fn  = time().'_'.rand(1000,9999).'.'.$ext;
            $up  = 'uploads/whatsapp_media/'.$fn;
            if(move_uploaded_file($_FILES['single_media_file']['tmp_name'], $up)) { $media_file_path=$up; $media_file_name=$_FILES['single_media_file']['name']; }
        } else $single_error = "❌ Invalid file type! Only images and videos allowed.";
    }

    if(empty($number)) $single_error = "❌ Please enter a mobile number!";
    elseif(empty($message) && empty($media_file_path)) $single_error = "❌ Please enter a message or select media!";
    else {
        $result = sendWhatsAppMessage($number, $message, $conn, $media_file_path, $media_type);
        if($result['success']) { logWhatsAppMessage($number,$customer_name,'single',$message,$media_file_path,$media_file_name,'sent',json_encode($result),$conn); $single_success="✅ Message sent to $number!"; }
        else { logWhatsAppMessage($number,$customer_name,'single',$message,$media_file_path,$media_file_name,'failed',$result['error'],$conn); $single_error="❌ Failed: ".$result['error']; }
    }
}

// Send Bulk
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['send_bulk'])) {
    $selected = $_POST['selected_customers']??[];
    $bulk_msg  = $_POST['bulk_message']??'';
    $media_type= $_POST['media_type']??'text';
    $media_file_path=$media_file_name='';
    $sent_count=$failed_count=0; $recipient_list=[];

    if(isset($_FILES['bulk_media_file']) && $_FILES['bulk_media_file']['error']==0) {
        $allowed=['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/avi','video/mov'];
        if(in_array(mime_content_type($_FILES['bulk_media_file']['tmp_name']),$allowed)) {
            $ext=pathinfo($_FILES['bulk_media_file']['name'],PATHINFO_EXTENSION);
            $fn=time().'_'.rand(1000,9999).'.'.$ext; $up='uploads/whatsapp_media/'.$fn;
            if(move_uploaded_file($_FILES['bulk_media_file']['tmp_name'],$up)) { $media_file_path=$up; $media_file_name=$_FILES['bulk_media_file']['name']; }
        } else $bulk_error="❌ Invalid file type!";
    }

    if(empty($selected)) $bulk_error="❌ Please select at least one customer!";
    elseif(empty($bulk_msg)&&empty($media_file_path)) $bulk_error="❌ Please enter a message or select media!";
    else {
        foreach($selected as $cid) {
            $c=mysqli_fetch_assoc(mysqli_query($conn,"SELECT name,mobile FROM customers WHERE id=".intval($cid)));
            if($c) {
                $recipient_list[]=$c['name'].' ('.$c['mobile'].')';
                $r=sendWhatsAppMessage($c['mobile'],$bulk_msg,$conn,$media_file_path,$media_type);
                if($r['success']) { $sent_count++; logWhatsAppMessage($c['mobile'],$c['name'],'bulk',$bulk_msg,$media_file_path,$media_file_name,'sent',json_encode($r),$conn); }
                else { $failed_count++; logWhatsAppMessage($c['mobile'],$c['name'],'bulk',$bulk_msg,$media_file_path,$media_file_name,'failed',$r['error'],$conn); }
            }
        }
        $bulk_result="✅ Sent: $sent_count | ❌ Failed: $failed_count";
    }
}

// Advance reminders
if(isset($_GET['send_advance_reminders'])) {
    $rdays=intval($_GET['reminder_days']??3);
    $adv_q=mysqli_query($conn,"SELECT * FROM advance_customers WHERE status='active' AND due_date>=CURDATE() AND DATEDIFF(due_date,CURDATE())<=$rdays");
    $sent_count=$failed_count=0;
    while($c=mysqli_fetch_assoc($adv_q)) {
        $dl=mysqli_fetch_assoc(mysqli_query($conn,"SELECT DATEDIFF('{$c['due_date']}',CURDATE()) as days"));
        $dlv=$dl?$dl['days']:0;
        $msg="💎 *MAA GOURI JEWELLERS - PAYMENT REMINDER* 💎\n\nDear {$c['customer_name']},\n\nYour advance payment is due in *$dlv days*.\n\n📅 Due: ".date('d-m-Y',strtotime($c['due_date']))."\n💰 Amount: ₹".number_format($c['advance_amount'],2)."\n\nPlease pay at earliest convenience.\n\nThank you! ✨";
        $r=sendWhatsAppMessage($c['customer_mobile'],$msg,$conn);
        if($r['success']) { $sent_count++; logWhatsAppMessage($c['customer_mobile'],$c['customer_name'],'advance_reminder',$msg,'','','sent',json_encode($r),$conn); }
        else { $failed_count++; logWhatsAppMessage($c['customer_mobile'],$c['customer_name'],'advance_reminder',$msg,'','','failed',$r['error'],$conn); }
    }
    $advance_reminder_result="✅ Reminders Sent: $sent_count | ❌ Failed: $failed_count";
}

$api_settings         = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM whatsapp_settings WHERE status='active' LIMIT 1"));
$all_customers        = mysqli_query($conn,"SELECT id,name,mobile FROM customers ORDER BY name");
$logs                 = mysqli_query($conn,"SELECT * FROM whatsapp_logs ORDER BY sent_at DESC LIMIT 30");
$advance_customers_list = mysqli_query($conn,"SELECT * FROM advance_customers WHERE status='active' ORDER BY due_date ASC");
$total_customers      = mysqli_num_rows($all_customers);

$logo_paths = ['assets/images/moti-removebg-preview.png','images/moti-removebg-preview.png','moti-removebg-preview.png'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="author" content="MANU GUPTA">
    <title>WhatsApp Automation — Maa Gouri Jewellers</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap');

        * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
        h1,h2,h3,.gold-font { font-family: 'Playfair Display', serif; }

        /* ========== SIDEBAR ========== */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: 240px; height: 100vh;
            background: linear-gradient(180deg, #7a4e0a 0%, #b5730e 40%, #d68b16 100%);
            z-index: 1000; display: flex; flex-direction: column;
            box-shadow: 4px 0 24px rgba(0,0,0,0.25);
            transition: transform 0.35s cubic-bezier(.4,0,.2,1);
            overflow-y: auto; overflow-x: hidden;
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }

        .sidebar-logo { padding: 22px 18px 16px; border-bottom: 1px solid rgba(255,255,255,0.18); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .sidebar-logo img { width: 44px; height: 44px; object-fit: contain; border-radius: 50%; background: rgba(255,255,255,0.1); padding: 3px; flex-shrink: 0; }
        .sidebar-logo-text h2 { color: #fff; font-size: 13px; font-weight: 700; line-height: 1.3; font-family: 'Playfair Display', serif; letter-spacing: 0.5px; }
        .sidebar-logo-text p  { color: rgba(255,255,255,0.65); font-size: 10px; margin-top: 1px; }

        .sidebar-nav { flex: 1; padding: 10px 0; }
        .sidebar-section-label { padding: 10px 20px 4px; color: rgba(255,255,255,0.45); font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; }

        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 11px 20px; color: rgba(255,255,255,0.85); text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s ease; border-left: 3px solid transparent; letter-spacing: 0.3px; position: relative; }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.13); color: #fff; border-left-color: rgba(255,255,255,0.8); padding-left: 26px; }
        .sidebar-nav a.active { background: rgba(255,255,255,0.22); color: #fff; border-left-color: #fff; font-weight: 700; }
        .sidebar-nav a.active::after { content: ''; position: absolute; right: 0; top: 50%; transform: translateY(-50%); width: 4px; height: 60%; background: #fff; border-radius: 4px 0 0 4px; }
        .sidebar-nav a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; opacity: 0.9; }

        .sidebar-divider { height: 1px; background: rgba(255,255,255,0.12); margin: 6px 16px; }

        .sidebar-user { padding: 14px 16px 18px; border-top: 1px solid rgba(255,255,255,0.18); background: rgba(0,0,0,0.12); flex-shrink: 0; }
        .sidebar-user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .sidebar-user-info i { color: rgba(255,255,255,0.9); font-size: 26px; flex-shrink: 0; }
        .sidebar-user-info .user-details p { color: #fff; font-size: 12px; font-weight: 600; line-height: 1.3; }
        .sidebar-user-info .user-details span { color: rgba(255,255,255,0.55); font-size: 10px; }

        .sidebar-logout { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 9px 14px; background: rgba(239,68,68,0.75); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; transition: background 0.2s; border: 1px solid rgba(239,68,68,0.4); }
        .sidebar-logout:hover { background: #ef4444; color: #fff; }

        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; backdrop-filter: blur(2px); }
        .sidebar-overlay.active { display: block; }

        /* ========== LAYOUT ========== */
        .page-wrapper { margin-left: 240px; min-height: 100vh; background: #F5F5F5; transition: margin-left 0.35s ease; }
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
            nav.nav-gold { margin-left: 0 !important; }
        }
        @media (min-width: 769px) { .mobile-burger { display: none !important; } }

        /* ========== PAGE STYLES ========== */
        body { background: #F5F5F5; margin: 0; padding: 0; }

        .page-heading { background: linear-gradient(135deg, #fdf6e3, #f5ead0); border-bottom: 2px solid rgba(181,115,14,0.2); padding: 20px 28px; }
        .page-heading h1 { color: #800020; font-size: 1.6rem; }
        .page-heading p  { color: #7a4e0a; font-size: 13px; margin-top: 2px; }

        /* Cards */
        .jewel-card { background: #fff; border: 1px solid rgba(181,115,14,0.2); border-radius: 20px; box-shadow: 0 4px 20px rgba(181,115,14,0.08); }
        .wa-card   { border-top: 3px solid #25D366; }
        .api-card  { border-top: 3px solid #d68b16; }
        .bulk-card { border-top: 3px solid #7c3aed; }
        .adv-card  { border-top: 3px solid #db2777; }
        .log-card  { border-top: 3px solid #2563eb; }

        /* Section titles */
        .title-wa   { color: #16a34a; }
        .title-api  { color: #800020; }
        .title-bulk { color: #7c3aed; }
        .title-adv  { color: #db2777; }
        .title-log  { color: #2563eb; }

        /* Inputs */
        .jewel-input { background: #fdf6e3; border: 1.5px solid rgba(181,115,14,0.3); color: #4a3000; border-radius: 10px; padding: 8px 12px; font-size: 13px; transition: all 0.25s; width: 100%; font-family: 'Poppins', sans-serif; outline: none; }
        .jewel-input:focus { border-color: #d68b16; box-shadow: 0 0 0 3px rgba(214,139,22,0.15); background: #fffdf5; }
        .jewel-input::placeholder { color: rgba(122,78,10,0.4); }
        select.jewel-input option { background: #fff; color: #4a3000; }
        .jewel-input[type="file"] { padding: 6px 12px; cursor: pointer; }

        .field-label { display: block; font-size: 11px; font-weight: 600; color: #7a4e0a; margin-bottom: 5px; }

        /* Buttons */
        .btn-gold    { background: linear-gradient(135deg, #800020, #d68b16); color: #fff; border: none; border-radius: 10px; padding: 9px 20px; font-weight: 700; font-size: 13px; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 6px; font-family: 'Poppins', sans-serif; }
        .btn-gold:hover { transform: scale(1.04); box-shadow: 0 8px 24px rgba(214,139,22,0.35); }

        .btn-green   { background: linear-gradient(135deg, #16a34a, #15803d); color: #fff; border: none; border-radius: 10px; padding: 9px 20px; font-weight: 700; font-size: 13px; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 6px; font-family: 'Poppins', sans-serif; }
        .btn-green:hover { transform: scale(1.04); box-shadow: 0 8px 20px rgba(22,163,74,0.4); }

        .btn-purple  { background: linear-gradient(135deg, #7c3aed, #6d28d9); color: #fff; border: none; border-radius: 10px; padding: 9px 20px; font-weight: 700; font-size: 13px; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 6px; font-family: 'Poppins', sans-serif; text-decoration: none; }
        .btn-purple:hover { transform: scale(1.04); box-shadow: 0 8px 20px rgba(124,58,237,0.4); color: #fff; }

        .btn-pink    { background: linear-gradient(135deg, #db2777, #be185d); color: #fff; border: none; border-radius: 10px; padding: 9px 20px; font-weight: 700; font-size: 13px; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 6px; font-family: 'Poppins', sans-serif; text-decoration: none; }
        .btn-pink:hover { transform: scale(1.04); box-shadow: 0 8px 20px rgba(219,39,119,0.4); color: #fff; }

        .btn-sm-link { background: none; border: none; cursor: pointer; font-size: 12px; font-weight: 600; font-family: 'Poppins', sans-serif; transition: opacity 0.2s; padding: 0; }

        /* Table */
        .jewel-table { width: 100%; border-collapse: collapse; }
        .jewel-table th { background: linear-gradient(135deg, #7a4e0a, #d68b16); color: #fff; font-weight: 600; padding: 10px 10px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .jewel-table td { padding: 9px 10px; border-bottom: 1px solid rgba(181,115,14,0.1); color: #3a2800; font-size: 12px; }
        .jewel-table tbody tr:hover { background: #fdf6e3; }
        .jewel-table tbody tr:nth-child(even) { background: #fffbf0; }
        .jewel-table tbody tr:nth-child(even):hover { background: #fdf6e3; }

        /* Customer checkbox list */
        .customer-select-box { max-height: 220px; overflow-y: auto; background: #fdf6e3; border: 1.5px solid rgba(181,115,14,0.25); border-radius: 12px; padding: 12px; }
        .customer-select-box::-webkit-scrollbar { width: 5px; }
        .customer-select-box::-webkit-scrollbar-thumb { background: rgba(181,115,14,0.3); border-radius: 4px; }

        .cust-label { display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 4px 6px; border-radius: 6px; transition: background 0.15s; font-size: 12px; color: #4a3000; }
        .cust-label:hover { background: rgba(214,139,22,0.12); }
        .cust-label input[type="checkbox"] { accent-color: #d68b16; width: 14px; height: 14px; flex-shrink: 0; }

        /* Info box */
        .info-box { background: #fdf6e3; border: 1px solid rgba(181,115,14,0.25); border-radius: 10px; padding: 10px 14px; font-size: 12px; color: #7a4e0a; }
        .info-box a { color: #16a34a; text-decoration: underline; }

        /* Alerts */
        .alert { display: flex; align-items: flex-start; gap: 8px; padding: 10px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; margin-bottom: 14px; }
        .alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
        .alert-error   { background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; }
        .alert-info    { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
        .alert-purple  { background: #f5f3ff; border: 1px solid #c4b5fd; color: #5b21b6; }

        /* Recipient box */
        .recipient-box { background: #fdf6e3; border: 1px solid rgba(181,115,14,0.2); border-radius: 10px; padding: 12px 16px; font-size: 12px; color: #4a3000; max-height: 160px; overflow-y: auto; margin-top: 6px; }

        /* Status badges */
        .badge-sent   { background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .badge-failed { background: #fecdd3; color: #9f1239; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 700; }

        /* Days-left colors */
        .days-urgent  { color: #ea580c; font-weight: 700; }
        .days-overdue { color: #dc2626; font-weight: 700; }
        .days-ok      { color: #16a34a; }

        /* Footer */
        footer { background: linear-gradient(0deg, #f5e6c8, #fdf6e3); border-top: 2px solid #d68b16; padding: 20px; text-align: center; margin-top: 40px; }

        @media (max-width: 640px) {
            .table-wrap { overflow-x: auto; }
            .jewel-table { min-width: 520px; }
        }
    </style>
</head>
<body>

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

<!-- Loading Overlay -->
<div id="loadingOverlay" style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999;display:flex;justify-content:center;align-items:center;overflow:hidden;transition:opacity 0.6s ease,visibility 0.6s ease;background:radial-gradient(ellipse at 50% 60%, #1a0a00 0%, #0d0500 100%);">

    <!-- Scanlines texture -->
    <div style="position:absolute;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 3px,rgba(214,139,22,0.015) 3px,rgba(214,139,22,0.015) 4px);pointer-events:none;z-index:1;"></div>

    <!-- Corner ornaments -->
    <!-- background diamonds removed to keep only central gem -->

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

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ========== SIDEBAR ========== -->
<div class="sidebar" id="mainSidebar">
    <div class="sidebar-logo">
        <?php
        $logo_found = false;
        foreach($logo_paths as $path) {
            if(file_exists($path)) { echo '<img src="'.$path.'" alt="Logo">'; $logo_found=true; break; }
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
        <a href="index.php"><i class="fas fa-home"></i> HOME</a>
        <a href="billing.php"><i class="fas fa-receipt"></i> BILLING</a>
        <a href="stock.php"><i class="fas fa-boxes"></i> STOCK</a>
        <a href="customers.php"><i class="fas fa-users"></i> CUSTOMERS</a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Analytics</div>
        <a href="reports.php"><i class="fas fa-chart-bar"></i> REPORTS</a>
        <a href="income_expenses.php"><i class="fas fa-chart-line"></i> INCOME &amp; EXP</a>

        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">Tools</div>
        <a href="whatsapp_automation.php" class="active"><i class="fab fa-whatsapp"></i> WHATSAPP</a>
        <a href="sbook.php"><i class="fas fa-book"></i> KARIGORI</a>
         <a href="purchase.php">
            <i class="fas fa-book"></i>PURCHASE
        </a>
        <a href="account.php">
            <i class="fas fa-book"></i> ACCOUNT
        </a>
    </nav>

    <div class="sidebar-user">
        <div class="sidebar-user-info">
            <i class="fas fa-user-circle"></i>
                <div class="user-details">
                <p><?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                <span>MAA GOURI JEWELLERS</span>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout"><i class="fas fa-sign-out-alt"></i> LOGOUT</a>
    </div>
</div>
<!-- ========== END SIDEBAR ========== -->

<!-- ========== NAVBAR ========== -->
<nav class="nav-gold shadow-lg sticky top-0 z-50" style="margin-left:240px;">
    <div class="container mx-auto px-4 sm:px-6 py-3 sm:py-4">
        <div class="flex justify-between items-center">
            <div class="ml-auto flex items-center gap-4">
                <span class="text-sm font-medium text-white hidden sm:inline">
                    <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($_SESSION['user_name']); ?>
                </span>
                <div class="mobile-burger" style="display:none;">
                    <div class="burger-menu" id="burgerMenu" onclick="toggleSidebar()">
                        <span></span><span></span><span></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- ========== PAGE WRAPPER ========== -->
<div class="page-wrapper">

    <div class="page-heading">
        <h1 class="gold-font"><i class="fab fa-whatsapp mr-2" style="color:#25D366;"></i> WhatsApp Automation</h1>
        <p>Send messages, bulk broadcasts &amp; advance payment reminders</p>
    </div>

    <div class="container mx-auto px-4 sm:px-6 py-6">

        <!-- Alerts -->
        <?php if(isset($api_success)): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><?php echo $api_success; ?></div><?php endif; ?>
        <?php if(isset($api_error)):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?php echo $api_error; ?></div><?php endif; ?>
        <?php if(isset($single_success)): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><?php echo $single_success; ?></div><?php endif; ?>
        <?php if(isset($single_error)):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?php echo $single_error; ?></div><?php endif; ?>
        <?php if(isset($bulk_result)):    ?>
            <div class="alert alert-info"><i class="fas fa-paper-plane"></i><?php echo $bulk_result; ?></div>
            <?php if(!empty($recipient_list)): ?>
                <div class="recipient-box mb-4">
                    <p class="font-semibold mb-2" style="color:#7a4e0a;">📋 Recipients (<?php echo count($recipient_list); ?> customers):</p>
                    <?php foreach($recipient_list as $r): ?><div>💎 <?php echo htmlspecialchars($r); ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if(isset($bulk_error)):             ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?php echo $bulk_error; ?></div><?php endif; ?>
        <?php if(isset($advance_reminder_result)):?><div class="alert alert-purple"><i class="fas fa-bell"></i><?php echo $advance_reminder_result; ?></div><?php endif; ?>

        <!-- ── API Settings ── -->
        <div class="jewel-card api-card p-5 mb-6">
            <h2 class="gold-font text-lg font-bold mb-4 title-api"><i class="fas fa-plug mr-2"></i>WhatsApp API Settings</h2>
            <form method="POST">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <label class="field-label">🌐 API Type</label>
                        <select name="api_type" class="jewel-input">
                            <option value="greenapi" <?php echo ($api_settings&&$api_settings['api_type']=='greenapi')?'selected':''; ?>>💚 Green API</option>
                            <option value="custom"   <?php echo ($api_settings&&$api_settings['api_type']=='custom')?'selected':''; ?>>⚙️ Custom API</option>
                        </select>
                    </div>
                    <div>
                        <label class="field-label">🔗 API URL</label>
                        <input type="text" name="api_url" value="<?php echo htmlspecialchars($api_settings['api_url']??'https://api.green-api.com'); ?>" placeholder="https://api.green-api.com" class="jewel-input">
                    </div>
                    <div>
                        <label class="field-label">🆔 Instance ID</label>
                        <input type="text" name="instance_id" value="<?php echo htmlspecialchars($api_settings['instance_id']??''); ?>" placeholder="Your Instance ID" class="jewel-input">
                    </div>
                    <div>
                        <label class="field-label">🔑 API Token</label>
                        <input type="text" name="api_token" value="<?php echo htmlspecialchars($api_settings['api_token']??''); ?>" placeholder="Your API Token" class="jewel-input">
                    </div>
                </div>
                <button type="submit" name="save_api_settings" class="btn-gold mt-4">
                    <i class="fas fa-save mr-1"></i> Save Settings
                </button>
            </form>
            <div class="info-box mt-4">
                <i class="fas fa-info-circle mr-1" style="color:#d68b16;"></i>
                <strong>Green API Setup:</strong> Get your Instance ID &amp; API Token from
                <a href="https://console.green-api.com" target="_blank">green-api.com</a>
            </div>
        </div>

        <!-- ── Single Message ── -->
        <div class="jewel-card wa-card p-5 mb-6">
            <h2 class="gold-font text-lg font-bold mb-4 title-wa"><i class="fab fa-whatsapp mr-2"></i>Send Single Message</h2>
            <form method="POST" enctype="multipart/form-data">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="field-label">📱 Mobile Number *</label>
                        <input type="tel" name="number" placeholder="9876543210" required class="jewel-input">
                    </div>
                    <div>
                        <label class="field-label">👤 Customer Name (Optional)</label>
                        <input type="text" name="customer_name" placeholder="Customer name" class="jewel-input">
                    </div>
                    <div>
                        <label class="field-label">📎 Media Type</label>
                        <select name="single_media_type" id="singleMediaType" class="jewel-input" onchange="toggleMedia('single')">
                            <option value="text">📝 Text Only</option>
                            <option value="image">🖼️ Image</option>
                            <option value="video">🎥 Video</option>
                        </select>
                    </div>
                    <div id="singleMediaUploadDiv" style="display:none;">
                        <label class="field-label">📁 Choose File</label>
                        <input type="file" name="single_media_file" accept="image/*,video/*" class="jewel-input">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="field-label">📝 Message</label>
                        <textarea name="message" rows="3" placeholder="Type your message here…" class="jewel-input"></textarea>
                    </div>
                </div>
                <button type="submit" name="send_single" class="btn-green mt-4">
                    <i class="fas fa-paper-plane"></i> Send Message
                </button>
            </form>
        </div>

        <!-- ── Bulk Message ── -->
        <div class="jewel-card bulk-card p-5 mb-6">
            <h2 class="gold-font text-lg font-bold mb-4 title-bulk"><i class="fas fa-users mr-2"></i>Bulk Message
                <span class="text-sm font-normal ml-2" style="color:#9ca3af;">(<?php echo $total_customers; ?> customers)</span>
            </h2>
            <form method="POST" enctype="multipart/form-data">
                <label class="field-label mb-1">📋 Select Customers</label>
                <div class="flex gap-3 mb-2">
                    <button type="button" class="btn-sm-link" style="color:#16a34a;" onclick="selectAll()">✅ Select All</button>
                    <button type="button" class="btn-sm-link" style="color:#dc2626;" onclick="deselectAll()">❌ Deselect All</button>
                </div>
                <div class="customer-select-box mb-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-1">
                        <?php mysqli_data_seek($all_customers, 0); while($c = mysqli_fetch_assoc($all_customers)): ?>
                            <label class="cust-label">
                                <input type="checkbox" name="selected_customers[]" value="<?php echo $c['id']; ?>">
                                <span>💎 <?php echo htmlspecialchars($c['name']); ?> (<?php echo $c['mobile']; ?>)</span>
                            </label>
                        <?php endwhile; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="field-label">📎 Media Type</label>
                        <select name="media_type" id="bulkMediaType" class="jewel-input" onchange="toggleMedia('bulk')">
                            <option value="text">📝 Text Only</option>
                            <option value="image">🖼️ Image</option>
                            <option value="video">🎥 Video</option>
                        </select>
                    </div>
                    <div id="bulkMediaUploadDiv" style="display:none;">
                        <label class="field-label">📁 Choose File</label>
                        <input type="file" name="bulk_media_file" accept="image/*,video/*" class="jewel-input">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="field-label">📝 Bulk Message</label>
                        <textarea name="bulk_message" rows="3" placeholder="Message to send to all selected customers…" class="jewel-input"></textarea>
                    </div>
                </div>
                <button type="submit" name="send_bulk" class="btn-purple mt-4">
                    <i class="fas fa-paper-plane"></i> Send to Selected
                </button>
            </form>
        </div>

        <!-- ── Advance Customers ── -->
        <div class="jewel-card adv-card p-5 mb-6">
            <h2 class="gold-font text-lg font-bold mb-4 title-adv"><i class="fas fa-star mr-2"></i>Advance Customers &amp; Reminders</h2>

            <div class="flex flex-wrap items-end gap-3 mb-4 p-4 rounded-xl" style="background:#fdf6e3;border:1px solid rgba(181,115,14,0.15);">
                <div>
                    <label class="field-label">⏰ Reminder Filter</label>
                    <select id="reminderDaysFilter" class="jewel-input" style="width:180px;">
                        <option value="1">1 day before</option>
                        <option value="2">2 days before</option>
                        <option value="3" selected>3 days before</option>
                        <option value="5">5 days before</option>
                        <option value="7">7 days before</option>
                        <option value="10">10 days before</option>
                        <option value="15">15 days before</option>
                    </select>
                </div>
                <a href="#" id="sendFilteredReminders" class="btn-pink">
                    <i class="fas fa-bell"></i> Send Reminders
                </a>
            </div>

            <div class="table-wrap overflow-x-auto rounded-xl" style="border:1px solid rgba(219,39,119,0.15);">
                <table class="jewel-table">
                    <thead>
                        <tr>
                            <th class="text-left">Customer</th>
                            <th class="text-left">Mobile</th>
                            <th class="text-right">Amount</th>
                            <th class="text-center">Due Date</th>
                            <th class="text-center">Reminder</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        mysqli_data_seek($advance_customers_list, 0);
                        if(mysqli_num_rows($advance_customers_list) > 0):
                        while($adv = mysqli_fetch_assoc($advance_customers_list)):
                            $dl  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT DATEDIFF('{$adv['due_date']}', CURDATE()) as days"));
                            $dlv = $dl ? $dl['days'] : 0;
                            $dc  = $dlv<=0 ? 'days-overdue' : ($dlv<=$adv['reminder_days'] ? 'days-urgent' : 'days-ok');
                            $dt  = $dlv>0 ? "$dlv days left" : ($dlv==0 ? 'Due today' : 'Overdue');
                        ?>
                        <tr>
                            <td class="font-semibold" style="color:#800020;">💎 <?php echo htmlspecialchars($adv['customer_name']); ?></td>
                            <td>📱 <?php echo htmlspecialchars($adv['customer_mobile']); ?></td>
                            <td class="text-right font-bold" style="color:#d68b16;">₹<?php echo number_format($adv['advance_amount'],2); ?></td>
                            <td class="text-center">📅 <?php echo date('d M Y', strtotime($adv['due_date'])); ?></td>
                            <td class="text-center"><?php echo $adv['reminder_days']; ?> days</td>
                            <td class="text-center <?php echo $dc; ?>"><?php echo $dt; ?></td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="6" class="text-center py-8" style="color:#7a4e0a;opacity:0.6;">No advance customers found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Message Logs ── -->
        <div class="jewel-card log-card p-5">
            <h2 class="gold-font text-lg font-bold mb-4 title-log"><i class="fas fa-history mr-2"></i>Message Logs <span class="text-sm font-normal" style="color:#9ca3af;">(Last 30)</span></h2>
            <div class="table-wrap overflow-x-auto rounded-xl" style="border:1px solid rgba(37,99,235,0.15);">
                <table class="jewel-table">
                    <thead>
                        <tr>
                            <th class="text-left">Date &amp; Time</th>
                            <th class="text-left">Recipient</th>
                            <th class="text-left">Type</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($logs && mysqli_num_rows($logs) > 0):
                        while($log = mysqli_fetch_assoc($logs)): ?>
                        <tr>
                            <td style="color:#6b7280;"><?php echo date('d M Y H:i', strtotime($log['sent_at'])); ?></td>
                            <td class="font-semibold" style="color:#800020;"><?php echo htmlspecialchars($log['recipient_name'] ?: $log['recipient_number']); ?></td>
                            <td style="color:#7a4e0a;text-transform:capitalize;">
                                <?php echo htmlspecialchars(str_replace('_',' ',$log['message_type'])); ?>
                                <?php if(!empty($log['media_file_name'])): ?> <span title="Has media">📎</span><?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if($log['status']=='sent'): ?>
                                    <span class="badge-sent">✓ Sent</span>
                                <?php else: ?>
                                    <span class="badge-failed">✗ Failed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="4" class="text-center py-8" style="color:#7a4e0a;opacity:0.6;">No messages sent yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <footer>
        <p class="text-xs" style="color:#7a4e0a;">
            &copy; 2026 MAA GOURI JEWELLERS &nbsp;|&nbsp; CRAFTED WITH ELEGANCE &nbsp;|&nbsp;
            Developed by <a href="https://saamparktechnologyresearch.in/" target="_blank" style="text-decoration:underline;color:#800020;">STR</a>
        </p>
    </footer>
</div><!-- end .page-wrapper -->

<style>
@media (max-width: 768px) { nav.nav-gold { margin-left: 0 !important; } }
</style>

<script>
    /* ── Sidebar ── */
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
        const b = document.getElementById('burgerMenu');
        if(b) b.classList.remove('active');
        document.body.style.overflow = '';
    }

    /* ── Media toggle ── */
    function toggleMedia(prefix) {
        const sel = document.getElementById(prefix + 'MediaType');
        const div = document.getElementById(prefix + 'MediaUploadDiv');
        if(sel && div) div.style.display = sel.value !== 'text' ? 'block' : 'none';
    }

    /* ── Customer checkboxes ── */
    function selectAll()   { document.querySelectorAll('input[name="selected_customers[]"]').forEach(c => c.checked = true); }
    function deselectAll() { document.querySelectorAll('input[name="selected_customers[]"]').forEach(c => c.checked = false); }

    /* ── Advance reminder link ── */
    const reminderBtn = document.getElementById('sendFilteredReminders');
    if(reminderBtn) {
        reminderBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const days = document.getElementById('reminderDaysFilter').value;
            if(confirm('Send reminders to customers due within ' + days + ' days?')) {
                window.location.href = '?send_advance_reminders=1&reminder_days=' + days;
            }
        });
    }
</script>
</body>
</html>