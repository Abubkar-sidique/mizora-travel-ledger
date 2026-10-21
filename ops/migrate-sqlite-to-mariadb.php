<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../ledger/database.php';
require_once __DIR__.'/../ledger/schema-mariadb.php';
require_once __DIR__.'/../ledger/accounting.php';

function failImport(string $message):never{fwrite(STDERR,"NO-GO: $message\n");exit(1);}
function tableNames(SQLite3 $source):array{$r=$source->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");$out=[];while($x=$r->fetchArray(SQLITE3_ASSOC))$out[]=$x['name'];return $out;}
function columns(SQLite3 $source,string $table):array{$sql="PRAGMA table_info(\"".str_replace('"','""',$table)."\")";$r=$source->query($sql);$out=[];while($x=$r->fetchArray(SQLITE3_ASSOC))$out[]=$x['name'];return $out;}
function scalar(SQLite3 $source,string $sql):int{return (int)$source->querySingle($sql);}
function validateFinancialSource(SQLite3 $s):void{
    $debit="'ticket_sold','umrah_sold','visa_sold','hotel_sold','transport_sold','tour_sold','commission_earned','cash_transfer_in','payment_paid','cash_paid','bank_paid','other_debit'";
    $credit="'ticket_bought','umrah_bought','visa_bought','hotel_bought','transport_bought','tour_bought','payment_received','cash_received','bank_received','cash_transfer_out','expense_paid','ticket_refund','travel_refund','adjustment','other_credit'";
    $payments="'payment_paid','cash_paid','bank_paid','payment_received','cash_received','bank_received','ticket_refund','travel_refund'";
    $currencies=implode(',',array_map(static fn(string $code)=>"'".$s->escapeString($code)."'",LEDGER_CURRENCY_CODES));
    $converted='(CAST(foreign_amount/1000000 AS INTEGER)*fx_rate+CAST(((foreign_amount%1000000)*fx_rate+500000)/1000000 AS INTEGER))';
    $checks=[
        "SELECT COUNT(*) FROM entries WHERE debit<0 OR credit<0 OR debit>9999999999999 OR credit>9999999999999 OR ((debit=0)=(credit=0))",
        "SELECT COUNT(*) FROM entries WHERE (debit>0 AND cat NOT IN ($debit)) OR (credit>0 AND cat NOT IN ($credit))",
        "SELECT COUNT(*) FROM entries WHERE currency NOT IN ($currencies) OR fx_rate<=0 OR fx_rate>100000000000 OR foreign_amount<=0 OR foreign_amount>9999999999999 OR (currency='PKR' AND (fx_rate!=1000000 OR foreign_amount!=debit+credit)) OR (currency!='PKR' AND debit+credit!=$converted)",
        "SELECT COUNT(*) FROM entries x LEFT JOIN entries s ON s.id=COALESCE(x.linked_entry_id,x.bill_id) WHERE COALESCE(x.linked_entry_id,x.bill_id) IS NOT NULL AND (s.id IS NULL OR s.agency_id!=x.agency_id OR s.account_id!=x.account_id OR s.deleted!=0 OR (x.debit>0 AND s.credit=0) OR (x.credit>0 AND s.debit=0) OR x.cat NOT IN ($payments) OR s.cat IN ($payments) OR (x.cat IN ('ticket_refund','travel_refund') AND (x.credit=0 OR s.debit=0)) OR (x.linked_entry_id IS NOT NULL AND x.bill_id IS NOT NULL AND x.linked_entry_id!=x.bill_id))",
        "SELECT COUNT(*) FROM entries s WHERE (s.debit>0 OR s.credit>0) AND (SELECT COALESCE(SUM(p.debit+p.credit),0) FROM entries p WHERE p.agency_id=s.agency_id AND p.account_id=s.account_id AND p.deleted=0 AND (p.linked_entry_id=s.id OR (p.bill_id=s.id AND (p.linked_entry_id IS NULL OR p.linked_entry_id!=p.bill_id))))>MAX(s.debit,s.credit)",
        "SELECT COUNT(*) FROM entries s WHERE s.deleted=1 AND EXISTS(SELECT 1 FROM entries p WHERE p.agency_id=s.agency_id AND p.account_id=s.account_id AND p.deleted=0 AND (p.linked_entry_id=s.id OR p.bill_id=s.id))",
    ];
    foreach($checks as $sql)if(scalar($s,$sql)!==0)failImport('Source financial invariants failed; refusing to migrate');
}
function sourceBalances(SQLite3 $s):array{
    $r=$s->query('SELECT a.id,a.opening,COALESCE(SUM(CASE WHEN e.deleted=0 THEN e.debit ELSE 0 END),0) debit,COALESCE(SUM(CASE WHEN e.deleted=0 THEN e.credit ELSE 0 END),0) credit FROM accounts a LEFT JOIN entries e ON e.account_id=a.id AND e.agency_id=a.agency_id GROUP BY a.id,a.opening ORDER BY a.id');$out=[];
    while($x=$r->fetchArray(SQLITE3_ASSOC))$out[(string)$x['id']]=[(string)$x['opening'],(string)$x['debit'],(string)$x['credit'],(string)((int)$x['opening']+(int)$x['debit']-(int)$x['credit'])];return $out;
}
function targetBalances(PDO $p):array{$r=$p->query('SELECT a.id,a.opening,COALESCE(SUM(CASE WHEN e.deleted=0 THEN e.debit ELSE 0 END),0) debit,COALESCE(SUM(CASE WHEN e.deleted=0 THEN e.credit ELSE 0 END),0) credit FROM accounts a LEFT JOIN entries e ON e.account_id=a.id AND e.agency_id=a.agency_id GROUP BY a.id,a.opening ORDER BY a.id');$out=[];while($x=$r->fetch(PDO::FETCH_ASSOC))$out[(string)$x['id']]=[(string)$x['opening'],(string)$x['debit'],(string)$x['credit'],(string)((int)$x['opening']+(int)$x['debit']-(int)$x['credit'])];return $out;}
function expectedCounts(SQLite3 $s,array $tables):array{$out=[];foreach($tables as $t)$out[$t]=scalar($s,'SELECT COUNT(*) FROM "'.str_replace('"','""',$t).'"');return $out;}
function actualCounts(PDO $p,array $tables):array{$out=[];foreach($tables as $t)$out[$t]=(int)$p->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn();return $out;}
function rowHashes(SQLite3|PDO $db,array $tables,bool $source):array{
    $orders=['agencies'=>'id','users'=>'id','accounts'=>'id','entries'=>'id','audit_log'=>'id','login_limits'=>$source?'"key"':'rate_key','chat_presence'=>'id','chat_messages'=>'id','chat_threads'=>'agency_id'];$out=[];
    foreach($tables as $table){$sql='SELECT * FROM '.($source?'"'.$table.'"':'`'.$table.'`').' ORDER BY '.$orders[$table];$rs=$db->query($sql);$hash=hash_init('sha256');
        while($row=$source?$rs->fetchArray(SQLITE3_ASSOC):$rs->fetch(PDO::FETCH_ASSOC)){$canon=[];foreach($row as $k=>$v){if($source&&$table==='login_limits'&&$k==='key')$k='rate_key';$canon[$k]=$v===null?null:(string)$v;}ksort($canon,SORT_STRING);hash_update($hash,json_encode($canon,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n");}$out[$table]=hash_final($hash);
    }return $out;
}

$args=[];foreach(array_slice($argv,1) as $arg){if(str_starts_with($arg,'--source='))$args['source']=substr($arg,9);elseif($arg==='--confirm-empty-target')$args['confirm']=true;else failImport('Usage: php ops/migrate-sqlite-to-mariadb.php --source=/private/ledger.sqlite --confirm-empty-target (MariaDB connection via LEDGER_DB_* environment)');}
if(empty($args['source'])||empty($args['confirm']))failImport('Explicit source path and --confirm-empty-target are required');
$sourcePath=realpath($args['source']);if(!$sourcePath||!is_file($sourcePath))failImport('Source SQLite file does not exist');
$source=new SQLite3($sourcePath,SQLITE3_OPEN_READONLY);$source->busyTimeout(10000);$source->exec('PRAGMA query_only=ON');
if((int)$source->querySingle('PRAGMA user_version')<8)failImport('Source schema must first be migrated to SQLite schema version 8 using the existing offline SQLite migration procedure');
$fk=$source->query('PRAGMA foreign_key_check');if($fk->fetchArray())failImport('Source SQLite database has a foreign-key violation');
if($source->querySingle('PRAGMA integrity_check')!=='ok')failImport('Source SQLite integrity check failed');validateFinancialSource($source);
$required=['agencies','users','accounts','entries','audit_log','login_limits','chat_presence','chat_messages','chat_threads'];$sourceTables=tableNames($source);foreach($required as $t)if(!in_array($t,$sourceTables,true))failImport("Source schema is missing $t");
$target=connectLedgerDatabase();$target->setAttribute(PDO::ATTR_TIMEOUT,10);installMariaSchema($target,false);upgradeMariaSchemaV2($target);$target->beginTransaction();$target->query('SELECT version FROM ledger_schema WHERE version=2 FOR UPDATE')->fetch();
$targetTables=$required;
foreach($targetTables as $t){$n=(int)$target->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn();if($n!==0)failImport("Target table $t is not empty; refusing overwrite");}
$source->exec('BEGIN');$beforeCounts=expectedCounts($source,$required);$beforeBalances=sourceBalances($source);
if(count($sourceTables)!==count($required))failImport('Source has unexpected application tables; review mapping before import');
try{
    foreach(['agencies','users','accounts','audit_log','login_limits','chat_presence','entries','chat_messages','chat_threads'] as $table){
        $srcCols=columns($source,$table);$dstCols=$table==='login_limits'?['rate_key','attempts','until_time']:array_map(static fn($c)=>$c==='key'?'rate_key':$c,$srcCols);
        $map=[];foreach($srcCols as $i=>$col){$dst=$col==='key'?'rate_key':$col;if(!in_array($dst,$dstCols,true))failImport("No MariaDB mapping for $table.$col");$map[]=$dst;}
        $read=$source->query('SELECT * FROM "'.str_replace('"','""',$table).'" ORDER BY '.(in_array('id',$srcCols,true)?'id':($table==='chat_threads'?'agency_id':'rowid')));
        $sql='INSERT INTO `'.$table.'` (`'.implode('`,`',$map).'`) VALUES ('.implode(',',array_fill(0,count($map),'?')).')';$insert=$target->prepare($sql);
        while($row=$read->fetchArray(SQLITE3_NUM)){$values=[];foreach($row as $value)$values[]=$value===false?null:$value;$insert->execute($values);}
    }
    $insideCounts=actualCounts($target,$required);$insideBalances=targetBalances($target);$insideHashes=rowHashes($target,$required,false);$beforeHashes=rowHashes($source,$required,true);
    if($beforeCounts!==$insideCounts)throw new RuntimeException('Pre-commit table row counts do not match');
    if($beforeBalances!==$insideBalances)throw new RuntimeException('Pre-commit per-account opening/debit/credit/closing balances do not match');
    if($beforeHashes!==$insideHashes)throw new RuntimeException('Pre-commit row hashes do not match; imported values differ');
    $target->commit();
}catch(Throwable $ex){if($target->inTransaction())$target->rollBack();try{$target->exec('SET FOREIGN_KEY_CHECKS=1');}catch(Throwable $ignore){}$source->close();failImport('Import rolled back: '.$ex->getMessage());}
$afterCounts=actualCounts($target,$required);$afterBalances=targetBalances($target);
if($beforeCounts!==$afterCounts)failImport('Import completed, but post-import table counts differ; keep source active and investigate');
if($beforeBalances!==$afterBalances)failImport('Import completed, but account opening/debit/credit/closing balances differ; keep source active and investigate');
$totalDebit=(int)$target->query('SELECT COALESCE(SUM(debit),0) FROM entries')->fetchColumn();$totalCredit=(int)$target->query('SELECT COALESCE(SUM(credit),0) FROM entries')->fetchColumn();
echo json_encode(['status'=>'PASS','source'=>$sourcePath,'source_unchanged'=>true,'counts'=>$afterCounts,'account_balances_verified'=>count($afterBalances),'entry_debit_minor_units'=>$totalDebit,'entry_credit_minor_units'=>$totalCredit],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
$source->close();
