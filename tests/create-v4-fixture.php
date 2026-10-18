<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !isset($argv[1]) || file_exists($argv[1])) {
    fwrite(STDERR, "Usage: php create-v4-fixture.php NEW_DATABASE_PATH\n");
    exit(2);
}
$db = new SQLite3($argv[1], SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
$db->enableExceptions(true);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec("CREATE TABLE agencies(id INTEGER PRIMARY KEY,name TEXT NOT NULL,phone TEXT,active INTEGER DEFAULT 1,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE users(id INTEGER PRIMARY KEY,agency_id INTEGER REFERENCES agencies(id),username TEXT UNIQUE NOT NULL,pass TEXT NOT NULL,role TEXT NOT NULL,fails INTEGER DEFAULT 0,locked_until INTEGER DEFAULT 0);
CREATE TABLE accounts(id INTEGER PRIMARY KEY,agency_id INTEGER NOT NULL REFERENCES agencies(id),name TEXT NOT NULL,phone TEXT,kind TEXT DEFAULT 'customer',opening INTEGER DEFAULT 0,archived INTEGER NOT NULL DEFAULT 0);
CREATE TABLE entries(id INTEGER PRIMARY KEY,agency_id INTEGER NOT NULL,account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,edate TEXT NOT NULL,note TEXT,ref TEXT,cat TEXT DEFAULT 'other',deleted INTEGER DEFAULT 0,debit INTEGER DEFAULT 0,credit INTEGER DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE audit_log(id INTEGER PRIMARY KEY,agency_id INTEGER,user_id INTEGER,action TEXT NOT NULL,entity TEXT,entity_id INTEGER,details TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
INSERT INTO agencies(id,name,phone,active) VALUES(1,'Migration Fixture Agency','03000000000',1);
INSERT INTO users(id,agency_id,username,pass,role) VALUES(1,NULL,'fixture.master','fixture-hash','master');
INSERT INTO accounts(id,agency_id,name,phone,kind,opening) VALUES(1,1,'Migration Fixture Account','03000000001','customer',25000);
INSERT INTO entries(id,agency_id,account_id,edate,note,ref,cat,deleted,debit,credit) VALUES(1,1,1,'2024-01-02','Preserve this financial row','FIXTURE-1','ticket_sold',0,17500,0);
INSERT INTO audit_log(id,agency_id,user_id,action,entity,entity_id,details) VALUES(1,1,1,'create','entry',1,'Fixture audit row');
PRAGMA user_version=4;");
$db->close();
