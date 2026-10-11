<?php

/** Minimal verified-account entry required by checkout; profile/signup expansion is PAR-03. */
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
            // This initial form creates addresses; edits/archive arrive with PAR-03.
            $input = array_intersect_key($_POST, array_flip(['label', 'recipient', 'phone', 'line1', 'line2', 'city', 'state', 'postal_code', 'country_code', 'is_default']));
            (new AddressService($this->pdo))->save($actor['id'], $input);
            $this->redirect('checkout');
        });
    }
}
