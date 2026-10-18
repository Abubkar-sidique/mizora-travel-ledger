<?php
declare(strict_types=1);
require_once __DIR__.'/../ledger/database.php';
if(PHP_SAPI!=='cli')exit(2);
$pdo=connectLedgerDatabase();$pdo->beginTransaction();
$lock=$pdo->prepare('SELECT id FROM accounts WHERE id=? AND agency_id=? AND archived=0 FOR UPDATE');$lock->execute([1,1]);if(!$lock->fetch())throw new RuntimeException('Account row lock unavailable');
usleep(700000);
$source=$pdo->prepare('SELECT debit,credit FROM entries WHERE id=? AND agency_id=? AND account_id=? AND deleted=0');$source->execute([1,1,1]);$charge=$source->fetch(PDO::FETCH_ASSOC);if(!$charge)throw new RuntimeException('Charge fixture unavailable');
$sum=$pdo->query('SELECT COALESCE(SUM(debit+credit),0) FROM entries WHERE agency_id=1 AND account_id=1 AND deleted=0 AND (linked_entry_id=1 OR bill_id=1)')->fetchColumn();
$amount=4000;$available=max((int)$charge['debit'],(int)$charge['credit'])-(int)$sum;
if($amount>$available){$pdo->rollBack();echo "REJECTED\n";exit(0);}
$insert=$pdo->prepare("INSERT INTO entries(agency_id,account_id,edate,note,ref,cat,debit,credit,linked_entry_id,request_key) VALUES(1,1,'2025-04-01','Concurrency regression','CONC','payment_received',0,?,1,?)");$insert->execute([$amount,bin2hex(random_bytes(24))]);$pdo->commit();echo "ACCEPTED\n";
