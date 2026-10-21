<?php
declare(strict_types=1);
// Reproduce the refund-insert path against a throwaway SQLite copy of the schema.
$dbPath = sys_get_temp_dir() . '/mizora-refund-probe-' . getmypid() . '.sqlite';
@unlink($dbPath);
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ERRMODE_EXCEPTION, true);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE entries (
  id INTEGER PRIMARY KEY,
  agency_id INTEGER NOT NULL,
  account_id INTEGER NOT NULL,
  edate TEXT NOT NULL,
  note TEXT, ref TEXT,
  cat TEXT DEFAULT "other",
  deleted INTEGER DEFAULT 0,
  debit INTEGER DEFAULT 0, credit INTEGER DEFAULT 0,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  bill_id INTEGER, linked_entry_id INTEGER,
  request_key TEXT,
  currency TEXT NOT NULL DEFAULT "PKR",
  fx_rate INTEGER NOT NULL DEFAULT 1000000,
  foreign_amount INTEGER NOT NULL DEFAULT 0
)');
require_once __DIR__ . '/../ledger/accounting.php';

const DEBIT_CATS=['ticket_sold'=>'Air Ticket Sold','commission_earned'=>'Commission Earned','cash_transfer_in'=>'Transfer In','payment_paid'=>'Payment Paid','cash_paid'=>'Cash Paid','bank_paid'=>'Bank Paid','other_debit'=>'Other Debit'];
const CREDIT_CATS=['ticket_refund'=>'Ticket Refund','travel_refund'=>'Visa / Umrah Refund','payment_received'=>'Payment Received','cash_received'=>'Cash Received','bank_received'=>'Bank Received','cash_transfer_out'=>'Transfer Out','expense_paid'=>'Expense Paid','adjustment'=>'Adjustment','other_credit'=>'Other Credit'];

$fxCheck='CAST(NEW.foreign_amount/1000000 AS INTEGER)*NEW.fx_rate+CAST(((NEW.foreign_amount%1000000)*NEW.fx_rate+500000)/1000000 AS INTEGER)';
$dr=implode(',',array_map([$pdo,'quote'],array_merge(array_keys(DEBIT_CATS),['commission_earned'])));
$cr=implode(',',array_map([$pdo,'quote'],array_merge(array_keys(CREDIT_CATS),['expense_paid'])));
$currencies=implode(',',array_map([$pdo,'quote'],LEDGER_CURRENCY_CODES));
$triggerSql = "CREATE TRIGGER entry_category_guard BEFORE INSERT ON entries WHEN (NEW.debit>0 AND NEW.cat NOT IN ($dr)) OR (NEW.credit>0 AND NEW.cat NOT IN ($cr)) OR NEW.fx_rate<=0 OR NEW.fx_rate>100000000000 OR NEW.foreign_amount<=0 OR NEW.foreign_amount>9999999999999 OR NEW.currency NOT IN ($currencies) OR (NEW.currency='PKR' AND (NEW.fx_rate!=1000000 OR NEW.foreign_amount!=NEW.debit+NEW.credit)) OR NEW.debit+NEW.credit!=($fxCheck) BEGIN SELECT RAISE(ABORT,'Invalid currency conversion, rate, amount or category direction'); END;";
$pdo->exec($triggerSql);

function insertEntry(int $debit, int $credit, string $cat, int $fx = 1000000, int $foreign = 0): void {
    global $pdo;
    try {
        $st = $pdo->prepare('INSERT INTO entries(agency_id,account_id,edate,note,ref,cat,debit,credit,linked_entry_id,request_key,currency,fx_rate,foreign_amount) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([1, 1, '2026-10-19', 'probe', 'PROBE-' . uniqid(), $cat, $debit, $credit, null, str_repeat('a', 48), 'PKR', $fx, $foreign]);
        echo "OK  debit=$debit credit=$credit cat=$cat fx=$fx foreign=$foreign\n";
    } catch (Throwable $ex) {
        echo "ERR debit=$debit credit=$credit cat=$cat fx=$fx foreign=$foreign :: " . $ex->getMessage() . "\n";
    }
}

// Case 1: PKR sale entry exactly as app does it (foreign=debit+credit)
insertEntry(25000, 0, 'ticket_sold', 1000000, 25000);
// Case 2: PKR refund entry exactly as app does it
insertEntry(0, 2500, 'ticket_refund', 1000000, 2500);
// Case 3: foreign-currency entry (probing why live schema rejected it earlier — retesting with the exact fxCheck expression used by hardening.php)
insertEntry(2805000, 0, 'ticket_sold', 280500000, 2795000);
// Case 4: transfer paired entry (PKR, fx=1000000, foreign=amount)
insertEntry(0, 7000000, 'cash_transfer_out', 1000000, 7000000);

@unlink($dbPath);
