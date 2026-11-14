<?php

/** Category types (CAT-TYPE-01): an explicit two-level taxonomy (type -> category) that supersedes the
    former self-referential category tree. Each category optionally belongs to one category_type; a seeded
    "General" type backfills existing rows so nothing is left unclassified during rollout. type_id stays
    nullable for now (a later migration can enforce NOT NULL once every category is assigned). The former
    parent_id column is retired - left dormant for a later drop and no longer read by services or templates.
    The type link mirrors 0007's cross-dialect ownership: a real FK under MySQL, BEFORE triggers under SQLite
    (which cannot add an FK via ALTER TABLE). Category/type writes advance the catalog cache version in the
    service layer, so storefront reads invalidate transactionally. */
class CategoryTypes extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS category_types (id $id, slug VARCHAR(120) NOT NULL,
            name VARCHAR(190) NOT NULL, enabled INT NOT NULL DEFAULT 1, position INT NOT NULL DEFAULT 0)$suffix");
        MigrationSchema::ensureIndex($this->pdo,'category_types','idx_category_type_slug',['slug'],true);
        // Seed the default type so existing categories can be classified without forcing a choice yet.
        $insert = $mysql ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $this->pdo->exec("$insert INTO category_types (slug,name,enabled,position) VALUES ('general','General',1,0)");
        if (!isset(MigrationSchema::columns($this->pdo,'categories')['type_id'])) $this->pdo->exec('ALTER TABLE categories ADD COLUMN type_id INT NULL');
        // Create the type_id index before the FK so MySQL backs the constraint with it instead of a duplicate.
        MigrationSchema::ensureIndex($this->pdo,'categories','idx_category_type',['type_id'],false);
        // Backfill existing categories to the default type; rerunnable (only touches still-unclassified rows).
        $general = (int)$this->pdo->query("SELECT id FROM category_types WHERE slug='general'")->fetchColumn();
        $this->pdo->prepare('UPDATE categories SET type_id=? WHERE type_id IS NULL')->execute([$general]);
        $this->typeLink($mysql);
        $this->verify();
    }

    private function typeLink(bool $mysql): void
    {
        if ($mysql) {
            $query = $this->pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='categories' AND COLUMN_NAME='type_id' AND REFERENCED_TABLE_NAME='category_types'");
            if (!(int)$query->fetchColumn()) $this->pdo->exec('ALTER TABLE categories ADD CONSTRAINT fk_category_type FOREIGN KEY(type_id) REFERENCES category_types(id) ON DELETE RESTRICT');
        } else {
            // SQLite cannot add an FK with ALTER TABLE. Triggers enforce the same optional link and block
            // deleting a type still in use, without rebuilding the live categories table.
            foreach (['INSERT','UPDATE'] as $event) {
                $name = 'category_type_'.strtolower($event);
                $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS $name BEFORE $event ON categories
                    WHEN NEW.type_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM category_types WHERE id=NEW.type_id)
                    BEGIN SELECT RAISE(ABORT,'category type link'); END");
            }
            $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS category_type_delete BEFORE DELETE ON category_types
                WHEN EXISTS(SELECT 1 FROM categories WHERE type_id=OLD.id)
                BEGIN SELECT RAISE(ABORT,'category type in use'); END");
        }
    }

    public function verify(): void
    {
        MigrationSchema::verifyDefinition($this->pdo,'category_types',['id'=>'id','slug'=>'varchar(120)','name'=>'varchar(190)','enabled'=>'int','position'=>'int'],[[['slug'],true]]);
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            MigrationSchema::verifyDefinition($this->pdo,'categories',['type_id'=>'?int'],[[['type_id'],false]],[['type_id','category_types','id','RESTRICT']]);
        } else {
            MigrationSchema::verifyDefinition($this->pdo,'categories',['type_id'=>'?int'],[[['type_id'],false]]);
            foreach (['category_type_insert','category_type_update','category_type_delete'] as $name) {
                $query = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type='trigger' AND name=?");$query->execute([$name]);
                if (!$query->fetchColumn()) throw new RuntimeException('Missing category type link trigger.');
            }
        }
    }
}
