<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';

// SMTP settings: update these values for your mail provider.
// For reliable email delivery, configure a valid SMTP provider and set SMTP_USERNAME and SMTP_PASSWORD.
// Example for Gmail: SMTP_HOST='smtp.gmail.com', SMTP_PORT=587, SMTP_SECURE='tls', and use an App Password.
// The PHP mail() fallback only works if your local Windows/XAMPP environment has a working SMTP relay configured
// in php.ini via SMTP and smtp_port. If not, set SMTP credentials above and use PHPMailer.
define('MAIL_FROM_ADDRESS', 'santudhara157@gmail.com');
define('MAIL_FROM_NAME', 'Maa Gouri Jewellers');
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'santudhara157@gmail.com');
define('SMTP_PASSWORD', 'drmhuagqcxajImig');
define('SMTP_SECURE', 'tls'); // tls or ssl

define('SMTP_DEBUG', 0);

function sendSMTPMail($to, $subject, $message) {
    if(empty(SMTP_USERNAME) || SMTP_USERNAME === 'your-smtp-username' || empty(SMTP_PASSWORD) || SMTP_PASSWORD === 'your-smtp-password') {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM_ADDRESS . ">\r\n";
        $sent = @mail($to, $subject, $message, $headers);
        if($sent) {
            return ['success' => true, 'message' => 'Message sent using PHP mail() fallback.'];
        }
        $lastError = error_get_last();
        $phpError = $lastError['message'] ?? 'Failed to connect to local mailserver.';
        $errorMessage = 'PHP mail() fallback failed. Configure SMTP_USERNAME and SMTP_PASSWORD in config/mail_config.php, or enable a local mailserver in php.ini (SMTP/smtp_port). ' . $phpError;
        error_log('[mail_config] ' . $errorMessage);
        return ['success' => false, 'message' => $errorMessage];
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPDebug  = SMTP_DEBUG;

        $fromAddress = SMTP_USERNAME;
        if(empty($fromAddress) || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $fromAddress = MAIL_FROM_ADDRESS;
        }
        $mail->setFrom($fromAddress, MAIL_FROM_NAME);
        if($fromAddress !== MAIL_FROM_ADDRESS && filter_var(MAIL_FROM_ADDRESS, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        }
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $message;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<p>', '</p>'], ' ', $message));

        $mail->send();
        return ['success' => true, 'message' => 'Message sent'];
    } catch (Exception $e) {
        $errorMessage = $mail->ErrorInfo ?: $e->getMessage();
        error_log('[mail_config] Mail error: ' . $errorMessage);
        return ['success' => false, 'message' => $errorMessage];
    }
}


