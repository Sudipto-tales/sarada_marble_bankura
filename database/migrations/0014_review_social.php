<?php

class ReviewSocial extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        // Half-star ratings: the existing `rating` keeps its CHECK(1..5); precise half-steps live in rating_tenths.
        if (!array_key_exists('rating_tenths', MigrationSchema::columns($this->pdo, 'product_reviews'))) {
            $this->pdo->exec('ALTER TABLE product_reviews ADD COLUMN rating_tenths INT NULL');
        }
        $this->pdo->exec('UPDATE product_reviews SET rating_tenths = rating * 10 WHERE rating_tenths IS NULL');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS review_replies (id $id,review_id INT NOT NULL,user_id INT NOT NULL,body TEXT NOT NULL,created_at BIGINT NOT NULL,
            FOREIGN KEY(review_id) REFERENCES product_reviews(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users_tbl(id) ON DELETE RESTRICT)$suffix");
        // Polymorphic likes over reviews and replies; target_id is app-enforced, so no foreign key on it.
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS review_likes (id $id,target_type VARCHAR(8) NOT NULL,target_id INT NOT NULL,user_id INT NOT NULL,created_at BIGINT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users_tbl(id) ON DELETE RESTRICT)$suffix");
        foreach ([['review_replies','idx_reply_review',['review_id','id'],false],
            ['review_likes','idx_like_owner',['target_type','target_id','user_id'],true]] as [$table,$name,$columns,$unique]) MigrationSchema::ensureIndex($this->pdo,$table,$name,$columns,$unique);
        $this->verify();
    }
    public function verify(): void
    {
        MigrationSchema::requireColumns($this->pdo,'product_reviews',['rating_tenths']);
        MigrationSchema::verifyDefinition($this->pdo,'review_replies',['id'=>'id','review_id'=>'int','user_id'=>'int','body'=>'text','created_at'=>'bigint'],
            [[['review_id','id'],false]],[['review_id','product_reviews','id','CASCADE'],['user_id','users_tbl','id','RESTRICT']]);
        MigrationSchema::verifyDefinition($this->pdo,'review_likes',['id'=>'id','target_type'=>'varchar(8)','target_id'=>'int','user_id'=>'int','created_at'=>'bigint'],
            [[['target_type','target_id','user_id'],true]],[['user_id','users_tbl','id','RESTRICT']]);
    }
}
