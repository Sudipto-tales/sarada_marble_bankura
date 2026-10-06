# Service-backed admin and image uploads

Scope: ADM-01/02/03. Use [identity](02-identity.md), [catalog](03-catalog-carts-schema.md), [inventory/orders](04-inventory-orders.md) and [cache](06-cache.md). Admin is the write authority for catalog/configuration; HTML/API both use the same services.

## ADM-01

Existing references: [Developer controller guard pattern](../../../Website/app/bridge/Developer.php#L5), [Welcome rendering](../../../Website/app/bridge/Welcome.php#L6), [BaseController.respond](../../../Website/core/BaseController.php#L10), [load_view](../../../Website/config/bootstarp.php#L10), [route provider](../../../Website/app/view.php#L5).

Proposed `app/page/admin/layout.php`, corresponding page views, unique Admin controller names and local `assets/admin/` files. Renderer accepts full `app/page/admin/...php` paths, not Laravel dot notation or extensionless paths. Layout receives escaped staff display name, allowed navigation and rendered content through the existing PHP view approach.

Algorithm: authorize commerce actor → build grant-filtered navigation → load actual service data or honest empty state → render escaped layout/page. Mutations remain separate method routes. Verify local Bootstrap/template asset paths under root and subdirectory deployments. Record asset source/license; original Sneat-style markup must not be described as official Sneat assets.

Tests: customer/Developer-only identity denied; view-only grant sees permitted navigation but backend mutation denied; keyboard/mobile navigation works; empty DB has clear create action/error handling; no fabricated sales cards count as live data.

## ADM-02

Sources: [route/API maps](../../../Website/api/gateway.php#L5), [existing rendering](../../../Website/core/Helpers.php#L11), [PDO helper](../../../Website/config/db.php#L38). New controllers call ProductService; they do not duplicate price/variant SQL in form handlers.

Product workflow: GET authorized form → POST CSRF/grants/revision/input validation → service save → field/conflict errors or redirect to persisted product. For variants use explicit add/update/archive IDs; validate each ID belongs to the current product. Category/brand mutations use corresponding grants/audit and version policy. Preserve values on validation error without echoing unescaped strings.

Image algorithm:

1. Check grant/CSRF/upload status and configured byte limit before reading. Allow only supported raster MIME types; reject SVG and executable/ambiguous extensions.
2. Detect MIME server-side; validate image dimensions/pixel budget and decodability with available tools. Do not trust browser MIME/name. If safe validation tools are absent, disable upload until the environment meets the policy.
3. Generate random filename/approved extension. Stage privately; never interpolate original names or allow overwriting paths.
4. Move validated image to safe nonexecuting public image storage, then save metadata through ProductService transaction/audit/version. On DB failure remove unreferenced new file; crashed orphan is handled by bounded reconciliation.
5. Remove old image only after committed replacement and reference checks. Limit per-product image count and gallery order.

Vectors: traversal name, PHP disguised as JPEG, huge dimensions/small compressed file, invalid MIME, valid image then DB rollback, replacement crash, variant owned by another product, parallel stale form submissions, view-only user POST. Test HTTP execution denial in target upload directory.

## ADM-03

Existing [Developer metrics](../../../Website/app/bridge/Developer.php#L68) are not commerce order authority. Implement real InventoryService.adjust and OrderService.transition calls; do not directly update stock/order status with generic CRUD.

Adjustment input: variant ID, signed canonical delta, reason, expected revision and operation key. Status input: order ID, target, expected revision and operation key. Require exact grant, validate transition, write transactional audit and show preserved history. Staff customer lookup returns only required fields; never password/token columns. Settings permit only implemented validated configuration fields, not arbitrary environment/file writes.

Checks: duplicate adjustment/status POST changes stock once; reduction below reserved denied; cancelled order cannot ship; customer address/history display uses snapshots; insufficient grant and stale revision reject; audit exists once. CSV/Excel, coupon/Drive/payment screens remain disabled until their optional task is delivered.
