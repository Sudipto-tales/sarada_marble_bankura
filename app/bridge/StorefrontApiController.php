<?php

/**
 * Versioned customer JSON API (WEB-03). These endpoints share the same services as the HTML
 * controllers and must produce identical effects/ownership — they never HTTP-call the storefront.
 * Cookie sessions carry identity; mutations require the session CSRF token (X-CSRF-Token header).
 * Cross-origin credentialed reads are blocked by the absence of any Access-Control-Allow-Origin,
 * so the token returned by the owned GET endpoints is the same-origin CSRF bootstrap.
 */
class StorefrontApiController extends StorefrontController
{
    private const BODY_LIMIT = 16384;

    private static function quantityMilli(array $body): int
    {
        $value = $body['quantity'] ?? '';
        // Quantities are decimal strings (e.g. "1.5"); accept an integer JSON number for convenience.
        if (is_int($value)) $value = (string) $value;
        return CommerceValues::decimal($value, 3, CommerceValues::MAX_QUANTITY, 'quantity');
    }

    /** GET /api/v1/cart — the caller's owned guest/user cart and server-recomputed line totals. */
    public function cart(): void
    {
        $this->handle(function (): void {
            $principal = CartService::principal(false);
            $cart = $principal === null
                ? ['id' => null, 'revision' => 0, 'items' => [], 'subtotal_minor' => 0, 'savings_minor' => 0, 'currency' => 'INR']
                : (new CartService($this->pdo))->get($principal);
            $this->json(['data' => self::jsonValues($cart), 'csrf_token' => RequestSecurity::csrfToken()]);
        }, true);
    }

    /** POST /api/v1/cart — add/update/remove a line. Body: {action,variant_id,quantity,cart_revision?,cart_id?}. */
    public function cartChange(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $body = RequestSecurity::jsonBody(self::BODY_LIMIT);
            $action = $body['action'] ?? 'update';
            if (!in_array($action, ['add', 'update', 'remove'], true)) CommerceValues::invalid('action');
            $principal = CartService::principal(true);
            $variantId = CommerceValues::id($body['variant_id'] ?? null);
            $carts = new CartService($this->pdo);
            if ($action === 'add') {
                $cart = $carts->add($principal, $variantId, self::quantityMilli($body));
            } else {
                $cart = $carts->change($principal, $variantId, $action === 'remove' ? null : self::quantityMilli($body),
                    CommerceValues::integer($body['cart_revision'] ?? null, 0, PHP_INT_MAX, 'revision'),
                    isset($body['cart_id']) && $body['cart_id'] !== '' ? CommerceValues::id($body['cart_id']) : null,
                    $action === 'remove');
            }
            $this->json(['data' => self::jsonValues($cart), 'csrf_token' => RequestSecurity::csrfToken()]);
        }, true);
    }

    /** GET /api/v1/addresses — the current user's owned delivery addresses. */
    public function addresses(): void
    {
        $this->handle(function (): void {
            $actor = CommerceAccess::requireActor();
            $list = (new AddressService($this->pdo))->listing($actor['id']);
            $this->json(['data' => self::jsonValues($list), 'csrf_token' => RequestSecurity::csrfToken()]);
        }, true);
    }

    /** POST /api/v1/addresses — create (201) or, with "id", update (200) an owned address. */
    public function addressCreate(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $actor = CommerceAccess::requireActor();
            $body = RequestSecurity::jsonBody(self::BODY_LIMIT);
            $expected = isset($body['expected_revision'])
                ? CommerceValues::integer($body['expected_revision'], 0, PHP_INT_MAX, 'revision') : 0;
            $saved = (new AddressService($this->pdo))->save($actor['id'], $body, $expected);
            $this->json(['data' => self::jsonValues($saved)], isset($body['id']) ? 200 : 201);
        }, true);
    }

    /** GET /api/v1/orders — the current user's owned order summaries, 20 per page. */
    public function orders(): void
    {
        $this->handle(function (): void {
            $actor = CommerceAccess::requireActor();
            $page = isset($_GET['page']) ? CommerceValues::integer($_GET['page'], 1, 1000, 'page') : 1;
            $list = (new OrderService($this->pdo))->listing($actor['id'], $page);
            $this->json(['data' => self::jsonValues($list), 'meta' => ['page' => $page], 'csrf_token' => RequestSecurity::csrfToken()]);
        }, true);
    }

    /**
     * POST /api/v1/orders — place an order from an owned cart. Body:
     * {cart_id,cart_revision,address_id,payment_mode}; the Idempotency-Key header is the checkout key.
     * Prices/availability are read authoritatively from the database inside the placement transaction;
     * a completed key returns the saved result (same 201) rather than placing a second order.
     */
    public function orderCreate(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $actor = CommerceAccess::requireActor();
            $key = CommerceValues::key($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
            $body = RequestSecurity::jsonBody(self::BODY_LIMIT);
            $result = (new OrderService($this->pdo))->place($actor['id'],
                CommerceValues::id($body['cart_id'] ?? null),
                CommerceValues::integer($body['cart_revision'] ?? null, 1, PHP_INT_MAX, 'revision'),
                CommerceValues::id($body['address_id'] ?? null),
                CommerceValues::text($body['payment_mode'] ?? '', 20, 'payment_mode'), $key);
            $result['order_id'] = $result['id']; // documented canonical field; string-cast by jsonValues
            $this->json(['data' => self::jsonValues($result)], 201);
        }, true);
    }

    /**
     * POST /api/v1/events — record one public analytics event (product_view, search, add_to_cart).
     * The server derives the user (if any), a pseudonymous session key and the time; browser-supplied
     * user ids and purchase events are never accepted. Rate-limited per session.
     */
    public function events(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $body = RequestSecurity::jsonBody(self::BODY_LIMIT);
            $type = is_string($body['type'] ?? null) ? $body['type'] : '';
            $actor = CommerceAccess::actor();
            $sessionKey = hash('sha256', 'evt:' . session_id());
            $result = (new EventService($this->pdo))->ingest($type, $body, $actor ? $actor['id'] : null, $sessionKey);
            $this->json(['data' => $result], 202);
        }, true);
    }

    /** GET /api/v1/orders/{id} — an owned order's snapshot and status history; another owner's id is 404. */
    public function order(int $id): void
    {
        $this->handle(function () use ($id): void {
            $actor = CommerceAccess::requireActor();
            $order = (new OrderService($this->pdo))->owned($actor['id'], $id);
            $this->json(['data' => self::jsonValues($order)]);
        }, true);
    }
}
