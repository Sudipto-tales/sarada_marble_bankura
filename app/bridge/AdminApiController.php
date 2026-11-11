<?php

/**
 * Versioned staff JSON API (WEB-03). Each endpoint reloads the actor's grants from the database and
 * requires the exact permission its HTML counterpart uses, plus the session CSRF token for mutations.
 * The same ProductService/InventoryService/OrderService validation, audit and operation-key replay
 * rules apply; these endpoints never HTTP-call the admin console.
 */
class AdminApiController extends StorefrontController
{
    private const BODY_LIMIT = 32768;

    /** GET /api/admin/products — staff catalog listing (includes drafts/archived), with pagination meta. */
    public function products(): void
    {
        $this->handle(function (): void {
            $actor = CommerceAccess::requirePermission('admin.products.view');
            $result = (new ProductService($this->pdo))->administration($actor['id'], $_GET);
            $items = $result['items']; unset($result['items']);
            $this->json(['data' => self::jsonValues($items), 'meta' => $result, 'csrf_token' => RequestSecurity::csrfToken()], 200);
        }, true);
    }

    /** POST /api/admin/products — create/update a product; body is the ProductService::save input. */
    public function productSave(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $actor = CommerceAccess::requirePermission('admin.products.edit');
            $body = RequestSecurity::jsonBody(self::BODY_LIMIT);
            $result = (new ProductService($this->pdo))->save($actor['id'], $body,
                CommerceValues::integer($body['revision'] ?? null, 0, PHP_INT_MAX, 'revision'),
                CommerceValues::key($body['operation_key'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')));
            $this->json(['data' => self::jsonValues($result)]);
        }, true);
    }

    /** POST /api/admin/inventory — one audited stock adjustment. Body: {variant_id,delta_milli,reason,operation_key,revision?}. */
    public function inventoryAdjust(): void
    {
        $this->handle(function (): void {
            RequestSecurity::requireMutation();
            $actor = CommerceAccess::requirePermission('admin.inventory.adjust');
            $body = RequestSecurity::jsonBody(self::BODY_LIMIT);
            $stock = (new InventoryService($this->pdo))->adjust($actor['id'],
                CommerceValues::id($body['variant_id'] ?? null),
                CommerceValues::integer($body['delta_milli'] ?? null, -CommerceValues::MAX_QUANTITY, CommerceValues::MAX_QUANTITY, 'delta_milli'),
                CommerceValues::text($body['reason'] ?? '', 190, 'reason'),
                CommerceValues::key($body['operation_key'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')),
                isset($body['revision']) ? CommerceValues::integer($body['revision'], 1, PHP_INT_MAX, 'revision') : null);
            $this->json(['data' => self::jsonValues($stock)]);
        }, true);
    }

    /** POST /api/admin/orders/{id}/status — a validated order status transition. Body: {target,revision,operation_key,reason?}. */
    public function orderStatus(int $id): void
    {
        $this->handle(function () use ($id): void {
            RequestSecurity::requireMutation();
            $actor = CommerceAccess::requirePermission('admin.orders.edit');
            $body = RequestSecurity::jsonBody(self::BODY_LIMIT);
            $result = (new OrderService($this->pdo))->transition($actor['id'], $id,
                CommerceValues::text($body['target'] ?? '', 32, 'target'),
                CommerceValues::integer($body['revision'] ?? null, 1, PHP_INT_MAX, 'revision'),
                CommerceValues::key($body['operation_key'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')),
                CommerceValues::text($body['reason'] ?? 'Order status updated', 190, 'reason'));
            $this->json(['data' => self::jsonValues($result)]);
        }, true);
    }
}
