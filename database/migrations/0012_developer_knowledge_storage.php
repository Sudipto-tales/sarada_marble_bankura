<?php

class DeveloperKnowledgeStorage extends Migration
{
    public function up()
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS developer_traces (
            id VARCHAR(32) NOT NULL PRIMARY KEY, route VARCHAR(190) NOT NULL,
            method VARCHAR(10) NOT NULL, module VARCHAR(40) NOT NULL,
            started_at BIGINT NOT NULL, duration_us BIGINT NOT NULL,
            status INT NOT NULL, spans TEXT NOT NULL, truncated INT NOT NULL)$suffix");
        MigrationSchema::ensureIndex($this->pdo,'developer_traces','idx_developer_trace_time',['started_at']);
        MigrationSchema::ensureIndex($this->pdo,'developer_traces','idx_developer_trace_route_time',['route','started_at']);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS developer_daily_reports (
            day VARCHAR(10) NOT NULL PRIMARY KEY, generated_at BIGINT NOT NULL, report TEXT NOT NULL)$suffix");
        $this->verify();
    }
    public function verify(): void
    {
        MigrationSchema::requireColumns($this->pdo,'developer_traces',['id','route','method','module','started_at','duration_us','status','spans','truncated']);
        MigrationSchema::requireColumns($this->pdo,'developer_daily_reports',['day','generated_at','report']);
        MigrationSchema::ensureIndex($this->pdo,'developer_traces','idx_developer_trace_time',['started_at']);
        MigrationSchema::ensureIndex($this->pdo,'developer_traces','idx_developer_trace_route_time',['route','started_at']);
    }
}
