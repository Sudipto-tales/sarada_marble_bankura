<?php
class PaymentDiagnostics extends Migration
{
    public function up()
    {
        if(!isset(MigrationSchema::columns($this->pdo,'payments')['last_error_code']))$this->pdo->exec('ALTER TABLE payments ADD COLUMN last_error_code VARCHAR(80) NULL');
        $this->verify();
    }
    public function verify():void {MigrationSchema::verifyDefinition($this->pdo,'payments',['last_error_code'=>'?varchar(80)']);}
}
