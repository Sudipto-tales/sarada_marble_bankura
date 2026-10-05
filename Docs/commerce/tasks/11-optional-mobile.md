# Optional features, imports, payments and Flutter adapters

Scope: deferred OPT-01/02/03/04 and MOB-01. Do not start them just because this guide exists. Check explicit milestone authorization and prerequisites in the tracker. Read [contracts](../contracts.md) and [data flow](../data-flow.md).

## OPT-01

Sources: [mobile reviews interface](../../../Mobile/lib/data/repositories/repositories.dart#L39), [promotions interface](../../../Mobile/lib/data/repositories/repositories.dart#L78), [wishlist controller](../../../Mobile/lib/core/state/wishlist_controller.dart#L1). These are offline patterns, not backend grants or coupon policy.

Wishlist: unique user/product association, owned CRUD, archived products hidden. Reviews: bounded rating/text, verified-purchase rule configured, moderation state, one allowed review per defined purchase/user/product policy, no browser-trusted rating count. Approved review changes rebuild trusted aggregates/cache versions.

Coupon algorithm: normalize code → lock campaign/coupon and owned redemption key in checkout lock order → validate interval/minimum/items/usage cap → apply integer discount with floor → atomically increment/record redemption with order commit. Retries do not consume extra redemption; cancellations follow a documented restore-or-not policy. Two users racing for the last redemption cannot both succeed. Freeze discount snapshots; API never trusts mobile coupon totals.

## OPT-02

Sources: [migration pattern](../../../Website/database/migrations/UsersTable.php#L4), [prepared DB helper](../../../Website/config/db.php#L36). Existing import module is absent. Build CSV first; XLSX requires an explicitly chosen compatible parser/license/resource policy.

Algorithm: authorized staff/private upload → generated staged file ID/checksum/schema version → dry-run parse in bounded batches → strict header/types/units/SKU/image-reference validation → report line-level redacted errors → explicit accepted import → per-batch ProductService upsert using stable SKU + import row key → write progress/checkpoint with batch commit → bounded queue continuation → final summary. No raw spreadsheet in job payload; no whole-file transaction or customer-request import.

Reject duplicate/conflicting SKU rows before applying them, unbounded files/rows, invalid units and arbitrary remote image URLs. Retrying a committed batch is idempotent; one failed row follows explicit all-batch reject/quarantine policy. Include revision conflict behavior for staff edits during import. Tests: parse error, duplicate SKU, crash/continuation, conflicting edit, oversized file, partial batch rollback, dry-run writes nothing.

## OPT-03

Sources: [env helper](../../../Website/config/env.php#L3), [HTTP helper](../../../Website/core/Helpers.php#L18). Existing helper lacks explicit integration deadlines; don't reuse it unchanged for unattended bulk downloads.

Flow: operator-authorized account/folder → secret credentials outside source/public path → manual CSRF/grant trigger or configured cron → bounded allowlisted download to private staging → checksum/schema validation → existing OPT-02 importer → sync log/checkpoint → result. Drive only supplies import input; MySQL remains storefront authority.

Keep provider file IDs/version keys for replay, bounded pagination/download size/time and token refresh rules. Never download an arbitrary user-supplied URL or query Drive during a product request. Provider scopes, documentation and connection must be verified when this deferred task starts; no account permissions are assumed now.

## OPT-04

References: [mobile payment methods](../../../Mobile/lib/data/models/order.dart#L64), [current Mailer](../../../Website/core/Mailer.php#L9). Mobile simulated payment is not a provider implementation.

Choose/configure provider and read current official integration docs at implementation time. Create payment intents against immutable server orders outside SQL locks, with durable local/provider idempotency keys. Verified signed webhook → deduplicate provider event → lock payment/order → validate amount/currency/allowed state → commit payment/history and required jobs. Browser success redirects only refresh verified state. Handle out-of-order/duplicate webhooks, unknown outcomes, refunds, reconciliation and cancellation/stock reservation policy before enabling live checkout.

WhatsApp uses separately authorized provider/template/settings and queued delivery records, with the same bounded retry/unknown-result policy as email. Never claim notification delivery based only on enqueuing a job.

## MOB-01

Open: [repository interfaces](../../../Mobile/lib/data/repositories/repositories.dart#L18), [AppDependencies.static](../../../Mobile/lib/core/state/app_scope.dart#L35), [main](../../../Mobile/lib/main.dart#L8), [CartController](../../../Mobile/lib/core/state/cart_controller.dart#L11), [StaticOrderRepository](../../../Mobile/lib/data/repositories/static_repositories.dart#L180), [Product](../../../Mobile/lib/data/models/product.dart#L3), [Order](../../../Mobile/lib/data/models/order.dart#L76).

Networking is more than swapping one constructor: local cart uses product ID and floating area; server cart needs variant ID/units/revision. `OrderRepository.place(Order)` currently accepts client totals; adapter must create a server checkout request and return the server result, or evolve the interface with an explicit command. Do not trust the comment that composition changes alone are sufficient.

Algorithm: opt-in feature flag → native token lifecycle with secure storage/refresh/revocation → Api repositories implementing agreed contracts → server ID/unit/money/status mapping → bounded read retries and stable checkout-key retries → controller state/error handling. Keep Room/visualization/calculator/local brand catalogs offline unless separately requested. A public catalog cache can survive logout; private addresses/orders/tokens cannot leak across accounts.

Preserve offline default and separate demo/remote local namespaces. Never upload seeded demo orders, local passwords or offline simulated purchases. Verify model unknown-state handling, network timeout after committed order, account switch, expired token and multi-variant cart behavior. Run appropriate Flutter analyze/tests and build checks only when mobile changes exist; no mobile code changes are part of this documentation update.
