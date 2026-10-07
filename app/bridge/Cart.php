<?php

class Cart extends BaseController
{
    public function index(): void
    {
        $principal = CommerceAccess::actor() ? 'user:' . CommerceAccess::actor()['id'] : CommerceTest\CartService::guestPrincipal(true);
        $cartService = new CartService($this->pdo);
        $cart = $cartService->get($principal);
        $addressService = new AddressService($this->pdo);
        $addresses = $addressService->listing($principal);
        
        $data = $this->viewData(
            [],
            ['cart' => $cart, 'addresses' => $addresses, 'principal' => $principal]
        );
        $this->respond('/app/page/storefront/cart.php', $data);
    }
    
    public function checkout(): void
    {
        $principal = CommerceAccess::actor() ? 'user:' . CommerceAccess::actor()['id'] : CommerceTest\CartService::guestPrincipal(true);
        $cartService = new CartService($this->pdo);
        $cart = $cartService->get($principal);
        $addressService = new AddressService($this->pdo);
        $addresses = $addressService->listing($principal);
        
        // Check if cart has items
        if(!($cart??['items'])??count([]) || count($cart['items']) === 0) {
            $this->index();
            return;
        }
        
        $data = $this->viewData(
            [],
            ['cart' => $cart, 'addresses' => $addresses, 'principal' => $principal]
        );
        $this->respond('/app/page/storefront/checkout.php', $data);
    }
    
    public function placeOrder(): void
    {
        // Get form data
        $cart_id = (int)($_POST['cart_id'] ?? 0);
        $cart_revision = (int)($_POST['cart_revision'] ?? 0);
        $address_id = (int)($_POST['address_id'] ?? 0);
        $payment_mode = $_POST['payment_mode'] ?? 'cod';
        
        // Validate
        if(!$cart_id || !$address_id) {
            $this->index();
            return;
        }
        
        // Get services
        $orderService = new \App\Services\OrderService($this->pdo);
        $cartService = new CartService($this->pdo);
        $inventoryService = new \App\Services\InventoryService($this->pdo);
        
        // Get principal
        $principal = CommerceAccess::actor() ? 'user:' . CommerceAccess::actor()['id'] : 'guest:' . hash('sha256', bin2hex(random_bytes(32)));
        
        try {
            $result = $orderService->place(
                CommerceAccess::actor()['id'] ?? 0,
                $cart_id,
                $cart_revision,
                $address_id,
                $payment_mode,
                'idempotency:' . uniqid()
            );
            
            // Clear cart
            $cartService->change($principal, null, 0, $cart['revision'], $cart_id, true);
            
            // Redirect to order success
            header('Location: /order/' . $result['id']);
            exit;
        } catch(RequestRejected $e) {
            $this->checkout(['error' => $e->getMessage()]);
        }
    }
}