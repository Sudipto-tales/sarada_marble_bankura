<?php

/** Customer presentation delegates identity and address validation to shared services. */
class CustomerAccount extends StorefrontController
{
    public function index(): void
    {
        $this->handle(function (): void {
            $actor = CommerceAccess::actor();
            $addresses = $actor ? (new AddressService($this->pdo))->listing($actor['id']) : [];
            $this->respond('app/page/storefront/account.php', compact('actor', 'addresses'));
        });
    }
    public function login(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $email = CommerceValues::text($_POST['email'] ?? '', 190, 'email');
            $password = $_POST['password'] ?? '';
            if (!is_string($password)) CommerceValues::invalid('password');
            $guest = CartService::guestPrincipal();
            $result = Auth::login($email, $password);
            if (!$result['status']) throw new RequestRejected(($result['code'] ?? '') === 'throttled' ? 429 : 401, 'login_failed', $result['message']);
            $actor = CommerceAccess::requireActor();
            if ($guest !== null) {
                $merge = (new CartService($this->pdo))->merge($guest, $actor['id']);
                CartService::clearGuestCookie();
                $_SESSION['cart_merge_excluded'] = count($merge['excluded']);
            }
            $this->redirect('cart');
        });
    }
    public function logout(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation(); Auth::logout();
            unset($_SESSION['checkout_keys'], $_SESSION['cart_merge_excluded']);
            $this->redirect('account');
        });
    }
    public function address(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation(); $actor = CommerceAccess::requireActor();
            // The shared service checks owner and expected revision for edits.
            $input = array_intersect_key($_POST, array_flip(['id', 'label', 'recipient', 'phone', 'line1', 'line2', 'city', 'state', 'postal_code', 'country_code', 'is_default']));
            (new AddressService($this->pdo))->save($actor['id'], $input, CommerceValues::integer($_POST['revision'] ?? 0, 0, PHP_INT_MAX, 'revision'));
            $this->redirect(isset($input['id']) ? 'account' : 'checkout');
        });
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
            $result = Auth::register($first, $last, $first.' '.$last, CommerceValues::text($_POST['email'] ?? '', 190, 'email'), $password);
            if (!$result['status']) throw new RequestRejected(($result['code'] ?? '') === 'unavailable' ? 503 : 422, 'registration_failed', $result['message']);
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
}
