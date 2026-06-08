<?php
require_once 'config/database.php';

$fixes = [
    2 => 'Supriyo@123',
    4 => 'Admin@123',
];

foreach ($fixes as $id => $plain_password) {
    $hash = password_hash($plain_password, PASSWORD_DEFAULT);
    mysqli_query($conn, "UPDATE users SET password = '$hash' WHERE id = $id");
    echo "✅ User ID $id done.<br>";
}

echo "<br><b>Delete this file now!</b>";
?>