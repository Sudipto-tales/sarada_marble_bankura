<?php

/** Customer presentation delegates identity and address validation to shared services. */
class CustomerAccount extends StorefrontController
{
    public function index(): void
    {
        $this->handle(function (): void {
            $actor = CommerceAccess::actor();
            $addresses = $actor ? (new AddressService($this->pdo))->listing($actor['id']) : [];
            $devices = $actor ? CustomerOtp::deviceListing($actor['id']) : [];
            $deviceTrusted = $actor ? CustomerOtp::trusted($actor['id']) : false;
            $pinSet = $actor ? CustomerOtp::pinAvailable($actor['id']) : false;
            $pinLogin = !$actor && CustomerOtp::currentDevicePinAvailable();
            $this->respond('app/page/storefront/account.php', compact('actor', 'addresses', 'devices', 'deviceTrusted', 'pinSet', 'pinLogin'));
        });
    }
    public function login(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $email = CommerceValues::text($_POST['email'] ?? '', 190, 'email');
            $password = $_POST['password'] ?? '';
            if (!is_string($password)) CommerceValues::invalid('password');
            $result = Auth::verifyPassword($email, $password);
            if ($result['throttled']) throw new RequestRejected(429, 'login_failed', 'Too many attempts. Try again in 15 minutes.');
            if (!$result['user']) throw new RequestRejected(401, 'login_failed', 'Invalid credentials or access unavailable.');
            $user = $result['user'];
            // A verified login still passes a one-time code unless this device is already trusted.
            if (CustomerOtp::loginRequired() && !CustomerOtp::trusted((int) $user['id'])) {
                $_SESSION['pending_otp'] = ['user_id' => (int) $user['id'], 'purpose' => 'login', 'email' => $user['email']];
                $sent = CustomerOtp::request((int) $user['id'], 'login', $user['email']);
                $this->respond('app/page/storefront/otp_verify.php', ['purpose' => 'login', 'email' => $user['email'], 'sent' => $sent, 'offerTrust' => true]);
                return;
            }
            $this->completeLogin((int) $user['id']);
        });
    }
    /** Establish the session and carry over the guest cart / pending buy-now. */
    private function completeLogin(int $userId, bool $remember = false): void
    {
        $guest = CartService::guestPrincipal();
        Auth::establishSession($userId, $remember);
        $actor = CommerceAccess::requireActor();
        if ($guest !== null) {
            $merge = (new CartService($this->pdo))->merge($guest, $actor['id']);
            CartService::clearGuestCookie();
            $_SESSION['cart_merge_excluded'] = count($merge['excluded']);
        }
        if (isset($_SESSION['pending_buy_now'])) {
            $pending = $_SESSION['pending_buy_now']; unset($_SESSION['pending_buy_now']);
            $cart = (new CartService($this->pdo))->buyNow($actor['id'], $pending['variant_id'], $pending['quantity'], $pending['key']);
            $this->redirect('checkout?purchase_cart=' . $cart['id']); return;
        }
        $this->redirect('');
    }
    public function otp(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $pending = $_SESSION['pending_otp'] ?? null;
            if (!is_array($pending) || !isset($pending['user_id'], $pending['purpose'])) {
                throw new RequestRejected(400, 'otp_context_missing', 'Your verification session expired. Please start again.');
            }
            $code = CommerceValues::text($_POST['code'] ?? '', 6, 'code');
            $userId = (int) $pending['user_id']; $purpose = (string) $pending['purpose'];
            if (!CustomerOtp::verify($userId, $purpose, $code)) {
                throw new RequestRejected(422, 'otp_invalid', 'That code is invalid or expired. Request a new one.');
            }
            unset($_SESSION['pending_otp']);
            if ($purpose === 'register') {
                Auth::markVerified($userId);
                $this->respond('app/page/storefront/message.php', ['title' => 'Email verified', 'message' => 'Your account is verified. You can now sign in.']);
                return;
            }
            if (($_POST['trust_device'] ?? '') === '1') CustomerOtp::trustCurrentDevice($userId);
            $this->completeLogin($userId);
        });
    }
    public function resendOtp(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $pending = $_SESSION['pending_otp'] ?? null;
            if (!is_array($pending) || !isset($pending['user_id'], $pending['purpose'], $pending['email'])) {
                throw new RequestRejected(400, 'otp_context_missing', 'Your verification session expired. Please start again.');
            }
            $sent = CustomerOtp::request((int) $pending['user_id'], (string) $pending['purpose'], (string) $pending['email']);
            $this->respond('app/page/storefront/otp_verify.php', ['purpose' => $pending['purpose'], 'email' => $pending['email'], 'sent' => $sent, 'offerTrust' => $pending['purpose'] === 'login']);
        });
    }
    public function logout(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation(); Auth::logout();
            unset($_SESSION['checkout_keys'], $_SESSION['cart_merge_excluded'], $_SESSION['pending_buy_now'], $_SESSION['pending_otp']);
            $this->redirect('account');
        });
    }
    public function deleteStart(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $actor = CommerceAccess::requireActor();
            $_SESSION['pending_otp'] = ['user_id' => (int) $actor['id'], 'purpose' => 'delete', 'email' => $actor['email']];
            $sent = CustomerOtp::request((int) $actor['id'], 'delete', $actor['email']);
            $this->respond('app/page/storefront/otp_verify.php',
                ['purpose' => 'delete', 'email' => $actor['email'], 'sent' => $sent, 'offerTrust' => false]);
        });
    }
    public function deleteConfirm(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $actor = CommerceAccess::requireActor();
            $pending = $_SESSION['pending_otp'] ?? null;
            if (!is_array($pending) || ($pending['purpose'] ?? '') !== 'delete' || (int) ($pending['user_id'] ?? 0) !== (int) $actor['id']) {
                throw new RequestRejected(400, 'otp_context_missing', 'Your confirmation session expired. Please start again.');
            }
            if (($_POST['confirm'] ?? '') !== 'Delete') {
                throw new RequestRejected(422, 'confirm_phrase', 'Type Delete exactly to confirm.');
            }
            $code = CommerceValues::text($_POST['code'] ?? '', 6, 'code');
            if (!CustomerOtp::verify((int) $actor['id'], 'delete', $code)) {
                throw new RequestRejected(422, 'otp_invalid', 'That code is invalid or expired. Request a new one.');
            }
            (new AccountDeletionService($this->pdo))->delete((int) $actor['id']);
            CustomerOtp::revokeAll((int) $actor['id']);
            CustomerOtp::clearDeviceCookie();
            Auth::logout();
            unset($_SESSION['checkout_keys'], $_SESSION['cart_merge_excluded'], $_SESSION['pending_buy_now'], $_SESSION['pending_otp']);
            $this->respond('app/page/storefront/message.php',
                ['title' => 'Account deleted', 'message' => 'Your account has been closed and you have been signed out. Past orders are retained for billing records.']);
        });
    }
    public function address(): void
    {
        $api = str_contains($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest');
        $this->handle(function () use ($api): void {
            RequestSecurity::requireMutation(); $actor = CommerceAccess::requireActor();
            // The shared service checks owner and expected revision for edits.
            $input = array_intersect_key($_POST, array_flip(['id', 'label', 'recipient', 'phone', 'line1', 'line2', 'city', 'state', 'postal_code', 'country_code', 'is_default']));
            $address = (new AddressService($this->pdo))->save($actor['id'], $input, CommerceValues::integer($_POST['revision'] ?? 0, 0, PHP_INT_MAX, 'revision'));
            if ($api) { echo json_encode(['ok'=>true,'address'=>self::jsonValues($address),'request_id'=>bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR); return; }
            $this->redirect(isset($input['id']) ? 'account' : 'checkout');
        }, $api);
    }
    public function archiveAddress(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation(); $actor = CommerceAccess::requireActor();
            (new AddressService($this->pdo))->archive($actor['id'], CommerceValues::id($_POST['id'] ?? null),
                CommerceValues::integer($_POST['revision'] ?? null, 1, PHP_INT_MAX, 'revision'));
            $this->redirect('account');
        });
    }
    public function orders(): void
    {
        $this->handle(function (): void {
            $actor = CommerceAccess::requireActor();
            $pageNumber = CommerceValues::integer($_GET['page'] ?? 1, 1, 1000, 'page');
            $orders = (new OrderService($this->pdo))->listing($actor['id'], $pageNumber);
            $this->respond('app/page/storefront/orders.php', compact('orders','pageNumber'));
        });
    }
    public function registration(): void
    {
        $this->handle(fn() => $this->respond('app/page/storefront/register.php', ['available'=>Auth::registrationAvailable()]));
    }
    public function register(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $first = CommerceValues::text($_POST['firstname'] ?? '', 100, 'firstname');
            $last = CommerceValues::text($_POST['lastname'] ?? '', 100, 'lastname');
            $password = $_POST['password'] ?? null;
            if (!is_string($password)) CommerceValues::invalid('password');
            $email = CommerceValues::text($_POST['email'] ?? '', 190, 'email');
            $result = Auth::register($first, $last, $first.' '.$last, $email, $password);
            if (!$result['status']) throw new RequestRejected(($result['code'] ?? '') === 'unavailable' ? 503 : 422, 'registration_failed', $result['message']);
            // OTP mode: issue a 6-digit code and show the entry page. Link/queue modes keep their email.
            if (CustomerOtp::enabled() && env('AUTH_VERIFICATION_TRANSPORT', 'disabled') === 'otp') {
                $user = Auth::userByEmail($email);
                if ($user) {
                    $_SESSION['pending_otp'] = ['user_id' => (int) $user['id'], 'purpose' => 'register', 'email' => $user['email']];
                    $sent = CustomerOtp::request((int) $user['id'], 'register', $user['email']);
                    $this->respond('app/page/storefront/otp_verify.php', ['purpose' => 'register', 'email' => $user['email'], 'sent' => $sent, 'offerTrust' => false]);
                    return;
                }
            }
            $this->respond('app/page/storefront/message.php', ['title'=>'Check your email','message'=>$result['message']]);
        });
    }
    public function verify(): void
    {
        $this->handle(function (): void {
            $result = Auth::verifyEmail(null, $_GET['token'] ?? null);
            if (!$result['status']) throw new RequestRejected(422, 'verification_failed', $result['message']);
            $this->respond('app/page/storefront/message.php', ['title'=>'Email verified','message'=>'Your account is verified. You can now sign in.']);
        });
    }
    public function forgot(): void
    {
        $this->handle(function (): void {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                $this->respond('app/page/storefront/forgot_password.php', ['available' => CustomerOtp::enabled()]);
                return;
            }
            RequestSecurity::requireMutation();
            if (!CustomerOtp::enabled()) throw new RequestRejected(503, 'unavailable', 'Password reset is currently unavailable.');
            $email = CommerceValues::text($_POST['email'] ?? '', 190, 'email');
            $user = Auth::userByEmail($email);
            // Always generic: never reveal whether an account exists.
            if ($user && (int) $user['status'] === 1) CustomerOtp::request((int) $user['id'], 'reset', $user['email']);
            $this->respond('app/page/storefront/reset_password.php', ['email' => $email]);
        });
    }
    public function reset(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            if (!CustomerOtp::enabled()) throw new RequestRejected(503, 'unavailable', 'Password reset is currently unavailable.');
            $email = CommerceValues::text($_POST['email'] ?? '', 190, 'email');
            $code = CommerceValues::text($_POST['code'] ?? '', 6, 'code');
            $password = $_POST['password'] ?? null;
            if (!is_string($password)) CommerceValues::invalid('password');
            $user = Auth::userByEmail($email);
            if (!$user || !CustomerOtp::verify((int) $user['id'], 'reset', $code)) {
                throw new RequestRejected(422, 'reset_failed', 'That code is invalid or expired. Request a new one.');
            }
            if (!Auth::setPassword((int) $user['id'], $password)) throw new RequestRejected(422, 'invalid_input', 'Use a password of 12 to 72 bytes.');
            $this->respond('app/page/storefront/message.php', ['title' => 'Password updated', 'message' => 'Your password was reset. You can now sign in.']);
        });
    }
    public function setPin(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation(); $actor = CommerceAccess::requireActor();
            $pin = $_POST['pin'] ?? '';
            if (!is_string($pin) || !CustomerOtp::setPin($actor['id'], $pin)) {
                throw new RequestRejected(422, 'invalid_pin', 'A PIN of 4 to 6 digits on a trusted device is required.');
            }
            $this->redirect('account');
        });
    }
    public function pinLogin(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            RequestSecurity::session();
            $pin = $_POST['pin'] ?? '';
            $userId = is_string($pin) ? CustomerOtp::verifyPin($pin) : null;
            if (!$userId) throw new RequestRejected(401, 'pin_failed', 'Incorrect PIN or this device is not trusted.');
            $this->completeLogin($userId);
        });
    }
    public function revokeDevices(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation(); $actor = CommerceAccess::requireActor();
            CustomerOtp::revokeAll($actor['id']);
            CustomerOtp::clearDeviceCookie();
            $this->redirect('account');
        });
    }
}
