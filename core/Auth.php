<?php
require_once 'config/db.php';
require_once 'Mailer.php'; // Assuming you have a Mailer class

class Auth
{
    private static $cookieName = 'remember_me';
    private static $cookieExpiry = 30 * 24 * 60 * 60; // 30 days in seconds

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Check if user is authenticated
     * @return bool
     */
    public static function isAuthenticated()
    {
        // Check session first
        if (isset($_SESSION['user_id'])) {
            return true;
        }

        // Check remember me cookie
        return self::validateRememberToken();
    }

    /**
     * Check if user's email is verified
     * @return bool
     */
    public static function isEmailVerified()
    {
        if (!self::isAuthenticated()) {
            return false;
        }
        return $_SESSION['email_verified'] ?? false;
    }

    /**
     * Login user with email and password
     * @param string $email
     * @param string $password
     * @param bool $remember Remember user
     * @return array
     */
    public static function login($email, $password, $remember = false)
    {
        $user = db_fetch_one("SELECT * FROM users_tbl WHERE email = ?", [$email]);

        if (!$user) {
            return ['status' => false, 'message' => 'Invalid credentials'];
        }

        if (!password_verify($password, $user['password'])) {
            return ['status' => false, 'message' => 'Invalid credentials'];
        }

        // Check if email is verified
        if (!$user['email_verify']) {
            return ['status' => false, 'message' => 'Email not verified', 'requires_verification' => true];
        }

        // Create user session
        self::createSession($user);

        // Set remember me cookie if requested
        if ($remember) {
            self::setRememberToken($user['id']);
        }

        // Update last login time
        date_default_timezone_set("Asia/Kolkata");
        db_execute("UPDATE users_tbl SET login_time=? WHERE id=?", [date("Y-m-d H:i:s"), $user['id']]);

        return ['status' => true, 'message' => 'Login successful'];
    }

    /**
     * Logout user
     * @param string $redirectPath Path to redirect after logout
     */
    public static function logout($redirectPath = 'auth/login')
    {
        // Clear session data
        $_SESSION = [];

        // Delete session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        // Destroy the session
        session_destroy();

        // Delete remember me cookie
        if (isset($_COOKIE[self::$cookieName])) {
            setcookie(self::$cookieName, '', time() - 3600, '/');
        }

        // Redirect
        header("Location: " . base_url($redirectPath));
        exit;
    }

    /**
     * Register new user
     * @param string $firstname
     * @param string $lastname
     * @param string $name
     * @param string $email
     * @param string $password
     * @param string|null $designation
     * @return array
     */
    public static function register($firstname, $lastname, $name, $email, $password, $designation = null)
    {
        // Check if email exists
        $existing = db_fetch_one("SELECT id FROM users_tbl WHERE email = ?", [$email]);
        if ($existing) {
            return ['status' => false, 'message' => 'Email already registered'];
        }

        // Hash password
        $hashed = password_hash($password, PASSWORD_BCRYPT);

        // Generate verification token
        $verifyToken = bin2hex(random_bytes(32));

        // Insert user
        $sql = "INSERT INTO users_tbl (firstname, lastname, name, email, password, designation, email_verify, verify_token, status) 
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, 1)";
        db_execute($sql, [$firstname, $lastname, $name, $email, $hashed, $designation, $verifyToken]);

        // Get the new user ID
        $userId = db_last_insert_id();

        // Send verification email
        self::sendVerificationEmail($userId, $email, $verifyToken);

        return ['status' => true, 'message' => 'Registration successful. Please check your email for verification.'];
    }

    /**
     * Create user session
     * @param array $user User data
     */
    private static function createSession($user)
    {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'] ?? 'user';
        $_SESSION['email_verified'] = (bool)$user['email_verify'];
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time();
    }

    /**
     * Set remember me token cookie
     * @param int $userId
     */
    private static function setRememberToken($userId)
    {
        $token = bin2hex(random_bytes(32));
        $expiry = time() + self::$cookieExpiry;

        // Store token in database
        db_execute("UPDATE users_tbl SET remember_token=? WHERE id=?", [$token, $userId]);

        // Set cookie
        setcookie(
            self::$cookieName,
            $token,
            $expiry,
            '/',
            '',
            false,  // Secure
            true    // HttpOnly
        );
    }

    /**
     * Validate remember me token
     * @return bool
     */
    private static function validateRememberToken()
    {
        if (!isset($_COOKIE[self::$cookieName])) {
            return false;
        }

        $token = $_COOKIE[self::$cookieName];
        $user = db_fetch_one("SELECT * FROM users_tbl WHERE remember_token = ?", [$token]);

        if ($user) {
            self::createSession($user);
            return true;
        }

        return false;
    }

    /**
     * Send verification email
     * @param int $userId
     * @param string $email
     * @param string $token
     * @return bool
     */
    private static function sendVerificationEmail($userId, $email, $token)
    {
        $verificationLink = base_url("auth/verify_email?token=$token&id=$userId");
        
        $subject = "Verify Your Email Address";
        $message = "Please click the following link to verify your email address: \n\n";
        $message .= $verificationLink;
        $message .= "\n\nIf you didn't create an account, please ignore this email.";

        return Mailer::send($email, $subject, $message);
    }

    /**
     * Verify user's email
     * @param int $userId
     * @param string $token
     * @return array
     */
    public static function verifyEmail($userId, $token)
    {
        $user = db_fetch_one("SELECT * FROM users_tbl WHERE id = ? AND verify_token = ?", [$userId, $token]);

        if (!$user) {
            return ['status' => false, 'message' => 'Invalid verification token or user ID'];
        }

        if ($user['email_verify']) {
            return ['status' => true, 'message' => 'Email already verified'];
        }

        // Update verification status
        db_execute("UPDATE users_tbl SET email_verify=1, verify_token=NULL WHERE id=?", [$userId]);

        // Update session if user is logged in
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $userId) {
            $_SESSION['email_verified'] = true;
        }

        return ['status' => true, 'message' => 'Email successfully verified'];
    }

    /**
     * Resend verification email
     * @param string $email
     * @return array
     */
    public static function resendVerificationEmail($email)
    {
        $user = db_fetch_one("SELECT id, email_verify, verify_token FROM users_tbl WHERE email = ?", [$email]);

        if (!$user) {
            return ['status' => false, 'message' => 'Email not found'];
        }

        if ($user['email_verify']) {
            return ['status' => false, 'message' => 'Email already verified'];
        }

        // Generate new token if none exists
        if (empty($user['verify_token'])) {
            $token = bin2hex(random_bytes(32));
            db_execute("UPDATE users_tbl SET verify_token=? WHERE id=?", [$token, $user['id']]);
        } else {
            $token = $user['verify_token'];
        }

        // Send verification email
        self::sendVerificationEmail($user['id'], $email, $token);

        return ['status' => true, 'message' => 'Verification email resent'];
    }
}