<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    fwrite(STDERR, "Usage: php verify-v4-migration.php DATABASE\n");
    exit(2);
}
$db = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
$db->enableExceptions(true);
$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
if ($db->querySingle('PRAGMA user_version') !== 7) $fail('Migration did not reach schema 7');
if ($db->querySingle('PRAGMA integrity_check') !== 'ok') $fail('SQLite integrity check failed');
$foreign = $db->query('PRAGMA foreign_key_check');
if ($foreign->fetchArray()) $fail('Foreign key check failed');
$account = $db->querySingle('SELECT opening FROM accounts WHERE id=1 AND agency_id=1');
$entry = $db->querySingle("SELECT edate || '|' || note || '|' || ref || '|' || cat || '|' || debit || '|' || credit || '|' || deleted FROM entries WHERE id=1 AND agency_id=1 AND account_id=1");
$audit = $db->querySingle("SELECT action || '|' || entity || '|' || entity_id || '|' || details FROM audit_log WHERE id=1");
if ($account !== 25000) $fail('Opening balance changed during migration');
if ($entry !== '2024-01-02|Preserve this financial row|FIXTURE-1|ticket_sold|17500|0|0') $fail('Financial entry changed during migration');
if ($audit !== 'create|entry|1|Fixture audit row') $fail('Audit history changed during migration');
$db->close();
fwrite(STDOUT, "PASS schema 7, integrity, foreign keys, opening balance, entry row, audit row\n");
