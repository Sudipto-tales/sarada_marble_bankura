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
            $twoStep = $actor ? $this->twoStepState((int) $actor['id']) : null;
            $channels = OtpChannels::available($this->pdo);
            $profile = $actor
                ? (CommerceDatabase::query($this->pdo, 'SELECT firstname, lastname, email, otp_phone FROM users_tbl WHERE id = ?', [$actor['id']])->fetch(PDO::FETCH_ASSOC) ?: [])
                : [];
            $notice = $_SESSION['account_notice'] ?? null;
            unset($_SESSION['account_notice']);
            $this->respond('app/page/storefront/account.php', compact('actor', 'addresses', 'devices', 'deviceTrusted', 'pinSet', 'twoStep', 'channels', 'profile', 'notice'));
        });
    }
    /** The sign-in page: email/password plus the quick PIN form when this device is trusted. */
    public function loginPage(): void
    {
        $this->handle(function (): void {
            if (CommerceAccess::actor()) { $this->redirect('account'); return; }
            $this->respond('app/page/storefront/login.php', ['pinLogin' => CustomerOtp::currentDevicePinAvailable()]);
        });
    }
    private function twoStepState(int $userId): array
    {
        $row = CommerceDatabase::query($this->pdo, 'SELECT otp_enabled, otp_channel, otp_phone FROM users_tbl WHERE id = ?', [$userId])->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['enabled' => (int) ($row['otp_enabled'] ?? 0) === 1, 'channel' => (string) ($row['otp_channel'] ?? 'email'), 'phone' => (string) ($row['otp_phone'] ?? '')];
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
            // A one-time code runs when the store forces it or the user turned on two-step, unless this
            // device is already trusted. The code goes to the user's channel (email unless they chose otherwise).
            $twoStep = CustomerOtp::loginRequired() || (int) ($user['otp_enabled'] ?? 0) === 1;
            if ($twoStep && !CustomerOtp::trusted((int) $user['id'])) {
                $available = OtpChannels::available($this->pdo);
                $channel = (string) ($user['otp_channel'] ?? 'email');
                if (empty($available[$channel])) $channel = 'email';
                $phone = (string) ($user['otp_phone'] ?? '');
                $_SESSION['pending_otp'] = ['user_id' => (int) $user['id'], 'purpose' => 'login', 'email' => $user['email'], 'channel' => $channel, 'phone' => $phone];
                $sent = CustomerOtp::request((int) $user['id'], 'login', $user['email'], $channel, $phone);
                $this->respond('app/page/storefront/otp_verify.php', ['purpose' => 'login', 'email' => $channel === 'email' ? $user['email'] : $phone, 'sent' => $sent, 'offerTrust' => true, 'channel' => $channel]);
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
            if ($purpose === 'register') {
                unset($_SESSION['pending_otp']);
                Auth::markVerified($userId);
                $this->respond('app/page/storefront/message.php', ['title' => 'Email verified', 'message' => 'Your account is verified. You can now sign in.']);
                return;
            }
            if ($purpose === 'twostep') {
                CommerceAccess::requireActor(); // only the signed-in owner enables their own second factor
                $channel = (string) ($pending['channel'] ?? 'email');
                // Email codes need no number, and the stored one is the account's profile phone, so leave it.
                Auth::setTwoFactor($userId, true, $channel, $channel === 'email' ? null : (string) ($pending['phone'] ?? ''));
                unset($_SESSION['pending_otp']);
                $this->respond('app/page/storefront/message.php', ['title' => 'Two-step verification on',
                    'message' => 'Two-step verification is enabled. We will send a code to your ' . ($channel === 'email' ? 'email' : $channel) . ' each time you sign in on a new device.']);
                return;
            }
            unset($_SESSION['pending_otp']);
            if (($_POST['trust_device'] ?? '') === '1') CustomerOtp::trustCurrentDevice($userId);
            $this->completeLogin($userId);
        });
    }
    public function twoStep(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $actor = CommerceAccess::requireActor();
            if (($_POST['enable'] ?? '') !== '1') { Auth::setTwoFactor((int) $actor['id'], false); $this->redirect('account'); return; }
            $channel = CommerceValues::text($_POST['channel'] ?? 'email', 16, 'channel');
            if (empty(OtpChannels::available($this->pdo)[$channel])) throw new RequestRejected(422, 'channel_unavailable', 'That verification channel is not available.');
            $phone = '';
            if ($channel !== 'email') {
                $phone = preg_replace('/\s+/', '', CommerceValues::text($_POST['phone'] ?? '', 32, 'phone'));
                if (!preg_match('/\A\+?[0-9]{8,15}\z/', $phone)) throw new RequestRejected(422, 'invalid_phone', 'Enter a valid phone number with country code.');
            }
            $email = (string) $actor['email'];
            $_SESSION['pending_otp'] = ['user_id' => (int) $actor['id'], 'purpose' => 'twostep', 'email' => $email, 'channel' => $channel, 'phone' => $phone];
            $sent = CustomerOtp::request((int) $actor['id'], 'twostep', $email, $channel, $phone);
            $this->respond('app/page/storefront/otp_verify.php', ['purpose' => 'twostep', 'email' => $channel === 'email' ? $email : $phone, 'sent' => $sent, 'offerTrust' => false, 'channel' => $channel]);
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
            $channel = (string) ($pending['channel'] ?? 'email');
            $sent = CustomerOtp::request((int) $pending['user_id'], (string) $pending['purpose'], (string) $pending['email'], $channel, (string) ($pending['phone'] ?? ''));
            $destination = $channel === 'email' ? (string) $pending['email'] : (string) ($pending['phone'] ?? '');
            $this->respond('app/page/storefront/otp_verify.php', ['purpose' => $pending['purpose'], 'email' => $destination, 'sent' => $sent, 'offerTrust' => $pending['purpose'] === 'login', 'channel' => $channel]);
        });
    }
    public function logout(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation(); Auth::logout();
            unset($_SESSION['checkout_keys'], $_SESSION['cart_merge_excluded'], $_SESSION['pending_buy_now'], $_SESSION['pending_otp'], $_SESSION['pending_profile']);
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
            unset($_SESSION['checkout_keys'], $_SESSION['cart_merge_excluded'], $_SESSION['pending_buy_now'], $_SESSION['pending_otp'], $_SESSION['pending_profile']);
            $this->respond('app/page/storefront/message.php',
                ['title' => 'Account deleted', 'message' => 'Your account has been closed and you have been signed out. Past orders are retained for billing records.']);
        });
    }
    /**
     * Manage profile: the name saves straight away, but a changed email or phone is held in the
     * session until a one-time code sent to that new destination is entered.
     */
    public function updateProfile(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $actor = CommerceAccess::requireActor();
            $current = CommerceDatabase::query($this->pdo, 'SELECT firstname, lastname, email, otp_phone FROM users_tbl WHERE id = ?', [$actor['id']])->fetch(PDO::FETCH_ASSOC);
            if (!$current) throw new RequestRejected(404, 'not_found', 'Account not found.');
            $profile = Auth::normaliseProfile(
                CommerceValues::text($_POST['firstname'] ?? '', 100, 'firstname'),
                CommerceValues::text($_POST['lastname'] ?? '', 100, 'lastname', true),
                CommerceValues::text($_POST['email'] ?? '', 190, 'email'),
                CommerceValues::text($_POST['phone'] ?? '', 32, 'phone', true));
            if (isset($profile['error'])) throw new RequestRejected(422, 'invalid_profile', $profile['error']);
            if (Auth::emailTaken($profile['email'], (int) $actor['id'])) {
                throw new RequestRejected(409, 'email_taken', 'That email address is already registered to another account.');
            }
            $emailChanged = strcasecmp($profile['email'], (string) $current['email']) !== 0;
            $phoneChanged = $profile['phone'] !== (string) ($current['otp_phone'] ?? '');
            if (!$emailChanged && !$phoneChanged) {
                if (!Auth::saveProfile((int) $actor['id'], $profile, true)) {
                    throw new RequestRejected(409, 'email_taken', 'That email address is already registered to another account.');
                }
                $_SESSION['account_notice'] = 'Your name was updated.';
                $this->redirect('account');
                return;
            }
            if (!CustomerOtp::enabled()) throw new RequestRejected(503, 'unavailable', 'Contact changes need verification, which is currently unavailable.');
            // Send the code to whatever the shopper is changing to, so they prove they hold it. A new
            // phone can only receive one when WhatsApp/SMS is configured; otherwise the existing,
            // already-verified email carries the confirmation.
            [$channel, $destination] = $this->profileChannel($profile, $emailChanged, $phoneChanged, (string) $current['email']);
            $_SESSION['pending_profile'] = $profile + ['user_id' => (int) $actor['id'], 'email_changed' => $emailChanged];
            $_SESSION['pending_otp'] = ['user_id' => (int) $actor['id'], 'purpose' => 'profile',
                'email' => $channel === 'email' ? $destination : (string) $current['email'], 'channel' => $channel, 'phone' => $channel === 'email' ? '' : $destination];
            $sent = CustomerOtp::request((int) $actor['id'], 'profile', (string) $_SESSION['pending_otp']['email'], $channel, (string) $_SESSION['pending_otp']['phone']);
            $this->respond('app/page/storefront/otp_verify.php',
                ['purpose' => 'profile', 'email' => $destination, 'sent' => $sent, 'offerTrust' => false, 'channel' => $channel]);
        });
    }
    /** Where a profile confirmation code goes: the new phone when we can text it, else the new/old email. */
    private function profileChannel(array $profile, bool $emailChanged, bool $phoneChanged, string $currentEmail): array
    {
        if ($phoneChanged && $profile['phone'] !== '' && !$emailChanged) {
            $available = OtpChannels::available($this->pdo);
            foreach (['whatsapp', 'sms'] as $channel) if (!empty($available[$channel])) return [$channel, $profile['phone']];
        }
        return ['email', $emailChanged ? $profile['email'] : $currentEmail];
    }
    public function confirmProfile(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $actor = CommerceAccess::requireActor();
            $pending = $_SESSION['pending_profile'] ?? null;
            if (!is_array($pending) || (int) ($pending['user_id'] ?? 0) !== (int) $actor['id']) {
                throw new RequestRejected(400, 'otp_context_missing', 'Your verification session expired. Please start again.');
            }
            $code = CommerceValues::text($_POST['code'] ?? '', 6, 'code');
            if (!CustomerOtp::verify((int) $actor['id'], 'profile', $code)) {
                throw new RequestRejected(422, 'otp_invalid', 'That code is invalid or expired. Request a new one.');
            }
            // Re-check the address at write time: another signup may have claimed it while the code was out.
            if (Auth::emailTaken($pending['email'], (int) $actor['id'])
                || !Auth::saveProfile((int) $actor['id'], $pending, true)) {
                unset($_SESSION['pending_profile'], $_SESSION['pending_otp']);
                throw new RequestRejected(409, 'email_taken', 'That email address is already registered to another account.');
            }
            $changed = !empty($pending['email_changed']);
            unset($_SESSION['pending_profile'], $_SESSION['pending_otp']);
            $_SESSION['account_notice'] = $changed ? 'Your email address was updated and verified.' : 'Your contact details were updated.';
            $this->redirect('account');
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
            // First-time verification is an emailed 6-digit code whenever OTP is available.
            // Without OTP (no mail transport), registration falls back to the link/queue email below.
            if (CustomerOtp::enabled()) {
                $user = Auth::userByEmail($email);
                if ($user) {
                    $_SESSION['pending_otp'] = ['user_id' => (int) $user['id'], 'purpose' => 'register', 'email' => $user['email'], 'channel' => 'email', 'phone' => ''];
                    $sent = CustomerOtp::request((int) $user['id'], 'register', $user['email']);
                    $this->respond('app/page/storefront/otp_verify.php', ['purpose' => 'register', 'email' => $user['email'], 'sent' => $sent, 'offerTrust' => false, 'channel' => 'email']);
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
