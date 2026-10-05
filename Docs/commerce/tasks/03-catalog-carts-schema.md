# Catalog, addresses and carts

Scope: DAT-01 and DAT-03. Use [value contracts](../contracts.md#values-and-boundaries), [inventory/order guide](04-inventory-orders.md), and existing references below. Do not generate generic CRUD tables unrelated to the actual stone catalog.

## DAT-01

Existing references: [UsersTable migration pattern](../../../Website/database/migrations/UsersTable.php#L4), [db_query](../../../Website/config/db.php#L36), [BaseController.respond](../../../Website/core/BaseController.php#L10), [mobile Product fields](../../../Mobile/lib/data/models/product.dart#L3). Mobile fields describe future compatibility, not production pricing rules.

Proposed migrations create categories, brands, products, variants, images and catalog version metadata. Use the agreed ordered migration convention. Proposed `app/Services/ProductService.php` owns catalog validation/reads/writes.

| Record | Required business fields / constraints |
| --- | --- |
| Category | ID, unique slug, name, enabled flag, optional parent with cycle prevention |
| Brand | ID, unique slug, name, enabled flag |
| Product | ID, unique slug, category/optional brand FK, name/description/search tags, status, feature flag, revision, timestamps |
| Variant | ID, product FK, unique SKU, finish/thickness/dimensions, sell_unit, quantity increment, optional coverage, unit_price_minor, enabled, revision |
| Image | ID, product FK, optional variant FK belonging to same product, generated public path, MIME/dimensions, position, alt text |

At least one enabled variant is required before activating a product. A product with order references is archived, not deleted. A stock-bearing variant has an inventory row; DAT-02 creates those atomically through product activation/inventory setup. No orphan product metadata or inferred float conversion.

Save algorithm: validate bounded strings/slug/SKU/units → load staff grant → begin → lock existing product then variants in stable order → compare expected revision → insert/update all validated records → reject SKU/slug uniqueness conflicts with field error → audit and increment durable catalog version → commit → return new revision. If a variant removed from input is referenced by orders, mark inactive instead of deleting it. File handling remains outside SQL, per ADM-02.

Read algorithm: active product + enabled category/brand policy → enabled variants and primary/gallery images → effective current public price/availability projection → bounded result. Require at least one active sellable variant to label an item purchasable; don't join all images/variants into duplicate listing cards. Query variants/images in bounded bulk queries instead of one SQL query per product card.

Tests: two conflicting slugs/SKUs, stale revision, incompatible units, negative/overflow price, image variant from another product, category cycle, archive with order history, product with no sellable variant, paginated listing without duplicate products. Verify indexes/FKs on MySQL and local fallbacks separately.

## DAT-03

Existing references: [Auth.isAuthenticated() and session user ID](../../../Website/core/Auth.php#L21), [mobile cart behavior](../../../Mobile/lib/core/state/cart_controller.dart#L11), [address model](../../../Mobile/lib/data/models/address.dart#L1), [CartItem model](../../../Mobile/lib/data/models/cart_item.dart#L1). Preserve mobile offline behavior; backend owns real cart semantics.

Proposed schema: addresses with user FK; carts with exactly one owner (`user_id` or `guest_token_hash`), status/revision/expiry; cart_items unique `(cart_id, variant_id)` plus canonical quantity. Use explicit ownership guards even when SQLite/MySQL CHECK support differs. Default address updates lock the user's address set and ensure at most one default through application logic/appropriate constraint.

Change algorithm:

1. Resolve server principal; for guest use protected opaque token hash, creating a cart only when needed.
2. Begin, lock owned active cart, validate expected revision, variant existence/enabled/quantity increment.
3. Upsert a unique line or delete it when the explicit remove action is used. Reject negative and invalid quantities; do not treat invalid input as removal.
4. Increment cart revision; compute display totals from current server prices. Availability is advisory and no stock is reserved by add-to-cart.
5. Commit; return cart/revision. Checkout must later revalidate every line.

Guest merge algorithm: on verified login, lock both carts in ascending ID order → verify guest token and destination user → combine identical variants, validate increments/caps → mark guest cart merged and revoke its token → increment destination revision → commit. Mark merge result idempotently so repeated login does not double quantities. Inactive/missing variants are excluded with a visible report, not silently purchased.

Tests: parallel line updates require revision retry, unique lines remain unique, guessed cart ID fails, guest merge replay doesn't double quantity, default address race doesn't produce multiple defaults, saved prices are ignored, login as a different user doesn't inherit the prior user's cart/address state.
