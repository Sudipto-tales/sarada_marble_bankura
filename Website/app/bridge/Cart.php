<?php

class Cart extends StorefrontController
{
    public function index(): void
    {
        $this->handle(function (): void {
            $cart = (new CartService($this->pdo))->get(CartService::principal(true));
            $cartProducts = array_column((new ProductService($this->pdo))->selection(array_values(array_unique(array_column($cart['items'], 'product_id')))), null, 'id');
            $this->respond('app/page/storefront/cart.php', compact('cart', 'cartProducts'));
        });
    }
    public function change(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $action = $_POST['action'] ?? 'update';
            if (!in_array($action, ['update', 'remove'], true)) CommerceValues::invalid('action');
            $quantity = $action === 'remove' ? null : CommerceValues::decimal($_POST['quantity'] ?? '', 3, CommerceValues::MAX_QUANTITY, 'quantity');
            (new CartService($this->pdo))->change(CartService::principal(true), CommerceValues::id($_POST['variant_id'] ?? null), $quantity,
                CommerceValues::integer($_POST['cart_revision'] ?? null, 0, PHP_INT_MAX, 'revision'),
                ($_POST['cart_id'] ?? '') === '' ? null : CommerceValues::id($_POST['cart_id']), $action === 'remove');
            $this->redirect('cart');
        });
    }
    public function buyNow(): void
    {
        $this->handle(function():void {
            RequestSecurity::requireMutation();
            $input=['variant_id'=>CommerceValues::id($_POST['variant_id']??null),'quantity'=>CommerceValues::decimal($_POST['quantity']??'',3,CommerceValues::MAX_QUANTITY,'quantity'),'key'=>CommerceValues::key($_POST['purchase_key']??null)];
            $actor=CommerceAccess::actor();
            if(!$actor){$_SESSION['pending_buy_now']=$input;$this->redirect('account');return;}
            $cart=(new CartService($this->pdo))->buyNow($actor['id'],$input['variant_id'],$input['quantity'],$input['key']);
            $this->redirect('checkout?purchase_cart='.$cart['id']);
        });
    }
    public function addPair(): void
    {
        $this->handle(function():void {
            RequestSecurity::requireMutation();$source=CommerceValues::id($_POST['product_id']??null);
            $products=new ProductService($this->pdo);$a=$products->selection([$source])[0]??null;
            $details=(new ProductDetailService($this->pdo))->get($source);$b=$details['companion_product_id']?($products->selection([$details['companion_product_id']])[0]??null):null;
            if(!$a||!$b)throw new RequestRejected(409,'pair_unavailable','This pairing is no longer available.');
            $raw=$_POST['lines']??null;if(!is_array($raw)||count($raw)!==2)CommerceValues::invalid('lines');$lines=[];
            foreach(array_values($raw) as $i=>$line){
                if(!is_array($line))CommerceValues::invalid('lines');$id=CommerceValues::id($line['variant_id']??null);
                if(!in_array($id,array_map('intval',array_column(($i===0?$a:$b)['variants'],'id')),true))CommerceValues::invalid('variant_id');
                $lines[]=['variant_id'=>$id,'qty_milli'=>CommerceValues::decimal($line['quantity']??'',3,CommerceValues::MAX_QUANTITY,'quantity')];
            }
            (new CartService($this->pdo))->changeMany(CartService::principal(true),$lines,CommerceValues::integer($_POST['cart_revision']??null,0,PHP_INT_MAX,'revision'),($_POST['cart_id']??'')===''?null:CommerceValues::id($_POST['cart_id']));
            $this->redirect('cart');
        });
    }
    private static function selection(array $input): array
    {
        return ['cart_id' => CommerceValues::id($input['cart_id'] ?? null),
            'cart_revision' => CommerceValues::integer($input['cart_revision'] ?? null, 1, PHP_INT_MAX, 'revision'),
            'address_id' => CommerceValues::id($input['address_id'] ?? null),
            'payment_mode' => CommerceValues::text($input['payment_mode'] ?? '', 20, 'payment_mode')];
    }
    public function checkout(): void
    {
        $this->handle(function (): void {
            $actor = CommerceAccess::actor();
            if (!$actor) { $this->redirect('account'); return; }
            $purchaseCart = isset($_GET['purchase_cart']) ? CommerceValues::id($_GET['purchase_cart']) : null;
            $cartService = new CartService($this->pdo);
            $cart = $purchaseCart === null ? $cartService->get('user:' . $actor['id']) : $cartService->purchaseCart($actor['id'], $purchaseCart);
            if (!$cart['items']) { $this->redirect('cart'); return; }
            $addresses = (new AddressService($this->pdo))->listing($actor['id']);
            $modes = OrderStates::paymentModes();
            $selection = null; $key = null; $quote = null;
            if (isset($_GET['address_id'], $_GET['payment_mode'])) {
                $selection = self::selection(['cart_id' => $cart['id'], 'cart_revision' => $cart['revision'],
                    'address_id' => $_GET['address_id'], 'payment_mode' => $_GET['payment_mode']]);
                (new AddressService($this->pdo))->get($actor['id'], $selection['address_id']);
                OrderStates::payment($selection['payment_mode']);
                $quote = OrderService::quote($cart);
                $fingerprint = hash('sha256', json_encode([$actor['id'], $selection, $quote], JSON_THROW_ON_ERROR));
                RequestSecurity::session(); $_SESSION['checkout_keys'] ??= [];
                if (!isset($_SESSION['checkout_keys'][$fingerprint])) {
                    if (count($_SESSION['checkout_keys']) >= 20) array_shift($_SESSION['checkout_keys']);
                    $_SESSION['checkout_keys'][$fingerprint] = ['key' => bin2hex(random_bytes(32)), 'actor' => $actor['id'], 'selection' => $selection, 'quote' => $quote];
                }
                $key = $_SESSION['checkout_keys'][$fingerprint]['key'];
            }
            $this->respond('app/page/storefront/checkout.php', compact('cart', 'addresses', 'modes', 'selection', 'key', 'quote', 'purchaseCart'));
        });
    }
    public function placeOrder(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation(); $actor = CommerceAccess::requireActor();
            $selection = self::selection($_POST); $key = CommerceValues::key($_POST['checkout_key'] ?? '');
            $review = null;
            foreach ($_SESSION['checkout_keys'] ?? [] as $candidate) {
                if (hash_equals($candidate['key'], $key) && $candidate['actor'] === $actor['id'] && $candidate['selection'] === $selection) { $review = $candidate; break; }
            }
            if ($review === null) throw new RequestRejected(409, 'checkout_selection_changed', 'Review the current checkout selection before submitting.');
            $result = (new OrderService($this->pdo))->place($actor['id'], $selection['cart_id'],
                $selection['cart_revision'], $selection['address_id'], $selection['payment_mode'], $key, $review['quote']);
            $this->redirect('order/' . $result['id']);
        });
    }
    public function order(int $id): void
    {
        $this->handle(function () use ($id): void {
            $actor = CommerceAccess::requireActor();
            $this->respond('app/page/storefront/order.php', ['order' => (new OrderService($this->pdo))->owned($actor['id'], $id)]);
        });
    }
}
