<?php

require_once __DIR__ . '/../config/env.php';

/** Transport adapter only; controllers must enqueue mail once the queue is integrated. */
final class Mailer
{
    public static function send($to, $subject, $body, $isHtml = false, ?string $messageKey = null): bool
    {
        if (!is_string($to) || !filter_var($to, FILTER_VALIDATE_EMAIL) || !is_string($subject)
            || strlen($subject) > 200 || preg_match('/[\r\n]/', $subject) || !is_string($body) || strlen($body) > 65536) {
            throw new InvalidArgumentException('Invalid email message.');
        }
        if($messageKey!==null&&!preg_match('/\A[a-f0-9]{64}\z/',$messageKey))throw new InvalidArgumentException('Invalid message key.');
        if(env('MAIL_TRANSPORT','disabled')==='test'&&env('APP_ENV','production')!=='production') {
            if(filter_var(env('MAIL_TEST_FAILURE',false),FILTER_VALIDATE_BOOLEAN))throw new RuntimeException('Test transport failed.');
            $message=json_encode(['to'=>$to,'subject'=>$subject,'body'=>$body,'message_id'=>$messageKey],JSON_THROW_ON_ERROR);
            $path=PrivateStorage::directory('test-mail').'/'.bin2hex(random_bytes(16)).'.json';$mask=umask(0077);
            try {if(file_put_contents($path,$message,LOCK_EX)!==strlen($message))throw new RuntimeException('Test transport failed.');}
            finally {umask($mask);}return true;
        }
        if (env('MAIL_TRANSPORT', 'disabled') !== 'smtp') return false;
        try {
            $mail = self::getMailer();
            $mail->addAddress($to);
            $mail->isHTML((bool) $isHtml);
            $mail->Subject = $subject;
            $mail->Body = $body;
            if($messageKey!==null)$mail->MessageID='<'.$messageKey.'@sarada.invalid>';
            return $mail->send();
        } catch (Throwable $e) {
            // Provider errors can contain addresses, credentials and connection details.
            throw new RuntimeException('Email delivery is unavailable.');
        }
    }

    protected static function getMailer()
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) throw new RuntimeException('Mail transport is unavailable.');
        $host = (string) env('MAIL_HOST', '');
        $username = (string) env('MAIL_USERNAME', '');
        $password = (string) env('MAIL_PASSWORD', '');
        $from = (string) env('MAIL_FROM_ADDRESS', '');
        $port = (string) env('MAIL_PORT', '587');
        $encryption = (string) env('MAIL_ENCRYPTION', 'tls');
        if ($host === '' || preg_match('/[\x00-\x20]/', $host) || $username === '' || $password === ''
            || !filter_var($from, FILTER_VALIDATE_EMAIL) || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
            || !in_array($encryption, ['tls', 'ssl'], true)) throw new RuntimeException('Mail configuration is unavailable.');
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->Port = (int) $port;
        $mail->SMTPSecure = $encryption;
        $mail->SMTPDebug = 0;
        $timeout=filter_var(env('MAIL_TIMEOUT_SECONDS',10),FILTER_VALIDATE_INT);
        if($timeout===false||$timeout<1||$timeout>10)throw new RuntimeException('Mail timeout must be 1 to 10 seconds.');
        $mail->Timeout = $timeout;
        $mail->Timelimit = $timeout;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($from, (string) env('MAIL_FROM_NAME', 'Maa Sarada'));
        return $mail;
    }
}
