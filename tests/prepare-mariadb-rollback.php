<?php
declare(strict_types=1);
require_once __DIR__.'/../ledger/database.php';
require_once __DIR__.'/../ledger/schema-mariadb.php';
if(PHP_SAPI!=='cli')exit(2);
$pdo=connectLedgerDatabase();installMariaSchema($pdo,false);
$pdo->exec("CREATE TRIGGER test_force_import_failure BEFORE INSERT ON entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='intentional rollback test'");
echo "Prepared rollback fault injection\n";
