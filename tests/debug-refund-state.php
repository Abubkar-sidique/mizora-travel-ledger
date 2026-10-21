<?php
declare(strict_types=1);
$db = new PDO('sqlite:' . __DIR__ . '/../ledger/data/ledger.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
echo "--- refund entries ---\n";
foreach ($db->query("SELECT id,agency_id,account_id,cat,debit,credit,linked_entry_id,deleted,ref FROM entries WHERE cat LIKE '%refund%' ORDER BY id DESC LIMIT 10") as $r) {
    echo json_encode($r), PHP_EOL;
}
echo "--- linked entries sanity (post void test state) ---\n";
foreach ($db->query("SELECT id,cat,debit,credit,linked_entry_id,deleted FROM entries WHERE linked_entry_id IS NOT NULL ORDER BY id DESC LIMIT 10") as $r) {
    echo json_encode($r), PHP_EOL;
}
