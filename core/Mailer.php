<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __BASEDIR__ . '/vendor/autoload.php';

class Mailer
{
    protected static function getMailer()
    {
        $mail = new PHPMailer(true);

        // SMTP Config
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'bcasudipta@gmail.com';        // your Gmail
        $mail->Password   = 'cgcw bvfe jgqv jbsm';          // 16 digit App password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Default From
        $mail->setFrom('bcasudipta@gmail.com', 'Sudipta');

        return $mail;
    }

    public static function send($to, $subject, $body, $isHtml = false)
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid recipient email: $to");
        }

        $mail = self::getMailer();
        try {
            $mail->addAddress($to);
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->send();
            return true;
        } catch (Exception $e) {
            throw new Exception("Mailer Error: {$mail->ErrorInfo}");
        }
    }

    public static function VerifyMail($mail){
        $mail->Subject = "Email Verification";
        $mail->Body    = "Please click the link below to verify your email address:\n\n" . 
                         "https://yourdomain.com/verify?email=" . urlencode($mail->getToAddresses()[0][0]);
        return self::send($mail->getToAddresses()[0][0], $mail->Subject, $mail->Body);
    }
}
