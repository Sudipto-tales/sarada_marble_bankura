<?php

require_once __DIR__ . '/UsersTable.php';

/** Explicit forward repair for databases which already recorded UsersTable. */
class UsersFoundation extends Migration
{
    public function up() { (new UsersTable($this->pdo))->up(); }
    public function verify(): void { (new UsersTable($this->pdo))->verify(); }
}
