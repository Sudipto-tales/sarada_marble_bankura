<?php
// Manually test migration 0005 up() and verify()
$pdo = new PDO('sqlite:database/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Drop existing tables
try { $pdo->exec('DROP TABLE IF EXISTS products, catalog_versions, catalog_hierarchy_lock, commerce_write_lock, product_images, categories, brands, migrations'); } catch (Exception $e) {}

// Create tables - exactly as in the migration up()
$mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

// categories
$pdo->exec("CREATE TABLE IF NOT EXISTS categories (id $id, slug VARCHAR(120) NOT NULL, name VARCHAR(190) NOT NULL, enabled INT NOT NULL DEFAULT 1, parent_id INT NULL, FOREIGN KEY(parent_id) REFERENCES categories(id) ON DELETE RESTRICT)$suffix");
echo "categories: OK\n";

// brands
$pdo->exec("CREATE TABLE IF NOT EXISTS brands (id $id, slug VARCHAR(120) NOT NULL, name VARCHAR(190) NOT NULL, enabled INT NOT NULL DEFAULT 1)$suffix");
echo "brands: OK\n";

// products
$pdo->exec("CREATE TABLE IF NOT EXISTS products (id $id, slug VARCHAR(120) NOT NULL, name VARCHAR(190) NOT NULL, description TEXT NOT NULL, tags TEXT NOT NULL, category_id INT NOT NULL, brand_id INT NULL, price_minor BIGINT NOT NULL, thickness_mm_milli BIGINT NULL, width_mm_milli BIGINT NULL, length_mm_milli BIGINT NULL, sell_unit VARCHAR(8) NOT NULL, qty_increment_milli BIGINT NOT NULL DEFAULT 1000, coverage_sqft_milli BIGINT NULL, status VARCHAR(16) NOT NULL DEFAULT 'active', featured INT NOT NULL DEFAULT 0, availability_status VARCHAR(20) NOT NULL DEFAULT 'in_stock', revision BIGINT NOT NULL DEFAULT 1, created_at VARCHAR(20) NOT NULL, updated_at VARCHAR(20) NOT NULL, FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE RESTRICT, FOREIGN KEY(brand_id) REFERENCES brands(id) ON DELETE RESTRICT)$suffix");
echo "products: OK\n";

// catalog_versions
$pdo->exec("CREATE TABLE IF NOT EXISTS catalog_versions (id $id, namespace VARCHAR(40) NOT NULL, version BIGINT NOT NULL)$suffix");
echo "catalog_versions: OK\n";

// catalog_hierarchy_lock
$pdo->exec("CREATE TABLE IF NOT EXISTS catalog_hierarchy_lock (id $id, revision BIGINT NOT NULL)$suffix");
echo "catalog_hierarchy_lock: OK\n";

// commerce_write_lock
$pdo->exec("CREATE TABLE IF NOT EXISTS commerce_write_lock (id $id, revision BIGINT NOT NULL)$suffix");
echo "commerce_write_lock: OK\n";

// indexes
$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_category_slug ON categories(slug)');
$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_brand_slug ON brands(slug)');
$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_product_slug ON products(slug)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_product_catalog ON products(status, category_id, id)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_product_brand ON products(brand_id, status)');
echo "indexes: OK\n";

// seed data
$pdo->exec("INSERT OR IGNORE INTO catalog_versions (namespace,version) VALUES ('catalog',1)");
$pdo->exec("INSERT OR IGNORE INTO catalog_hierarchy_lock (id,revision) VALUES (1,1)");
$pdo->exec("INSERT OR IGNORE INTO commerce_write_lock (id,revision) VALUES (1,1)");
echo "seed data: OK\n";

// Now try verify using MigrationSchema
require 'config/migration.php';

echo "\nRunning verify...\n";

// Verify categories
try {
    MigrationSchema::verifyDefinition($pdo,'categories',['id'=>'id','slug'=>'varchar(120)','name'=>'varchar(190)','enabled'=>'int','parent_id'=>'?int'],[[['slug'],true]],[['parent_id','categories','id','RESTRICT']]);
    echo "categories verify: OK\n";
} catch (Exception $e) {
    echo "categories verify: FAIL - " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// Verify brands
try {
    MigrationSchema::verifyDefinition($pdo,'brands',['id'=>'id','slug'=>'varchar(120)','name'=>'varchar(190)','enabled'=>'int'],[[['slug'],true]]);
    echo "brands verify: OK\n";
} catch (Exception $e) {
    echo "brands verify: FAIL - " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// Verify products
try {
    MigrationSchema::verifyDefinition($pdo,'products',['id'=>'id','slug'=>'varchar(120)','name'=>'varchar(190)','description'=>'text','tags'=>'text','category_id'=>'int','brand_id'=>'?int','price_minor'=>'bigint','thickness_mm_milli'=>'?bigint','width_mm_milli'=>'?bigint','length_mm_milli'=>'?bigint','sell_unit'=>'varchar(8)','qty_increment_milli'=>'bigint','coverage_sqft_milli'=>'?bigint','status'=>'varchar(16)','featured'=>'int','revision'=>'bigint','created_at'=>'varchar(20)','updated_at'=>'varchar(20)'],[[['slug'],true],[['status','category_id','id'],false],[['brand_id','status'],false]],[['category_id','categories','id','RESTRICT'],['brand_id','brands','id','RESTRICT']]);
    echo "products verify: OK\n";
} catch (Exception $e) {
    echo "products verify: FAIL - " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}