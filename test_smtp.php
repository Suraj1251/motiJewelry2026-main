<?php
require 'vendor/phpmailer/phpmailer/src/Exception.php';
require 'vendor/phpmailer/phpmailer/src/PHPMailer.php';
require 'vendor/phpmailer/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: text/plain');

$mail = new PHPMailer(true);
$mail->SMTPDebug = 2;
$mail->Debugoutput = function($str, $level) {
    echo $str . "\n";
};
$mail->isSMTP();
$mail->Host       = 'smtp.gmail.com';
$mail->SMTPAuth   = true;
$mail->Username   = 'santudhara157@gmail.com';
$mail->Password   = 'drmhuagqcxajlmig';
$mail->SMTPSecure = 'tls';
$mail->Port       = 587;

try {
    $mail->setFrom('santudhara157@gmail.com', 'Test');
    $mail->addAddress('santudhara157@gmail.com');
    $mail->Subject = 'Test';
    $mail->Body    = 'Test mail';
    $mail->send();
    echo "\nSUCCESS\n";
} catch (Exception $e) {
    echo "\nFAILED: " . $mail->ErrorInfo . "\n";
}
