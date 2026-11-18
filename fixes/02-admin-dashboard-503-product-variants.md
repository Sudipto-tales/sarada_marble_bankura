# 02: 503 right after admin login (`product_variants` no longer exists)

## Root cause
The "sync pending changes" commits on Nov 15 **rewrote migration `0005_catalog_schema.php`**:
- The `product_variants` table was removed.
- Price, unit and size moved onto `products` (`price_minor`, `sell_unit`, `qty_increment_milli`, ...).
- `inventory.variant_id` became `inventory.product_id` (`0006`).
- `product_images.variant_id` was removed.

`InventoryService` and the migrations were updated. **`AdminReadService` was not**, and two lines in `ProductService` were missed.
After login, `/admin` runs `Admin::index()` → `AdminReadService::attention()`. That runs
`SELECT … FROM inventory i JOIN product_variants v …` → `PDOException` → **503**.
The Products, Inventory, Stock and Analytics admin pages hit the same error.

## Part A: fix the queries in `Website/app/Services/AdminReadService.php`

Mapping used everywhere:

| old (variant) | new (product) |
|---|---|
| `JOIN product_variants v ON v.id=i.variant_id JOIN products p ON p.id=v.product_id` | `JOIN products p ON p.id=i.product_id` |
| `v.id` | `p.id` |
| `v.sku` | `p.slug` (there is no sku column now) |
| `v.sell_unit` / `v.qty_increment_milli` | `p.sell_unit` / `p.qty_increment_milli` |
| `v.unit_price_minor` (MIN/MAX subquery) | `p.price_minor` |
| `v.enabled=1` | `p.status='active'` |
| `WHERE i.variant_id=?` | `WHERE i.product_id=?` |

Keep the output alias names (`variant_id`, `sku`, `min_price`, `max_price`) so the views keep working.

### A1. `attention()` (the direct cause of the post-login 503)
Old:
```php
"SELECT COUNT(*) FROM inventory i JOIN product_variants v ON v.id=i.variant_id WHERE v.enabled=1 AND i.on_hand_milli-i.reserved_milli<v.qty_increment_milli"
```
New:
```php
"SELECT COUNT(*) FROM inventory i JOIN products p ON p.id=i.product_id WHERE p.status='active' AND i.on_hand_milli-i.reserved_milli<p.qty_increment_milli"
```

### A2. `table()` config, `'products'` entry
Old columns fragment:
```php
(SELECT MIN(v.unit_price_minor) FROM product_variants v WHERE v.product_id=p.id AND v.enabled=1) AS price',
```
New:
```php
p.price_minor AS price',
```
Old extra search predicate:
```php
"p.id IN (SELECT product_id FROM product_variants WHERE sku LIKE ? ESCAPE '!')"
```
New (keeps one `?`):
```php
"p.slug LIKE ? ESCAPE '!'"
```

### A3. `table()` config, `'inventory'` entry
New entry (replace the whole `'inventory'=>[...]` element):
```php
'inventory'=>['inventory i JOIN products p ON p.id=i.product_id','p.id,p.name,p.slug AS sku,p.sell_unit,i.on_hand_milli AS on_hand,i.reserved_milli AS reserved,(i.on_hand_milli-i.reserved_milli) AS available',
    ['p.name','p.slug','p.sell_unit','i.on_hand_milli','i.reserved_milli','available'],['p.name','p.slug']],
```

### A4. `productCards()`, the three variant subqueries
New:
```php
p.slug AS sku,
p.price_minor AS min_price,
p.price_minor AS max_price,
```

### A5. `stock()`
```php
$row=CommerceDatabase::query($this->pdo,'SELECT i.*,p.slug AS sku,p.sell_unit,p.qty_increment_milli,p.name FROM inventory i JOIN products p ON p.id=i.product_id WHERE i.product_id=?',[$id])->fetch();
```

### A6. Low-stock queries (about lines 136, 151 and 183, in `analyticsDashboard()` / `lowStockRows()`)
Apply the mapping table. Example:
```php
"SELECT p.id AS variant_id,p.name,p.slug AS sku,p.sell_unit,p.qty_increment_milli,(i.on_hand_milli-i.reserved_milli) AS available_milli
 FROM inventory i JOIN products p ON p.id=i.product_id WHERE p.status='active' AND …"
```
Count query:
```php
"SELECT COUNT(*) FROM inventory i JOIN products p ON p.id=i.product_id WHERE p.status='active' AND i.on_hand_milli-i.reserved_milli<p.qty_increment_milli*5"
```

Check: `grep -n "product_variants\|v\.\|variant_id=" Website/app/Services/AdminReadService.php` must return **nothing** except the `AS variant_id` aliases.

## Part B: `Website/app/Services/ProductService.php`
- About line 198: `INSERT INTO product_images (product_id,variant_id,path,…) VALUES (?,NULL,?,…)` → remove `variant_id,` and the matching `NULL,`.
- About line 263: remove `AND pi.variant_id IS NULL`.

Then run `grep -rn "product_variants\|variant_id" Website/app Website/core`. Fix any other query that touches the DB. Form field names such as `$_POST['variant_id']` in `Admin.php` can stay, because they now carry the product id.

## Part C: databases created BEFORE Nov 15 (production)
An edited migration **never runs again** on a DB that already applied it. The production DB still has `product_variants` and no `products.price_minor`. Add a new forward migration. Do not edit old ones.

`Website/database/migrations/0038_collapse_variants.php`:
```php
<?php

/** Forward-only: move first enabled variant's fields onto products for DBs migrated before the 0005 rewrite. */
class CollapseVariants extends Migration
{
    public function up()
    {
        $cols = MigrationSchema::columns($this->pdo, 'products');
        $add = ['price_minor' => 'BIGINT NOT NULL DEFAULT 0', 'thickness_mm_milli' => 'BIGINT NULL',
            'width_mm_milli' => 'BIGINT NULL', 'length_mm_milli' => 'BIGINT NULL',
            'sell_unit' => "VARCHAR(8) NOT NULL DEFAULT 'sqft'", 'qty_increment_milli' => 'BIGINT NOT NULL DEFAULT 1000',
            'coverage_sqft_milli' => 'BIGINT NULL', 'availability_status' => "VARCHAR(20) NOT NULL DEFAULT 'in_stock'"];
        foreach ($add as $name => $type) if (!isset($cols[$name])) $this->pdo->exec("ALTER TABLE products ADD $name $type");
        if (!isset(MigrationSchema::columns($this->pdo, 'product_variants')['id'])) return; // fresh install: nothing to copy
        $pick = "(SELECT %s FROM product_variants v WHERE v.product_id=products.id ORDER BY v.enabled DESC, v.id LIMIT 1)";
        foreach (['price_minor' => 'unit_price_minor', 'thickness_mm_milli' => 'thickness_mm_milli', 'width_mm_milli' => 'width_mm_milli',
            'length_mm_milli' => 'length_mm_milli', 'sell_unit' => 'sell_unit', 'qty_increment_milli' => 'qty_increment_milli',
            'coverage_sqft_milli' => 'coverage_sqft_milli'] as $to => $from) {
            $this->pdo->exec("UPDATE products SET $to = COALESCE(" . sprintf($pick, $from) . ", $to)");
        }
        // inventory.variant_id -> product_id
        if (isset(MigrationSchema::columns($this->pdo, 'inventory')['variant_id'])
            && !isset(MigrationSchema::columns($this->pdo, 'inventory')['product_id'])) {
            $this->pdo->exec('ALTER TABLE inventory ADD product_id INT NULL');
            $this->pdo->exec('UPDATE inventory SET product_id = (SELECT product_id FROM product_variants v WHERE v.id = inventory.variant_id)');
        }
    }
    public function verify(): void
    {
        $cols = MigrationSchema::columns($this->pdo, 'products');
        foreach (['price_minor', 'sell_unit', 'qty_increment_milli'] as $c) if (!isset($cols[$c])) throw new RuntimeException("products.$c missing");
    }
}
```
> ⚠ First check that `MigrationSchema::columns()` returns `[]` for a missing table. If it throws instead, wrap that call in try/catch.
> ⚠ Inventory with several variants per product: this keeps one row per variant. Before deploying, run
> `SELECT product_id, COUNT(*) FROM inventory GROUP BY product_id HAVING COUNT(*)>1` and merge those rows by hand. **Back up the DB first.**

## Test
1. `php -l` on every file you changed.
2. Fresh local DB: `rm -f Website/database/database.sqlite && cd Website && php vayu migrate` (fix 05-B must be applied first if migrate fatals).
3. `php vayu commerce:create admin@local.test superadmin`, then set a 12+ character password.
4. `php vayu run`. Log in at `http://localhost:8000/admin/login`. **`/admin` loads (200), with no 503.**
5. Open `/admin/products` and `/admin/inventory`. Both load, and the fix-01 log has no new `[503]` line.

## Done when
`/admin` and its tables load after login. **STOP. Report and ask for "next".**
