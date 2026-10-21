<?php
declare(strict_types=1);
require_once __DIR__.'/hardening.php';
require_once __DIR__.'/runtime.php';
require_once __DIR__.'/database.php';
require_once __DIR__.'/schema-mariadb.php';
require_once __DIR__.'/mariadb-backup.php';
require_once __DIR__.'/accounting.php';
require_once __DIR__.'/ui.php';
ini_set('display_errors','0');
set_exception_handler(function(Throwable $ex):void{writeRollback();error_log((string)$ex);if(http_response_code()<400)http_response_code(500);header('Content-Type: text/plain; charset=UTF-8');echo 'Unable to complete this request. Please retry. If the problem continues, contact your administrator.';});
productionGuard(__DIR__);
date_default_timezone_set(getenv('LEDGER_TIMEZONE') ?: 'Asia/Karachi');
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
define('DBF', getenv('LEDGER_DB') ?: __DIR__.'/data/ledger.sqlite');
define('AUTH_WRAP', '<div class="auth-intro"><div class="auth-brand"><span class="brandmark big">'.uiIcon('plane').'</span><span class="brandname">MIZORA<small>Travel Ledger</small></span></div><h2>Simple. Secure.<br>Professional.</h2><p>Travel Agency Ledger System</p><div class="auth-features"><span>'.uiIcon('user').'Manage Agencies</span><span>'.uiIcon('wallet').'Track Payments</span><span>'.uiIcon('reports').'View Reports</span><span>'.uiIcon('shield').'Keep Records Safe</span></div><small class="auth-copy">© 2026 MIZORA. All rights reserved.</small></div><div class="auth-pane">');
session_name('LGR');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>productionMode()||(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')]);
session_start();
// Idle timeout: any authenticated activity refreshes the sliding window.
if(isset($_SESSION['u']) && time()-(int)($_SESSION['last_activity']??time())>1800){$_SESSION=[];session_regenerate_id(true);}
if(isset($_SESSION['u']))$_SESSION['last_activity']=time();
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self' data:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('Cache-Control: no-store');

/* ---------- core ---------- */
function db():PDO{static $p;if($p)return $p;
 $p=connectLedgerDatabase();
 if(ledgerDriver()==='mysql'){
  if(!productionMode()&&(getenv('LEDGER_AUTO_MIGRATE')==='1'||PHP_SAPI==='cli')){installMariaSchema($p);upgradeMariaSchemaV2($p);}
  try{$version=(int)$p->query('SELECT MAX(version) FROM ledger_schema')->fetchColumn();}catch(Throwable $ex){throw new RuntimeException('MariaDB schema is not installed; run the offline schema/import command');}
  if($version!==2)throw new RuntimeException('MariaDB schema version is unsupported; run the reviewed offline schema migration first');
  return $p;
 }
 $schema=(int)$p->query('PRAGMA user_version')->fetchColumn();
 if(productionMode()&&PHP_SAPI!=='cli'&&$schema<8){http_response_code(503);throw new RuntimeException('Production migration must run offline through the CLI');}
 if($schema>=8)return $p;
 if($schema>=7){migrateLedgerCurrency($p);return $p;}
 if($schema>=5){migrateChat($p);migrateChatSupport($p);migrateLedgerCurrency($p);return $p;}
 $p->exec('BEGIN IMMEDIATE');
 if((int)$p->query('PRAGMA user_version')->fetchColumn()>=5){$p->exec('COMMIT');migrateChat($p);migrateChatSupport($p);migrateLedgerCurrency($p);return $p;}
 if((int)$p->query("PRAGMA user_version")->fetchColumn()<4){
 $p->exec("CREATE TABLE IF NOT EXISTS agencies(id INTEGER PRIMARY KEY,name TEXT NOT NULL,phone TEXT,active INTEGER DEFAULT 1,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
 CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,agency_id INTEGER REFERENCES agencies(id),username TEXT UNIQUE NOT NULL,pass TEXT NOT NULL,role TEXT NOT NULL,fails INTEGER DEFAULT 0,locked_until INTEGER DEFAULT 0);
 CREATE TABLE IF NOT EXISTS accounts(id INTEGER PRIMARY KEY,agency_id INTEGER NOT NULL REFERENCES agencies(id),name TEXT NOT NULL,phone TEXT,kind TEXT DEFAULT 'customer',opening INTEGER DEFAULT 0);
 CREATE TABLE IF NOT EXISTS entries(id INTEGER PRIMARY KEY,agency_id INTEGER NOT NULL,account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,edate TEXT NOT NULL,note TEXT,ref TEXT,cat TEXT DEFAULT 'other',deleted INTEGER DEFAULT 0,debit INTEGER DEFAULT 0,credit INTEGER DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
 CREATE INDEX IF NOT EXISTS i_e ON entries(agency_id,account_id,edate,id);CREATE INDEX IF NOT EXISTS i_a ON accounts(agency_id);CREATE INDEX IF NOT EXISTS i_active_e ON entries(agency_id,account_id,deleted,edate,id);");
 $c=array_column($p->query('PRAGMA table_info(entries)')->fetchAll(),'name');
 if(!in_array('cat',$c))$p->exec("ALTER TABLE entries ADD COLUMN cat TEXT DEFAULT 'other'");
 if(!in_array('deleted',$c))$p->exec('ALTER TABLE entries ADD COLUMN deleted INTEGER DEFAULT 0');
 $ac=array_column($p->query('PRAGMA table_info(accounts)')->fetchAll(),'name');
 if(!in_array('archived',$ac))$p->exec('ALTER TABLE accounts ADD COLUMN archived INTEGER NOT NULL DEFAULT 0');
 $p->exec("CREATE TABLE IF NOT EXISTS audit_log(id INTEGER PRIMARY KEY,agency_id INTEGER,user_id INTEGER,action TEXT NOT NULL,entity TEXT,entity_id INTEGER,details TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP); CREATE INDEX IF NOT EXISTS audit_agency ON audit_log(agency_id,created_at); CREATE INDEX IF NOT EXISTS i_e_report ON entries(agency_id,deleted,edate,cat);");
 $p->exec("PRAGMA user_version=4");
 }
 $uc=array_column($p->query('PRAGMA table_info(users)')->fetchAll(),'name');
 if(!in_array('display_name',$uc,true))$p->exec('ALTER TABLE users ADD COLUMN display_name TEXT');
 if(!in_array('phone',$uc,true))$p->exec('ALTER TABLE users ADD COLUMN phone TEXT');
 if(!in_array('session_version',$uc,true))$p->exec('ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 0');
 $ec=array_column($p->query('PRAGMA table_info(entries)')->fetchAll(),'name');
 if(!in_array('bill_id',$ec,true))$p->exec('ALTER TABLE entries ADD COLUMN bill_id INTEGER REFERENCES entries(id)');
 $p->exec('CREATE INDEX IF NOT EXISTS i_bill_payments ON entries(agency_id,bill_id,deleted)');
 if(!in_array('linked_entry_id',$ec,true))$p->exec('ALTER TABLE entries ADD COLUMN linked_entry_id INTEGER REFERENCES entries(id)');
 $p->exec('CREATE INDEX IF NOT EXISTS i_entry_links ON entries(agency_id,account_id,linked_entry_id,deleted)');
 $p->exec('COMMIT');migrateIntegrity($p);migrateChat($p);migrateChatSupport($p);migrateLedgerCurrency($p);return $p;}
function q(string $s,array $a=[]){$st=db()->prepare(ledgerSql($s));$st->execute($a);return $st;}
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function m(int $v){$parts=explode('.',moneyDecimal(abs($v)));return ($v<0?'-':'').preg_replace('/\B(?=(\d{3})+(?!\d))/',',',$parts[0]).'.'.$parts[1];}
function cents($v){$v=trim((string)$v);if(str_contains($v,',')&&!preg_match('/^-?\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/',$v))throw new InvalidArgumentException('Invalid amount');$v=str_replace(',','',$v);if(!preg_match('/^-?\d{1,11}(?:\.\d{1,2})?$/',$v))throw new InvalidArgumentException('Invalid amount');$neg=str_starts_with($v,'-');$v=ltrim($v,'-');$a=explode('.',$v,2);$c=((int)$a[0])*100+(int)str_pad($a[1]??'',2,'0');return $neg?-$c:$c;}
function tok(){return $_SESSION['t']??=bin2hex(random_bytes(16));}
function chk(){if(!hash_equals(tok(),(string)($_POST['t']??''))){http_response_code(419);exit('Session expired. Refresh the page and try again.');}}
function go($u){header('Location: '.$u);exit;}
function me(){return $_SESSION['u']??null;}
function flash($m=null){if($m!==null){$_SESSION['f']=$m;return;}$f=$_SESSION['f']??'';unset($_SESSION['f']);return $f;}
function audit(string $action,string $entity,int $id,string $details=''):void{$u=me();q('INSERT INTO audit_log(agency_id,user_id,action,entity,entity_id,details) VALUES(?,?,?,?,?,?)',[$u['aid']??null,$u['id']??null,$action,$entity,$id,$details]);}
function validDate(string $v):bool{$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);return $d!==false&&$d->format('Y-m-d')===$v;}
function post($k){return trim((string)($_POST[$k]??''));}
function T(){return requestKey().'<input type="hidden" name="t" value="'.tok().'">';}
// One familiar Dr/Cr ledger. Categories reflect the actual transaction, not just the column.
const DEBIT_CATS=['ticket_sold'=>'Air Ticket Sold','umrah_sold'=>'Umrah / Hajj Sold','visa_sold'=>'Visa Sold','hotel_sold'=>'Hotel Sold','transport_sold'=>'Transport Sold','tour_sold'=>'Tour Package Sold','commission_earned'=>'Commission Earned','cash_transfer_in'=>'Cash / Bank Transfer In','payment_paid'=>'Payment Paid','cash_paid'=>'Cash Paid','bank_paid'=>'Bank Transfer Paid','other_debit'=>'Other Debit'];
const CREDIT_CATS=['ticket_bought'=>'Air Ticket Purchased','umrah_bought'=>'Umrah / Hajj Purchased','visa_bought'=>'Visa Purchased','hotel_bought'=>'Hotel Purchased','transport_bought'=>'Transport Purchased','tour_bought'=>'Tour Package Purchased','payment_received'=>'Payment Received','cash_received'=>'Cash Received','bank_received'=>'Bank Transfer Received','cash_transfer_out'=>'Cash / Bank Transfer Out','expense_paid'=>'Expense Paid','ticket_refund'=>'Ticket Refund','travel_refund'=>'Visa / Umrah Refund','adjustment'=>'Adjustment','other_credit'=>'Other Credit'];
// Preserve labels for old database rows and old exports.
const CATS=DEBIT_CATS+CREDIT_CATS+['ticket'=>'Air Ticket','umrah'=>'Umrah / Hajj','visa'=>'Visa','hotel'=>'Hotel','transport'=>'Transport','tour'=>'Tour Package','cash'=>'Cash / Payment','other'=>'Other'];
const DR_PAY=['payment_paid','cash_paid','bank_paid'];
const CR_PAY=['payment_received','cash_received','bank_received'];
if(PHP_SAPI==='cli'&&defined('LEDGER_MAINTENANCE_BOOTSTRAP')){db();return;}
function isPaymentCat(string $cat):bool{return in_array($cat,DR_PAY,true)||in_array($cat,CR_PAY,true);}
function isRefundCat(string $cat):bool{return in_array($cat,['ticket_refund','travel_refund'],true);}
function remainingSql(string $alias='x'):string{
 return "MAX($alias.debit,$alias.credit)-COALESCE((SELECT SUM(p.debit+p.credit) FROM entries p WHERE p.agency_id=$alias.agency_id AND p.account_id=$alias.account_id AND p.linked_entry_id=$alias.id AND p.deleted=0),0)-COALESCE((SELECT SUM(p.debit+p.credit) FROM entries p WHERE p.agency_id=$alias.agency_id AND p.bill_id=$alias.id AND p.account_id=$alias.account_id AND p.deleted=0 AND (p.linked_entry_id IS NULL OR p.linked_entry_id!=p.bill_id)),0)";
}
function pager(int $tot,int $per,int $pg,string $base){
 $n=max(1,(int)ceil($tot/$per));$first=$tot?($pg-1)*$per+1:0;$last=min($tot,$pg*$per);
 $link=static fn(int $v,string $label)=>'<a href="'.e($base).'&pg='.$v.'">'.$label.'</a>';
 $out='<nav class="pager" aria-label="Pagination"><span class="pager-count">Showing '.$first.'–'.$last.' of '.$tot.'</span><div class="pager-links">';
 $out.=$pg>1?$link($pg-1,'‹ Previous'):'<span aria-disabled="true">‹ Previous</span>';
 $pages=array_unique(array_merge([1],range(max(1,$pg-1),min($n,$pg+1)),[$n]));$prev=0;
 foreach($pages as $v){if($prev&&$v>$prev+1)$out.='<span>…</span>';$out.=$v===$pg?'<span class="current" aria-current="page">'.$v.'</span>':$link($v,(string)$v);$prev=$v;}
 $out.=$pg<$n?$link($pg+1,'Next ›'):'<span aria-disabled="true">Next ›</span>';
 return $out.'</div></nav>';
}
// Rows per page: user selectable, default 10.
const PER_OPTS=[10,25,50,100];
function perPage():int{$v=(int)($_GET['per']??10);return in_array($v,PER_OPTS,true)?$v:10;}
function perSwitch(int $per,string $page,array $keep=[]):string{$hidden='';foreach($keep as $k=>$v)if($v!==''&&$v!==null)$hidden.='<input type="hidden" name="'.e((string)$k).'" value="'.e((string)$v).'">';$o='<form class="per-form" method="get"><input type="hidden" name="p" value="'.e($page).'">'.$hidden.'<label>Show <select name="per" aria-label="Rows per page">';foreach(PER_OPTS as $n)$o.='<option value="'.$n.'"'.($n===$per?' selected':'').'>'.$n.'</option>';return $o.'</select> rows</label></form>';}
function dc(int $b){return 'PKR '.m(abs($b)).($b>0?' Dr':($b<0?' Cr':' —'));}

/* ---------- first-run: master setup ---------- */
$p=$_GET['p']??'';
if(!(int)q("SELECT EXISTS(SELECT 1 FROM users WHERE role='master')")->fetchColumn()){
 if(productionMode()){http_response_code(503);throw new RuntimeException('Production requires a provisioned administrator; web setup is disabled');}
 $err='';
 if($_SERVER['REQUEST_METHOD']==='POST'){chk();$un=strtolower(post('username'));$pw=(string)($_POST['password']??'');
  if(!preg_match('/^[a-z0-9_.]{3,30}$/',$un)||(strlen($pw)<12||strlen($pw)>72))$err='Use a username of 3–30 characters and a password of 12–72 characters.';
  else{ledgerBegin(db(),true);if(!(int)q("SELECT EXISTS(SELECT 1 FROM users WHERE role='master')")->fetchColumn()){q("INSERT INTO users(username,pass,role) VALUES(?,?, 'master')",[$un,password_hash($pw,PASSWORD_DEFAULT)]);}ledgerCommit(db());flash('Master account ready. Please sign in.');go('?p=login');}}
 page('Setup',AUTH_WRAP.'<div class="card auth-card"><span class="auth-logo">'.uiIcon('plane').'</span><h1>Master Setup</h1><p class="muted">Create your master administrator account to get started.</p>'.($err?'<div class="err">'.e($err).'</div>':'').'<form method="post" novalidate data-validate>'.T().'<label>Username<input name="username" required autofocus autocomplete="username" placeholder="e.g. mizora.admin" pattern="[a-zA-Z0-9_.]{3,30}" title="3-30 characters: letters, numbers, dot or underscore"><small class="field-hint">3–30 characters · letters, numbers, dot or underscore</small></label><label>Password (12+ characters)<input type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password" placeholder="Minimum 12 characters"><small class="field-hint">12–72 characters · use uppercase, lowercase and a number</small></label><button class="btn">Create Master</button></form></div></div>',false);}

/* ---------- auth ---------- */
if($p==='logout'&&$_SERVER['REQUEST_METHOD']==='POST'){chk();if((me()['role']??'')==='master')q('UPDATE chat_presence SET last_seen=0 WHERE id=1');$_SESSION=[];session_regenerate_id(true);go('?p=login');}
if(!me()){
 $err='';
 if($_SERVER['REQUEST_METHOD']==='POST'){chk();
  $rateKey=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'local'));
  $limit=q(ledgerDriver()==='mysql'?'SELECT * FROM login_limits WHERE rate_key=?':'SELECT * FROM login_limits WHERE key=?',[$rateKey])->fetch();
  if($limit && (int)$limit['until_time']>time() && (int)$limit['attempts']>=30){http_response_code(429);page('Sign in',AUTH_WRAP.'<div class="card auth-card"><span class="auth-logo">'.uiIcon('lock').'</span><h1>Please wait</h1><p class="muted">Too many sign-in attempts. Try again in 5 minutes.</p></div></div>',false);}
  q(ledgerDriver()==='mysql'?'INSERT INTO login_limits(rate_key,attempts,until_time) VALUES(?,1,?) ON DUPLICATE KEY UPDATE attempts=IF(until_time<=?,1,attempts+1),until_time=IF(until_time<=?,VALUES(until_time),until_time)':'INSERT INTO login_limits(key,attempts,until_time) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET attempts=CASE WHEN until_time<=? THEN 1 ELSE attempts+1 END,until_time=CASE WHEN until_time<=? THEN excluded.until_time ELSE until_time END',[$rateKey,time()+300,time(),time()]);
  $r=q("SELECT u.*,a.active aa,a.name an FROM users u LEFT JOIN agencies a ON a.id=u.agency_id WHERE u.username=?",[strtolower(post('username'))])->fetch();
  if($r&&$r['locked_until']>time())$err='Too many attempts. Please try again in 5 minutes.';
  elseif($r&&password_verify((string)($_POST['password']??''),$r['pass'])&&($r['role']==='master'||$r['aa'])){
   q("UPDATE users SET fails=0,locked_until=0 WHERE id=?",[$r['id']]);session_regenerate_id(true);
   $_SESSION['u']=['id'=>$r['id'],'role'=>$r['role'],'aid'=>$r['agency_id'],'name'=>$r['display_name']?:$r['username'],'an'=>$r['an'],'sv'=>(int)$r['session_version']];
   unset($_SESSION['t']);audit('login','user',(int)$r['id']);
   go($r['role']==='master'?'?p=agencies':'?p=home');}
  else{if($r){q('UPDATE users SET fails=CASE WHEN fails>=4 THEN 0 ELSE fails+1 END,locked_until=CASE WHEN fails>=4 THEN ? ELSE 0 END WHERE id=?',[time()+300,$r['id']]);}
   else password_verify((string)($_POST['password']??''), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
   $err='Incorrect credentials or inactive agency.';}}
 page('Login',AUTH_WRAP.'<div class="card auth-card"><span class="auth-logo">'.uiIcon('plane').'</span><h1>Welcome Back</h1><p class="muted">Sign in to MIZORA to continue</p>'.($err?'<div class="err">'.e($err).'</div>':'').'<form method="post" novalidate data-validate>'.T().'<label>Username<input name="username" required autofocus autocomplete="username" placeholder="Enter your username"><small class="field-hint">Your username is provided by your administrator</small></label><label>Password<input type="password" name="password" required autocomplete="current-password" placeholder="Enter your password"><small class="field-hint">Passwords are case sensitive</small></label><div class="auth-row"><span class="muted">Contact your administrator for access</span></div><button class="btn">Sign In '.uiIcon('plane').'</button></form></div></div>',false);}

$U=me();
// Recheck session identity and agency status on EVERY request, including exports.
$live=q('SELECT u.id,u.role,u.agency_id,u.session_version,a.active FROM users u LEFT JOIN agencies a ON a.id=u.agency_id WHERE u.id=?',[(int)$U['id']])->fetch();
if(!$live||$live['role']!==$U['role']||(int)($live['agency_id']??0)!==(int)($U['aid']??0)||(int)$live['session_version']!==(int)($U['sv']??0)||($U['role']!=='master'&&(int)$live['active']!==1)){$_SESSION=[];session_regenerate_id(true);flash('Your session expired or your agency is inactive. Please sign in again.');go('?p=login');}
$post=$_SERVER['REQUEST_METHOD']==='POST';$pg=min(100000,max(1,(int)($_GET['pg']??1)));

/* ---------- USER PROFILE / PASSWORD ---------- */
if($p==='chat'){
 header('Content-Type: application/json; charset=UTF-8');
 $master=$U['role']==='master';$aid=$master?(int)($_GET['agency']??0):(int)$U['aid'];
 $respond=static function(array $data,int $status=200):void{http_response_code($status);echo json_encode($data,JSON_INVALID_UTF8_SUBSTITUTE);exit;};
 if($post&&($_POST['action']??'')==='availability'){
  chk();if(!$master)$respond(['error'=>'Only the master admin can change availability.'],403);
  $mode=(string)($_POST['mode']??'');if(!in_array($mode,['available','away'],true))$respond(['error'=>'Choose Available or Away.'],422);
  q('UPDATE chat_presence SET mode=?,last_seen=? WHERE id=1',[$mode,$mode==='available'?time():0]);$respond(['availability'=>chatAvailability()]);
 }
 if($aid&& !q('SELECT id FROM agencies WHERE id=?',[$aid])->fetch())$respond(['error'=>'Conversation unavailable.'],404);
 if($post){chk();if(!$aid)$respond(['error'=>'Choose an agency first.'],422);
  $body=trim((string)($_POST['message']??''));$key=(string)($_POST['key']??'');
  if($body===''||strlen($body)>8000||!preg_match('//u',$body)||preg_match_all('/./us',$body)>2000||!preg_match('/^[a-zA-Z0-9-]{16,80}$/',$key))$respond(['error'=>'Enter a message of up to 2,000 characters.'],422);
  ledgerBegin(db(),true);
  if(ledgerDriver()==='mysql')q('SELECT id FROM users WHERE id=? FOR UPDATE',[(int)$U['id']])->fetch();
  $existing=q('SELECT id,agency_id,body FROM chat_messages WHERE sender_id=? AND request_key=?',[$U['id'],$key])->fetch();
  if($existing&&((int)$existing['agency_id']!==$aid||$existing['body']!==$body)){ledgerRollback(db());$respond(['error'=>'This send request belongs to another message. Please retry.'],409);}
  if(!$existing){
   $recent=q("SELECT COUNT(*) FROM chat_messages WHERE sender_id=? AND is_auto=0 AND created_at>=datetime('now','-1 minute')",[$U['id']])->fetchColumn();
   if((int)$recent>=30){ledgerRollback(db());$respond(['error'=>'Please wait a moment before sending another message.'],429);}
   q('INSERT INTO chat_messages(agency_id,sender_id,from_master,body,request_key) VALUES(?,?,?,?,?)',[$aid,$U['id'],$master?1:0,$body,$key]);$messageId=(int)db()->lastInsertId();
   if(ledgerDriver()==='mysql')recordMariaChatMessage($aid,$messageId,$master?1:0);
   if($master)q("UPDATE chat_threads SET human_until=?,waiting=0,bot_topic='',bot_count=0 WHERE agency_id=?",[time()+900,$aid]);
   else chatAssistantReply($aid,$body,$messageId);
  }
  ledgerCommit(db());$respond(['ok'=>true,'id'=>$existing?(int)$existing['id']:$messageId]);
 }
 if($_SERVER['REQUEST_METHOD']!=='GET')$respond(['error'=>'Method unavailable.'],405);
 session_write_close();
 if($master)q("UPDATE chat_presence SET last_seen=? WHERE id=1 AND mode='available' AND last_seen<?",[time(),time()-20]);
 $availability=chatAvailability($aid);$badge=(int)q($master?'SELECT COALESCE(SUM(unread_master),0) FROM chat_threads':'SELECT COALESCE(unread_agent,0) FROM chat_threads WHERE agency_id=?',$master?[]:[$aid])->fetchColumn();
 $threads=[];$threadMore=false;$threadCursor=null;
 if($master&&!isset($_GET['badge'])&&($_GET['threads']??'1')!=='0'){
  $search=substr(trim((string)($_GET['search']??'')),0,100);$args=[];$where='(a.active=1 OR t.last_id>0)';
  if($search!==''){$where.=" AND a.name LIKE ? ESCAPE '\\'";$args[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$search).'%';}
  if(isset($_GET['thread_id'],$_GET['thread_last'])){$where.=' AND (COALESCE(t.last_id,0),a.id)<(CAST(? AS INTEGER),CAST(? AS INTEGER))';$args[]=max(0,(int)$_GET['thread_last']);$args[]=max(0,(int)$_GET['thread_id']);}
  $threads=q("SELECT a.id,a.name,a.active,COALESCE(t.last_id,0) last_id,COALESCE(t.unread_master,0) unread,COALESCE(t.waiting,0) waiting,substr(m.body,1,140) preview FROM agencies a LEFT JOIN chat_threads t ON t.agency_id=a.id LEFT JOIN chat_messages m ON m.id=t.last_id WHERE $where ORDER BY COALESCE(t.last_id,0) DESC,a.id DESC LIMIT 51",$args)->fetchAll();
  $threadMore=count($threads)>50;if($threadMore)array_pop($threads);if($threads){$last=end($threads);$threadCursor=['id'=>(int)$last['id'],'last'=>(int)$last['last_id']];}
 }
 $messages=[];$hasOlder=null;$hasMore=false;
 if($aid&&!isset($_GET['badge'])&&($_GET['messages']??'1')!=='0'){
  $before=max(0,(int)($_GET['before']??0));$after=max(0,(int)($_GET['after']??0));if($before&&$after)$respond(['error'=>'Choose one message cursor.'],422);
  $args=[$aid];$cursor='';if($before||$after){$cursor=$before?' AND m.id<?':' AND m.id>?';$args[]=$before?:$after;}
  $messages=q('SELECT m.id,m.body,m.created_at,m.from_master,m.is_auto,u.display_name,u.username FROM chat_messages m JOIN users u ON u.id=m.sender_id WHERE m.agency_id=?'.$cursor.' ORDER BY m.id '.($after?'ASC':'DESC').' LIMIT 101',$args)->fetchAll();
  $extra=count($messages)>100;if($extra)array_pop($messages);if($after)$hasMore=$extra;else{$hasOlder=$extra;$messages=array_reverse($messages);}
 }
 session_write_close();$respond(['unread'=>$badge,'threads'=>$threads,'thread_more'=>$threadMore,'thread_cursor'=>$threadCursor,'messages'=>$messages,'has_older'=>$hasOlder,'has_more'=>$hasMore,'availability'=>$availability,'agency'=>$aid]);
}
if($p==='chat-read'&&$post){chk();$master=$U['role']==='master';$aid=$master?(int)($_POST['agency']??0):(int)$U['aid'];$through=max(0,(int)($_POST['through']??0));if(ledgerDriver()==='mysql'){ledgerBegin(db(),true);$st=q('UPDATE chat_messages SET read_at=CURRENT_TIMESTAMP WHERE agency_id=? AND from_master=? AND read_at IS NULL AND id<=?',[$aid,$master?0:1,$through]);$count=$st->rowCount();if($count>0){$col=$master?'unread_master':'unread_agent';q('UPDATE chat_threads SET '.$col.'=GREATEST(0,'.$col.'-?) WHERE agency_id=?',[$count,$aid]);}ledgerCommit(db());}else q('UPDATE chat_messages SET read_at=CURRENT_TIMESTAMP WHERE agency_id=? AND from_master=? AND read_at IS NULL AND id<=?',[$aid,$master?0:1,$through]);header('Content-Type: application/json');echo '{"ok":true}';exit;}
if($p==='profile'){
 $user=q('SELECT id,username,display_name,phone,pass FROM users WHERE id=?',[(int)$U['id']])->fetch();
 if($post){chk();$action=post('act');
  if($action==='profile'){
   $name=post('display_name');$phone=post('phone');
   if(strlen($name)<2||strlen($name)>90||strlen($phone)>35)flash('Use a name of 2–90 characters and a phone number of up to 35 characters.');
   else{q('UPDATE users SET display_name=?,phone=? WHERE id=?',[$name,$phone,$U['id']]);$_SESSION['u']['name']=$name;audit('update','profile',(int)$U['id']);flash('Profile successfully updated.');}
  }elseif($action==='password'){
  $current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');$repeat=(string)($_POST['confirm_password']??'');
  if($current==='')flash('Enter your current password to continue.');
  elseif(!password_verify($current,$user['pass']))flash('Current password is incorrect. Please check and try again.');
  elseif($new==='')flash('Enter a new password.');
  elseif(strlen($new)<12)flash('New password is too short. Use at least 12 characters.');
  elseif(strlen($new)>72)flash('New password is too long. Use at most 72 characters.');
  elseif(!preg_match('/[A-Z]/',$new)||!preg_match('/[a-z]/',$new)||!preg_match('/[0-9]/',$new))flash('New password must include uppercase, lowercase and a number.');
  elseif(!hash_equals($new,$repeat))flash('New passwords do not match. Please retype the confirmation.');
  elseif(password_verify($new,$user['pass']))flash('New password must be different from your current password.');
   else{q('UPDATE users SET pass=?,fails=0,locked_until=0,session_version=session_version+1 WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),$U['id']]);$_SESSION['u']['sv']++;session_regenerate_id(true);audit('password_change','user',(int)$U['id']);flash('Password changed. Other sessions have been signed out.');}
  }go('?p=profile');
 }
 $h='<div class="page-head"><div><span class="crumb">Settings</span><h1>Profile &amp; Settings</h1><p class="muted">Manage your account and preferences.</p></div></div><div class="settings-layout"><div class="card side-menu" aria-label="Settings sections"><a class="sm-item active" href="?p=profile">'.uiIcon('user').'<span>Profile</span></a><a class="sm-item" href="#password">'.uiIcon('lock').'<span>Change Password</span></a></div><div class="settings-main"><div class="card"><div class="panel-head"><div><h3>Account Information</h3><p class="muted">Your identity inside the MIZORA workspace.</p></div></div><div class="account-id"><span class="avatar avatar-lg">'.e(strtoupper(substr(($user['display_name']?:$user['username'])??'M',0,1))).'</span><span><b>'.e($user['display_name']?:$user['username']).'</b><br><small>'.($U['role']==='master'?'Master Account':e($U['an']?:'Agency Account')).'</small></span><span class="chip chip-teal">'.($U['role']==='master'?'MASTER ADMIN':'AGENCY OWNER').'</span></div><form method="post" class="form-grid" novalidate data-validate>'.T().'<input type="hidden" name="act" value="profile"><label>Full name<input name="display_name" value="'.e($user['display_name']?:$user['username']).'" minlength="2" maxlength="90" required autocomplete="name" placeholder="Your full name"><small class="field-hint">2–90 characters</small></label><label>Username<input value="'.e($user['username']).'" disabled aria-label="Username"></label><label>Phone number<input name="phone" value="'.e($user['phone']).'" maxlength="35" autocomplete="tel" placeholder="+92 300 0000000"><small class="field-hint">Optional · include country code</small></label><label>Role<input value="'.($U['role']==='master'?'Master Admin':'Agency Owner').'" disabled aria-label="Role"></label><label>Account status<input value="Active" disabled aria-label="Account status"></label><div class="form-actions"><button class="btn btn-inline">'.uiIcon('check').' Update Profile</button><small class="muted">'.date('d M Y h:i A').'</small></div></form></div><div class="card" id="password"><div class="panel-head"><div><h3>Change Password</h3><p class="muted">Use at least 12 characters. Keep your password private.</p></div></div><form method="post" class="form-grid" novalidate data-validate>'.T().'<input type="hidden" name="act" value="password"><label>Current password<input type="password" name="current_password" autocomplete="current-password" required placeholder="Enter your current password"><small class="field-hint">Required to confirm this change</small></label><label>New password<input type="password" name="new_password" minlength="12" maxlength="72" autocomplete="new-password" required placeholder="Minimum 12 characters"><small class="field-hint">12–72 characters · uppercase, lowercase and a number</small></label><label>Confirm new password<input type="password" name="confirm_password" minlength="12" maxlength="72" autocomplete="new-password" required placeholder="Repeat the new password"><small class="field-hint">Must match the new password exactly</small></label><div class="form-actions"><button class="btn btn-inline" data-confirm="Change your password? Other signed-in sessions will be logged out.">'.uiIcon('lock').' Update Password</button></div></form></div></div></div>';
 page('My Profile',$h);
}

/* ---------- MASTER ---------- */
if($U['role']==='master'){
 if($p==='backup'){
  if(isset($_GET['download'])){
    if(ledgerDriver()==='mysql'){
     $tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'mizora-mariadb-backup-'.bin2hex(random_bytes(16)).'.sql';
     try{session_write_close();$info=writeMariaBackup(db(),$tmp);audit('backup','database',0,'MariaDB logical SQL snapshot downloaded');header('Content-Type: application/sql; charset=UTF-8');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="mizora-ledger-'.date('Ymd-His').'.sql"');header('Content-Length: '.$info['bytes']);readfile($tmp);}finally{@unlink($tmp);}exit;
    }
   $tmp=tempnam(sys_get_temp_dir(),'mizora-backup-');if($tmp===false)exit('Backup unavailable');@unlink($tmp);
   try{session_write_close();db()->exec('VACUUM INTO '.db()->quote($tmp));$snapshot=new PDO('sqlite:'.$tmp);if($snapshot->query('PRAGMA integrity_check')->fetchColumn()!=='ok'||$snapshot->query('PRAGMA foreign_key_check')->fetchAll())throw new RuntimeException('Backup verification failed');$snapshot=null;audit('backup','database',0,'Full database snapshot downloaded');header('Content-Type: application/octet-stream');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="ledger-'.date('Ymd-His').'.sqlite"');header('Content-Length: '.filesize($tmp));readfile($tmp);}finally{@unlink($tmp);}exit;}
   $path=ledgerDriver()==='mysql'?(string)getenv('LEDGER_DB_NAME'):DBF;$exists=ledgerDriver()==='mysql'||is_file($path);$bytes=ledgerDriver()==='mysql'?(int)q('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn():($exists?(int)filesize($path):0);$writable=ledgerDriver()==='mysql'?true:($exists?is_writable($path):is_writable(dirname($path)));
   $stat=ledgerDriver()==='mysql'?null:($exists?@stat($path):null);
  $tables=['agencies'=>'Agencies','users'=>'Users','accounts'=>'Travel Ledgers','entries'=>'Ledger Entries','audit_log'=>'Audit Log'];$counts=[];
  foreach(array_keys($tables) as $tb)$counts[$tb]=(int)q('SELECT COUNT(*) FROM '.$tb)->fetchColumn();
  $last=q('SELECT action,entity,created_at FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
  $size=$bytes>=1048576?number_format($bytes/1048576,2).' MB':($bytes>=1024?number_format($bytes/1024,1).' KB':$bytes.' B');
  $h='<div class="page-head"><div><h1>Backup &amp; Restore</h1><p class="muted">Download a complete, verified snapshot of the entire MIZORA database.</p></div><a class="btn btn-inline" href="?p=backup&download=1" data-download>'.uiIcon('download').' Download Backup</a></div>'.
  statCards([['backup','Database Size',$size,'teal'],['ledger','Ledger Entries',number_format($counts['entries']),'green'],['agencies','Agencies',number_format($counts['agencies']),'violet'],['user','User Accounts',number_format($counts['users']),'orange']]).
   '<div class="grid2 cards-grid"><div class="card"><div class="panel-head"><div><h3>Create Backup</h3><p class="muted">A consistent copy is produced online; no downtime and no partial files.</p></div></div><ul class="backup-steps"><li><b>1. Snapshot</b><span>'.(ledgerDriver()==='mysql'?'MariaDB InnoDB consistent snapshot writes a logical SQL backup.':'SQLite <code>VACUUM INTO</code> writes a fully consistent copy while the ledger keeps working.').'</span></li><li><b>2. Verify</b><span>'.(ledgerDriver()==='mysql'?'The export is read from a consistent database snapshot; restore it into a separate empty MariaDB database and compare record counts and balances.':'The snapshot passes an integrity check and foreign-key validation before it leaves the server.').'</span></li><li><b>3. Download</b><span>'.(ledgerDriver()==='mysql'?'You receive a <code>.sql</code> file containing the MariaDB schema and records.':'You receive a single <code>.sqlite</code> file containing every agency, ledger and entry.').'</span></li></ul><p class="muted backup-note">Keep the file somewhere private. It contains all financial records and password hashes.</p></div>'.
  '<div class="card table-card"><div class="panel-head"><div><h3>Database Status</h3><p class="muted">Current storage and contents on this server.</p></div></div><div class="tw"><table><tbody>'.
  '<tr><td>Storage file</td><td class="r"><code>'.e(basename($path)).'</code></td></tr>'.
  '<tr><td>Status</td><td class="r"><span class="status-dot '.($exists&&$writable?'on':'off').'">'.($exists?($writable?'Ready &amp; writable':'Read-only — check permissions'):'Not created yet').'</span></td></tr>'.
  '<tr><td>Size on disk</td><td class="r"><b>'.$size.'</b></td></tr>'.
  '<tr><td>Last modified</td><td class="r">'.($stat?e(date('d M Y h:i A',(int)$stat['mtime'])):'—').'</td></tr>'.
  '<tr><td>Last recorded activity</td><td class="r">'.($last?e(ucfirst((string)$last['action']).' · '.$last['entity'].' · '.$last['created_at']):'—').'</td></tr>'.
  '</tbody></table></div></div></div>'.
  '<div class="card table-card" style="margin-top:16px"><div class="panel-head"><div><h3>What This Backup Contains</h3><p class="muted">Every table is copied exactly — nothing is filtered or summarised.</p></div></div><div class="tw"><table><thead><tr><th>Contents</th><th class="r">Records</th><th class="r">Included</th></tr></thead><tbody>';
  foreach($tables as $tb=>$label)$h.='<tr><td>'.$label.' <small class="muted">('.$tb.')</small></td><td class="r"><b>'.number_format($counts[$tb]).'</b></td><td class="r"><span class="chip chip-green">Full</span></td></tr>';
  $h.='</tbody></table></div><div class="backup-restore"><h4>Restoring a backup</h4><p>To restore, stop the web service, replace the database file with the downloaded snapshot, confirm ownership and permissions, then start the service again. Financial history is append-only, so a restore never rewrites existing entries.</p></div></div>';
  page('Backup',$h);
  exit;
 }
 if($post){chk();$a=post('act');
  if($a==='new'){$un=strtolower(post('username'));$pw=(string)($_POST['password']??'');
   if(post('name')===''||!preg_match('/^[a-z0-9_.]{3,30}$/',$un)||(strlen($pw)<12||strlen($pw)>72))flash('Enter an agency name, valid username and password of 12–72 characters.');
   elseif(q("SELECT 1 FROM users WHERE username=?",[$un])->fetch())flash('This username is already in use.');
   else{db()->beginTransaction();q("INSERT INTO agencies(name,phone) VALUES(?,?)",[post('name'),post('phone')]);$id=(int)db()->lastInsertId();
    q("INSERT INTO users(agency_id,username,pass,role) VALUES(?,?,?,'owner')",[$id,$un,password_hash($pw,PASSWORD_DEFAULT)]);db()->commit();audit('create','agency',$id);flash('Agency created.');}}
  if($a==='toggle'){q("UPDATE agencies SET active=1-active WHERE id=?",[(int)$_POST['id']]);audit('toggle','agency',(int)$_POST['id']);flash('Agency status updated.');}
  if($a==='reset'&&strlen((string)($_POST['password']??''))>=12&&strlen((string)$_POST['password'])<=72){q("UPDATE users SET pass=?,fails=0,locked_until=0,session_version=session_version+1 WHERE agency_id=? AND role='owner'",[password_hash((string)$_POST['password'],PASSWORD_DEFAULT),(int)$_POST['id']]);audit('password_reset','agency',(int)$_POST['id']);flash('Owner password reset. Other sessions have been signed out.');}
  go('?p=agencies');}
 $s=trim((string)($_GET['s']??''));$per=perPage();
 $tot=(int)q("SELECT COUNT(*) FROM agencies WHERE name LIKE ?",['%'.$s.'%'])->fetchColumn();
 $pg=min($pg,max(1,(int)ceil($tot/$per)));
 $rows=q("SELECT a.*,(SELECT username FROM users WHERE agency_id=a.id AND role='owner' LIMIT 1) owner,(SELECT COUNT(*) FROM accounts WHERE agency_id=a.id) accs,(SELECT COUNT(*) FROM entries WHERE agency_id=a.id) ents FROM agencies a WHERE a.name LIKE ? ORDER BY a.id DESC LIMIT $per OFFSET ".(($pg-1)*$per),['%'.$s.'%'])->fetchAll();
 $act=(int)q('SELECT COUNT(*) FROM agencies WHERE active=1 AND name LIKE ?',['%'.$s.'%'])->fetchColumn();$blk=$tot-$act;
 $h='<div class="page-head"><div><h1>Travel Agencies</h1><p class="muted">Manage your travel agency partners.</p></div><button type="button" class="btn btn-inline" data-scroll="#new-agency">'.uiIcon('plus').' Add Agency</button></div>'.statCards([['agencies','Total Agencies',$tot,'teal'],['ledger','Active',$act,'green'],['alert','Suspended',$blk,'orange']]);
 $h.='<div class="grid2 cards-grid"><div class="card" id="new-agency"><div class="panel-head"><h3>New Agency</h3></div><form method="post">'.T().'<input type="hidden" name="act" value="new"><label>Agency Name<input name="name" required maxlength="180" placeholder="e.g. Skyline Travels"><small class="field-hint">Shown on every statement and report</small></label><label>Phone<input name="phone" maxlength="35" placeholder="+92 300 0000000"><small class="field-hint">Optional contact number</small></label><label>Owner Username<input name="username" required pattern="[a-zA-Z0-9_.]{3,30}" maxlength="30" placeholder="e.g. skyline.owner"><small class="field-hint">3–30 characters · letters, numbers, dot or underscore</small></label><label>Owner Password (12+)<input name="password" type="password" minlength="12" maxlength="72" required autocomplete="new-password" placeholder="Minimum 12 characters"><small class="field-hint">12–72 characters · share securely with the agency</small></label><button class="btn">Create Agency</button></form></div><div class="card table-card"><div class="panel-head"><div><h3>Agencies List</h3><p class="muted">All partner agencies on this system.</p></div><form class="inline-search"><input type="hidden" name="p" value="agencies"><input type="hidden" name="per" value="'.$per.'"><input class="search" type="search" name="s" placeholder="Search agency…" value="'.e($s).'"></form><div class="listing-tools">'.perSwitch($per,'agencies',['s'=>$s]).'</div></div><div class="tw listing-scroll"><table class="stack"><thead><tr><th>Agency Name</th><th>Owner</th><th>Accounts</th><th>Entries</th><th>Status</th><th class="r">Actions</th></tr></thead>';
 foreach($rows as $r){$h.='<tr><td data-l="Agency"><span class="cell-main"><span class="ava-sm">'.e(strtoupper(substr($r['name'],0,1))).'</span><span><b>'.e($r['name']).'</b><small>'.e($r['phone']).'</small></span></span></td><td data-l="Owner">'.e($r['owner']).'</td><td data-l="Accounts">'.$r['accs'].'</td><td data-l="Entries">'.$r['ents'].'</td><td data-l="Status"><span class="status-dot '.($r['active']?'on':'off').'">'.($r['active']?'Active':'Blocked').'</span></td><td class="act r" data-l="Actions"><form method="post">'.T().'<input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="'.$r['id'].'"><button class="mini">'.($r['active']?'Block':'Unblock').'</button></form><form method="post" data-reset>'.T().'<input type="hidden" name="act" value="reset"><input type="hidden" name="id" value="'.$r['id'].'"><input type="hidden" name="password" value=""><button class="mini" type="button" data-reset-open>Reset</button></form></td></tr>';}
 if(!$rows)$h.='<tr><td colspan="6" class="muted">No agencies found.</td></tr>';
 page('Agencies',$h.'</table></div>'.pager($tot,$per,$pg,'?p=agencies&per='.$per.'&s='.urlencode($s)).'</div></div>');
 exit;}

/* ---------- AGENCY (tenant-scoped: every query has agency_id) ---------- */
if($U['role']==='master')go('?p=agencies');
$aid=(int)$U['aid'];
if(!$post&&(isset($_GET['csv'])||isset($_GET['xlsx'])||isset($_GET['print'])))session_write_close();
if($p==='audit'){
 $per=max(10,min(100,perPage()));$pg=max(1,(int)($_GET['pg']??1));$search=substr(trim((string)($_GET['s']??'')),0,100);$actionFilter=(string)($_GET['action']??'');
 $actionLabels=['login'=>'Signed in','password_change'=>'Password changed','password_reset'=>'Password reset','create'=>'Created','update'=>'Updated','void'=>'Entry voided','archive'=>'Archived','restore'=>'Restored','transfer'=>'Transfer recorded','backup'=>'Backup downloaded','toggle'=>'Status changed'];if(!isset($actionLabels[$actionFilter]))$actionFilter='';
 $since=gmdate('Y-m-d H:i:s',time()-30*86400);$stats=q('SELECT COUNT(*) total,COUNT(DISTINCT user_id) actors,COUNT(DISTINCT action) action_types,SUM(CASE WHEN created_at>=? THEN 1 ELSE 0 END) recent FROM audit_log WHERE agency_id=?',[$since,$aid])->fetch();
 $where='al.agency_id=?';$params=[$aid];if($actionFilter!==''){$where.=' AND al.action=?';$params[]=$actionFilter;}if($search!==''){$where.=' AND (al.action LIKE ? OR al.entity LIKE ? OR al.details LIKE ? OR u.display_name LIKE ? OR u.username LIKE ?)';$like='%'.$search.'%';array_push($params,$like,$like,$like,$like,$like);}
 $join=' LEFT JOIN users u ON u.id=al.user_id AND u.agency_id=al.agency_id';$total=(int)q('SELECT COUNT(*) FROM audit_log al'.$join.' WHERE '.$where,$params)->fetchColumn();$pg=min($pg,max(1,(int)ceil($total/$per)));$offset=($pg-1)*$per;
 $events=q('SELECT al.id,al.user_id,al.action,al.entity,al.entity_id,al.details,al.created_at,COALESCE(NULLIF(u.display_name,\'\'),u.username,\'Former user\') actor_name,u.username FROM audit_log al'.$join.' WHERE '.$where.' ORDER BY al.id DESC LIMIT '.$per.' OFFSET '.$offset,$params)->fetchAll();
 $eventNames=['account'=>'Travel account','agency'=>'Agency','entry'=>'Ledger entry','user'=>'User','profile'=>'Profile','database'=>'Database'];$eventTones=['login'=>'green','create'=>'blue','update'=>'violet','password_change'=>'orange','password_reset'=>'red','void'=>'red','archive'=>'orange','restore'=>'green','transfer'=>'teal','backup'=>'slate','toggle'=>'violet'];
 $h='<div class="page-head audit-page-head"><div><span class="audit-kicker">SECURITY &amp; HISTORY</span><h1>Activity Log</h1><p class="muted">A clear, read-only record of important changes made in this agency.</p></div><div class="audit-head-mark">'.uiIcon('reports').'<span>Agency audit trail</span></div></div>'.
 '<section class="audit-stats" aria-label="Activity summary"><article class="audit-stat"><span class="audit-stat-icon audit-stat-blue">'.uiIcon('reports').'</span><div><small>Total events</small><strong>'.number_format((int)$stats['total']).'</strong></div></article><article class="audit-stat"><span class="audit-stat-icon audit-stat-green">'.uiIcon('user').'</span><div><small>Contributors</small><strong>'.number_format((int)$stats['actors']).'</strong></div></article><article class="audit-stat"><span class="audit-stat-icon audit-stat-violet">'.uiIcon('trend').'</span><div><small>Last 30 days</small><strong>'.number_format((int)$stats['recent']).'</strong></div></article><article class="audit-stat"><span class="audit-stat-icon audit-stat-amber">'.uiIcon('ledger').'</span><div><small>Action types</small><strong>'.number_format((int)$stats['action_types']).'</strong></div></article></section>'.
 '<section class="card audit-card"><div class="audit-toolbar"><div><h2>Recorded activity</h2><p>'.number_format($total).' matching '.($total===1?'event':'events').'</p></div><form class="audit-filters" method="get"><input type="hidden" name="p" value="audit"><input type="hidden" name="per" value="'.$per.'"><label class="audit-search"><span class="sr-only">Search activity</span><input type="search" name="s" value="'.e($search).'" maxlength="100" placeholder="Search user, record or details"></label><label class="sr-only" for="audit-action">Filter by action</label><select id="audit-action" name="action"><option value="">All actions</option>';foreach($actionLabels as $key=>$label)$h.='<option value="'.e($key).'"'.($actionFilter===$key?' selected':'').'>'.e($label).'</option>';$h.='</select><button class="btn btn-inline">Filter</button>'.(($search!==''||$actionFilter!=='')?'<a class="ghost" href="?p=audit">Clear</a>':'').'</form></div>'.perSwitch($per,'audit',['s'=>$search,'action'=>$actionFilter]).
 '<div class="audit-table-wrap"><table class="audit-table"><thead><tr><th>Activity</th><th>Record</th><th>Details</th><th>Performed by</th><th>Time</th></tr></thead><tbody>';
 foreach($events as $event){$act=(string)$event['action'];$label=$actionLabels[$act]??ucwords(str_replace('_',' ',$act));$entity=(string)($event['entity']??'');$actor=(string)$event['actor_name'];$date=(string)$event['created_at'];$time=strtotime($date);$dateLabel=$time===false?$date:date('M j, Y',$time);$timeLabel=$time===false?'':date('g:i A',$time);$initial=strtoupper(substr(trim($actor),0,1));$h.='<tr><td data-l="Activity"><span class="audit-action audit-tone-'.e($eventTones[$act]??'slate').'"><i></i>'.e($label).'</span></td><td data-l="Record"><strong class="audit-record">'.e($eventNames[$entity]??($entity!==''?ucwords(str_replace('_',' ',$entity)):'System')).'</strong>'.($event['entity_id']?'<small class="audit-record-id">Record #'.(int)$event['entity_id'].'</small>':'').'</td><td data-l="Details"><span class="audit-detail">'.e(trim((string)($event['details']??''))?:'Activity recorded').'</span></td><td data-l="Performed by"><span class="audit-actor"><i>'.e($initial?:'?').'</i><span><strong>'.e($actor).'</strong>'.(!empty($event['username'])?'<small>@'.e((string)$event['username']).'</small>':'').'</span></span></td><td data-l="Time"><time class="audit-time" datetime="'.e($date).'">'.e($dateLabel).'<small>'.e($timeLabel).'</small></time></td></tr>';}
 if(!$events)$h.='<tr><td colspan="5"><div class="audit-empty"><span>'.uiIcon('reports').'</span><strong>'.($search!==''||$actionFilter!==''?'No matching activity':'No activity recorded yet').'</strong><small>'.($search!==''||$actionFilter!==''?'Try another search or clear the filters.':'Changes and sign-ins will appear here as they happen.').'</small></div></td></tr>';
 $h.='</tbody></table></div>'.pager($total,$per,$pg,'?p=audit&per='.$per.'&s='.rawurlencode($search).'&action='.rawurlencode($actionFilter)).'</section>';
 page('Activity Log',$h);exit;
}
if($p==='references'&&!$post){
 header('Content-Type: application/json; charset=UTF-8');$id=(int)($_GET['id']??0);
 if(!q('SELECT 1 FROM accounts WHERE id=? AND agency_id=? AND archived=0',[$id,$aid])->fetchColumn()){http_response_code(404);echo json_encode(['error'=>'Account unavailable']);exit;}
 session_write_close();$search=substr(trim((string)($_GET['s']??'')),0,100);$sourceSide=($_GET['side']??'')==='dr'?'credit':'debit';
 $due=remainingSql();$params=[$aid,$id];$searchSql='';
 if($search!==''){$searchSql=" AND (x.ref LIKE ? ESCAPE '\\' OR x.id=?)";$params[]='%'.addcslashes($search,'%_\\').'%';$params[]=(int)$search;}
 $payments=implode(',',array_map([db(),'quote'],array_merge(DR_PAY,CR_PAY,['ticket_refund','travel_refund'])));
 $rows=q("SELECT x.id,x.edate,x.ref,x.cat,($due) due FROM entries x WHERE x.agency_id=? AND x.account_id=? AND x.deleted=0 AND x.$sourceSide>0 AND x.cat NOT IN ($payments)$searchSql AND ($due)>0 ORDER BY x.edate DESC,x.id DESC LIMIT 51",$params)->fetchAll();
 $more=count($rows)>50;$items=[];foreach(array_slice($rows,0,50) as $row)$items[]=['id'=>(int)$row['id'],'due'=>moneyDecimal((int)$row['due']),'label'=>($row['ref']?:'Entry #'.$row['id']).' · '.(CATS[$row['cat']]??$row['cat']).' · PKR '.m((int)$row['due'])];
 echo json_encode(['items'=>$items,'more'=>$more],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
if($post){chk();$a=post('act');
 if($a==='transfer'){
  $from=(int)($_POST['from_account_id']??0);$to=(int)($_POST['to_account_id']??0);
  try{$amount=cents($_POST['amount']??0);if($amount<=0||$amount>9999999999999||$from===$to||!validDate(post('edate')))throw new InvalidArgumentException('Choose two different cash/bank accounts, a valid date and a positive PKR amount.');}
  catch(InvalidArgumentException $ex){flash($ex->getMessage());go('?p=accounts');}
  try{
   ledgerBegin(db(),true);$ids=[$from,$to];sort($ids,SORT_NUMERIC);
   $locks=q("SELECT id,kind FROM accounts WHERE agency_id=? AND archived=0 AND id IN (?,?) ORDER BY id". (ledgerDriver()==='mysql'?' FOR UPDATE':''),[$aid,$ids[0],$ids[1]])->fetchAll();
   if(count($locks)!==2)throw new RuntimeException('One of the selected accounts is unavailable.');
   foreach($locks as $locked)if(!in_array((string)$locked['kind'],['cash','bank'],true))throw new RuntimeException('Transfers are only available between cash and bank accounts.');
   $key=post('request_key');if(!preg_match('/^[a-f0-9]{48}$/',$key))throw new RuntimeException('Refresh the form before saving.');
   if(q('SELECT 1 FROM entries WHERE agency_id=? AND request_key=?',[$aid,$key])->fetch())throw new RuntimeException('This transfer was already saved.');
   $ref='TRF-'.strtoupper(bin2hex(random_bytes(6)));$date=post('edate');$note=substr(trim(post('note')),0,250);
   q("INSERT INTO entries(agency_id,account_id,edate,note,ref,cat,debit,credit,request_key,currency,fx_rate,foreign_amount) VALUES(?,?,?,?,?,'cash_transfer_out',0,?,?, 'PKR',1000000,?)",[$aid,$from,$date,'Transfer to account #'.$to.($note!==''?' · '.$note:''),$ref,$amount,$key,$amount]);$outId=(int)db()->lastInsertId();
   $pairedKey=substr(hash('sha256','mizora-transfer:'.$key),0,48);
   q("INSERT INTO entries(agency_id,account_id,edate,note,ref,cat,debit,credit,request_key,currency,fx_rate,foreign_amount) VALUES(?,?,?,?,?,'cash_transfer_in',?,?,?, 'PKR',1000000,?)",[$aid,$to,$date,'Transfer from account #'.$from.($note!==''?' · '.$note:''),$ref,$amount,0,$pairedKey,$amount]);$inId=(int)db()->lastInsertId();
   audit('transfer','entry',$outId,'Paired cash/bank transfer #'.$inId.' · '.$ref);ledgerCommit(db());flash('Cash/bank transfer saved in both account ledgers.');
  }catch(Throwable $ex){writeRollback();flash($ex instanceof RuntimeException&&!($ex instanceof PDOException)?$ex->getMessage():'Unable to save transfer. Please retry.');}
  go('?p=accounts');
 }
 if($a==='acc'&&post('name')!==''){try{$opening=cents($_POST['opening']??0);if($opening<0||strlen(post('name'))>180||strlen(post('phone'))>35||!in_array(post('opening_side'),['dr','cr'],true))throw new InvalidArgumentException('Invalid account details');$opening=post('opening_side')==='cr'?-$opening:$opening;}catch(InvalidArgumentException $ex){flash('Opening amount or account details invalid.');go('?p=accounts');}db()->beginTransaction();q("INSERT INTO accounts(agency_id,name,phone,kind,opening) VALUES(?,?,?,?,?)",[$aid,post('name'),post('phone'),in_array(post('kind'),['agent','customer','airline','vendor','cash','bank','other'],true)?post('kind'):'customer',$opening]);audit('create','account',(int)db()->lastInsertId());db()->commit();flash('Account added successfully.');go('?p=accounts');}
 if($a==='ent' || $a==='payment'){
  $id=(int)($_POST['account_id']??0);
  try{$foreignAmount=cents($_POST['amount']??0);$currency=strtoupper(post('currency')?:'PKR');if(!in_array($currency,LEDGER_CURRENCY_CODES,true))throw new InvalidArgumentException('Select a supported transaction currency.');$rateInput=post('fx_rate');$fxRate=$currency==='PKR'&&$rateInput===''?LEDGER_FX_SCALE:ledgerFxRate($rateInput);if($currency==='PKR'&&$fxRate!==LEDGER_FX_SCALE)throw new InvalidArgumentException('PKR transactions must use an exchange rate of 1.');$amt=$currency==='PKR'?$foreignAmount:ledgerForeignToPkr($foreignAmount,$fxRate);}catch(InvalidArgumentException $ex){$foreignAmount=0;$currency='PKR';$fxRate=LEDGER_FX_SCALE;$amt=0;}
  $ac0=q('SELECT id FROM accounts WHERE id=? AND agency_id=? AND archived=0',[$id,$aid])->fetch();
  if(!$ac0||$amt<=0||!validDate(post('edate'))){flash('Invalid account, amount or date.');go('?p=acc&id='.$id);}
  if(!in_array(post('type'),['dr','cr'],true)){flash('Invalid Debit/Credit direction.');go('?p=acc&id='.$id);}
  $dr=post('type')==='dr';$cats=$dr?DEBIT_CATS:CREDIT_CATS;
  $cat=post('cat');if(!isset($cats[$cat])){flash('Please select a valid category.');go('?p=acc&id='.$id);}
  $link=(int)($_POST['linked_entry_id']??0);
  try{
   ledgerBegin(db(),true);
   if(ledgerDriver()==='mysql'&&!q('SELECT id FROM accounts WHERE id=? AND agency_id=? AND archived=0 FOR UPDATE',[$id,$aid])->fetch())throw new RuntimeException('Account is unavailable.');
   $key=post('request_key');if(!preg_match('/^[a-f0-9]{48}$/',$key))throw new RuntimeException('Refresh the form before saving.');
   if(q('SELECT 1 FROM entries WHERE agency_id=? AND request_key=?',[$aid,$key])->fetch()){ledgerRollback(db());flash('Entry already saved.');go('?p=acc&id='.$id);}
   if($link){
    if(!isPaymentCat($cat)&&!isRefundCat($cat))throw new RuntimeException('Only payments or refunds can be linked to a ticket reference.');
    $source=q('SELECT id,debit,credit,ref,cat FROM entries WHERE id=? AND agency_id=? AND account_id=? AND deleted=0',[$link,$aid,$id])->fetch();
    if(!$source||($dr?(int)$source['credit']<=0:(int)$source['debit']<=0)||isPaymentCat((string)$source['cat'])||isRefundCat((string)$source['cat']))throw new RuntimeException('Invalid original entry for this payment or refund.');
    $original=$dr?(int)$source['credit']:(int)$source['debit'];
    $applied=(int)q('SELECT COALESCE(SUM(debit+credit),0) FROM entries WHERE agency_id=? AND account_id=? AND (linked_entry_id=? OR bill_id=?) AND deleted=0',[$aid,$id,$link,$link])->fetchColumn();
   if($amt>$original-$applied)throw new RuntimeException((isRefundCat($cat)?'Refund':'Payment').' exceeds remaining amount for this reference.');
   }
   $detail=post('note');$ref=post('ref');
   if(strlen($detail)>500||strlen($ref)>100)throw new RuntimeException('Details or reference too long.');
   q('INSERT INTO entries(agency_id,account_id,edate,note,ref,cat,debit,credit,linked_entry_id,request_key,currency,fx_rate,foreign_amount) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$aid,$id,post('edate'),$detail,$ref,$cat,$dr?$amt:0,$dr?0:$amt,$link?:null,$key,$currency,$fxRate,$foreignAmount]);
   $newId=(int)db()->lastInsertId();audit('create','entry',$newId,$link?'Reference #'.$link:'General ledger entry');
   ledgerCommit(db());flash('Ledger entry saved successfully.');
  }catch(Throwable $ex){writeRollback();if($ex instanceof RuntimeException && !($ex instanceof PDOException)){flash($ex->getMessage());}else{error_log((string)$ex);flash('Unable to save entry. Please retry.');}}
  go('?p=acc&id='.$id);
 }
 if($a==='del'){$id=(int)($_POST['account_id']??0);$eid=(int)($_POST['id']??0);
  try{ledgerBegin(db(),true);
   if(ledgerDriver()==='mysql')q('SELECT id FROM accounts WHERE id=? AND agency_id=? FOR UPDATE',[$id,$aid])->fetch();
   if((int)q('SELECT COUNT(*) FROM entries WHERE (bill_id=? OR linked_entry_id=?) AND agency_id=? AND account_id=? AND deleted=0',[$eid,$eid,$aid,$id])->fetchColumn()>0)throw new RuntimeException('Void linked payments or refunds before voiding this entry.');
   $changed=q('UPDATE entries SET deleted=1 WHERE id=? AND account_id=? AND agency_id=? AND deleted=0',[$eid,$id,$aid])->rowCount();
   if($changed)audit('void','entry',$eid,'Historical row retained; allocation released');
   ledgerCommit(db());flash($changed?'Entry voided. Balance and allocations updated.':'Entry already void or not found.');
  }catch(Throwable $ex){writeRollback();flash($ex instanceof PDOException?'Unable to void entry. Please retry.':$ex->getMessage());}
  go('?p=acc&id='.$id);}
 if($a==='delacc'){$i=(int)$_POST['id'];$change=q('UPDATE accounts SET archived=1 WHERE id=? AND agency_id=?',[$i,$aid])->rowCount();if($change)audit('archive','account',$i);flash('Account archived. All entries are preserved.');go('?p=accounts');}
 if($a==='restoreacc'){$i=(int)$_POST['id'];$change=q('UPDATE accounts SET archived=0 WHERE id=? AND agency_id=?',[$i,$aid])->rowCount();if($change)audit('restore','account',$i);flash('Account restored.');go('?p=accounts');}
 go('?p=home');}

function cs($v){$v=(string)$v;return preg_match('/^[\x00-\x20]*[=+@-]/',$v)?"'".$v:$v;}
$BAL="a.opening+COALESCE(SUM(e.debit-e.credit),0)";$J="LEFT JOIN entries e ON e.account_id=a.id AND e.agency_id=a.agency_id AND e.deleted=0";

/* ---- accounts (server-side search + pagination) ---- */
if($p==='accounts'){
 $s=trim((string)($_GET['s']??''));$l='%'.addcslashes($s,'%_\\').'%';$per=perPage();
 $showArchived=isset($_GET['archived'])&&$_GET['archived']==='1';
 $cashBankAccounts=q("SELECT id,name,kind FROM accounts WHERE agency_id=? AND archived=0 AND kind IN ('cash','bank') ORDER BY name,id",[$aid])->fetchAll();$transferChoices=[''=>'Choose account'];foreach($cashBankAccounts as $cashBankAccount)$transferChoices[(int)$cashBankAccount['id']]=$cashBankAccount['name'].' ('.ucfirst((string)$cashBankAccount['kind']).')';
 $w="a.agency_id=? AND a.archived=".($showArchived?'1':'0')." AND (a.name LIKE ? ESCAPE '\\' OR IFNULL(a.phone,'') LIKE ? ESCAPE '\\')";
 $tot=(int)q("SELECT COUNT(*) FROM accounts a WHERE $w",[$aid,$l,$l])->fetchColumn();$pg=min($pg,max(1,(int)ceil($tot/$per)));
 $rows=q("SELECT a.id,a.name,a.phone,a.kind,a.opening+COALESCE((SELECT SUM(e.debit-e.credit) FROM entries e WHERE e.account_id=a.id AND e.agency_id=a.agency_id AND e.deleted=0),0) bal FROM accounts a WHERE $w ORDER BY a.name,a.id LIMIT $per OFFSET ".(($pg-1)*$per),[$aid,$l,$l])->fetchAll();
 $summary=q("SELECT COUNT(*) c,COALESCE(SUM(b),0) net,COALESCE(SUM(b>0),0) dr_count,COALESCE(SUM(b<0),0) cr_count,COALESCE(SUM(CASE WHEN b>0 THEN b ELSE 0 END),0) dr,COALESCE(SUM(CASE WHEN b<0 THEN -b ELSE 0 END),0) cr FROM (SELECT $BAL b FROM accounts a $J WHERE a.agency_id=? AND a.archived=? GROUP BY a.id) summary_accounts",[$aid,$showArchived?1:0])->fetch();
 $sum=['c'=>$summary['c'],'net'=>$summary['net']];$di=['c'=>$summary['dr_count'],'bal'=>$summary['dr']];$cr=['c'=>$summary['cr_count'],'bal'=>$summary['cr']];
 $h='<div class="page-head"><div><h1>Travel Ledgers</h1><p class="muted">Manage your travel agency ledgers, track payments and monitor outstanding balances.</p></div><div class="head-tabs"><a class="'.($showArchived?'':'active').'" href="?p=accounts">Active</a><a class="'.($showArchived?'active':'').'" href="?p=accounts&archived=1">Archived</a></div></div>'.
 statCards([['agencies','Total Ledgers',(int)$sum['c'],'teal'],['accounts','With Receivables',(int)$di['c'],'violet'],['trend','Total Receivable','PKR '.m((int)$di['bal']),'green'],['wallet','Total Payable','PKR '.m((int)$cr['bal']),'orange']]).
 '<div class="grid2 cards-grid"><div class="card" id="new-account"><div class="panel-head"><h3>New Account</h3></div><form method="post">'.T().'<input type="hidden" name="act" value="acc"><label>Name<input name="name" required maxlength="180" placeholder="e.g. Skyline Travels"><small class="field-hint">Ledger name shown in reports</small></label><label>Phone<input name="phone" maxlength="35" placeholder="+92 300 0000000"><small class="field-hint">Optional contact number</small></label><label>Type'.sel(['agent'=>'Travel Agent','customer'=>'Customer','airline'=>'Airline','vendor'=>'Vendor / Supplier','cash'=>'Cash Account','bank'=>'Bank Account','other'=>'Other'],'kind').'</label><div class="form-grid tight"><label>Opening Balance (PKR)<input name="opening" type="number" step="0.01" min="0" max="9999999999" value="0" placeholder="0.00"><small class="field-hint">Amount before any entry</small></label><label>Side'.sel(['dr'=>'Debit (Dr)','cr'=>'Credit (Cr)'],'opening_side').'</label></div><button class="btn">Add Account</button></form></div><div class="card table-card"><div class="panel-head"><div><h3>'.($showArchived?'Archived':'Active').' Accounts</h3><p class="muted">Click a ledger to view transactions and payments.</p></div><form class="inline-search"><input type="hidden" name="p" value="accounts">'.($showArchived?'<input type="hidden" name="archived" value="1">':'').'<input type="hidden" name="per" value="'.$per.'"><input class="search" type="search" name="s" value="'.e($s).'" placeholder="Name / phone search…"></form><div class="listing-tools">'.perSwitch($per,'accounts',['archived'=>$showArchived?'1':'','s'=>$s]).'</div></div><div class="tw listing-scroll"><table class="stack"><thead><tr><th>Name</th><th>Type</th><th class="r">Balance (Dr / Cr)</th><th class="r">Actions</th></tr></thead>';
 foreach($rows as $r)$h.='<tr><td data-l="Account"><a class="cell-main" href="?p=acc&id='.$r['id'].'"><span class="ava-sm">'.e(strtoupper(substr($r['name'],0,1))).'</span><span><b>'.e($r['name']).'</b><small>'.e($r['phone']).'</small></span></a></td><td data-l="Type"><span class="chip chip-slate">'.e(ucfirst((string)$r['kind'])).'</span></td><td data-l="Balance" class="r '.($r['bal']>0?'dr':($r['bal']<0?'cr':'')).'"><b>'.dc((int)$r['bal']).'</b></td><td class="act r" data-l="Actions"><a class="mini" href="?p=acc&id='.$r['id'].'">Open</a></td></tr>';
 if(!$rows)$h.='<tr><td colspan="4" class="muted">No accounts found. Add an account to get started.</td></tr>';
 $transferPanel='<section class="card transfer-card"><div class="panel-head"><div><h2>Cash / Bank Transfer</h2><p class="muted">Post matching entries to both accounts in one transaction.</p></div></div>'.(count($cashBankAccounts)>=2?'<form method="post" class="transfer-form">'.T().'<input type="hidden" name="act" value="transfer"><label>From'.sel($transferChoices,'from_account_id').'</label><label>To'.sel($transferChoices,'to_account_id').'</label><label>Amount (PKR)<input name="amount" type="number" step="0.01" min="0.01" max="9999999999" required></label><label>Date<input name="edate" type="date" value="'.date('Y-m-d').'" required></label><label>Details<input name="note" maxlength="250" placeholder="Optional transfer note"></label><button class="btn">Record Transfer</button></form>':'<p class="muted">Create at least two active cash or bank accounts to record transfers.</p>').'</section>';
 $h=str_replace('<div class="grid2 cards-grid">',$transferPanel.'<div class="grid2 cards-grid">',$h);page('Accounts',$h.'</table></div>'.pager($tot,$per,$pg,'?p=accounts&per='.$per.'&archived='.($showArchived?'1':'0').'&s='.urlencode($s)).'</div></div>');}

/* ---- ledger (date filter, pagination, CSV, print) ---- */
if($p==='acc'){
 db()->beginTransaction();
 $id=(int)($_GET['id']??0);$ac=q("SELECT * FROM accounts WHERE id=? AND agency_id=?",[$id,$aid])->fetch();if(!$ac)go('?p=accounts');
 $fr=(string)($_GET['from']??'');$to=(string)($_GET['to']??'');
 if(($fr!==''&&!validDate($fr))||($to!==''&&!validDate($to))||($fr!==''&&$to!==''&&$fr>$to)){http_response_code(422);page('Invalid date range','<div class="card"><h1>Invalid date range</h1><p>Choose valid dates with From before or equal to To.</p><a class="ghost" href="?p=acc&id='.$id.'">Return to ledger</a></div>');}
 $w="account_id=? AND agency_id=? AND deleted=0".($fr?" AND edate>=?":"").($to?" AND edate<=?":"");$a=[$id,$aid];if($fr)$a[]=$fr;if($to)$a[]=$to;
 $open=(int)$ac['opening']+($fr?(int)q("SELECT COALESCE(SUM(debit-credit),0) FROM entries WHERE account_id=? AND agency_id=? AND deleted=0 AND edate<?",[$id,$aid,$fr])->fetchColumn():0);
 if(isset($_GET['csv'])){$fn='ledger-'.preg_replace('/[^a-z0-9]+/i','-',trim($ac['name'])).'-'.$id;header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$fn.'.csv"');header('X-Content-Type-Options: nosniff');$o=fopen('php://output','w');fwrite($o,"\xEF\xBB\xBF");fputcsv($o,['Date','Category','Detail','Ref','Debit','Credit','Balance'],',','"','\\');$r0=$open;fputcsv($o,['','Opening Balance','','','','',dc($open)],',','"','');$csvD=0;$csvC=0;
  foreach(q("SELECT * FROM entries WHERE $w ORDER BY edate,id",$a) as $r){$r0+=$r['debit']-$r['credit'];$csvD+=(int)$r['debit'];$csvC+=(int)$r['credit'];fputcsv($o,[$r['edate'],cs(CATS[$r['cat']]??$r['cat']),cs($r['note'].((string)($r['currency']??'PKR')!=='PKR'?' · '.$r['currency'].' '.m((int)$r['foreign_amount']).' @ '.ledgerFxRateText((int)$r['fx_rate']).' PKR = PKR '.m((int)$r['debit']+(int)$r['credit']):'')),cs($r['ref']),moneyDecimal((int)$r['debit']),moneyDecimal((int)$r['credit']),dc($r0)],',','"','');}fputcsv($o,['','Total / Closing Balance','','',moneyDecimal($csvD),moneyDecimal($csvC),dc($r0)],',','"','');fclose($o);exit;}
 if(isset($_GET['xlsx'])||isset($_GET['print'])){
  require_once __DIR__.'/statement.php';$report=statementData($ac,$aid,$fr,$to,$open,$U);
  if(isset($_GET['print']))statementPrint($report);
  require_once __DIR__.'/xlsx.php';$filename='ledger-'.preg_replace('/[^a-z0-9]+/i','-',trim($ac['name'])).'-'.$id;xlsxDownload($filename,$report);exit;
 }
 $per=perPage();
 $sum=q("SELECT COUNT(*) c,COALESCE(SUM(debit),0) d,COALESCE(SUM(credit),0) k FROM entries WHERE $w",$a)->fetch();
  $pg=min($pg,max(1,(int)ceil((int)$sum['c']/$per)));$lim=$per;$off=($pg-1)*$per;
 $run=$open+($off?(int)q("SELECT COALESCE(SUM(debit-credit),0) FROM (SELECT debit,credit FROM entries WHERE $w ORDER BY edate,id LIMIT $off) entry_page",$a)->fetchColumn():0);
 $es=q("SELECT * FROM entries WHERE $w ORDER BY edate,id LIMIT $lim OFFSET $off",$a)->fetchAll();
 $close=$open+(int)$sum['d']-(int)$sum['k'];$qs='&id='.$id.'&from='.$fr.'&to='.$to.'&per='.$per;
 $outstanding=[];$visibleIds=[];foreach($es as $entry){if(!isPaymentCat((string)$entry['cat']))$visibleIds[]=(int)$entry['id'];}
 if($visibleIds){$dueSql=remainingSql();foreach(q("SELECT x.id,($dueSql) due FROM entries x WHERE x.agency_id=? AND x.account_id=? AND x.id IN (".implode(',',$visibleIds).")",[$aid,$id]) as $source)$outstanding[(int)$source['id']]=(int)$source['due'];}
 db()->commit();
 $balanceLabel=$close>0?'Debit Balance · Receivable':($close<0?'Credit Balance · Payable / Advance':'Account Settled');
 $h='<div class="page-head"><div><a class="back noprint" href="?p=accounts">← Back to Ledgers</a><h1>'.e($ac['name']).'</h1><p class="muted">'.e($ac['phone']?:'No phone').' · '.e(ucfirst((string)$ac['kind'])).' · Complete transaction history, payments and outstanding balance</p></div><div class="noprint row"><a class="ghost" href="?p=acc'.$qs.'&csv=1" data-download>'.uiIcon('download').' CSV</a><a class="ghost xl" href="?p=acc'.$qs.'&xlsx=1" data-download>'.uiIcon('entry').' Excel</a><a class="ghost" href="?p=acc'.$qs.'&print=1" target="_blank" rel="noopener">'.uiIcon('print').' Print / PDF</a></div></div>'.
 '<div class="simple-overview"><div class="card simple-balance"><span class="stat-ic tone-teal">'.uiIcon($close>0?'trend':($close<0?'wallet':'ledger')).'</span><div><small>'.e($balanceLabel).'</small><strong>'.dc($close).'</strong><em>Balance after opening and entries in the selected period</em></div></div><div class="card simple-metric"><span class="stat-ic tone-green">'.uiIcon('ticket').'</span><div><small>Total Debit (Dr)</small><strong>PKR '.m((int)$sum['d']).'</strong></div></div><div class="card simple-metric"><span class="stat-ic tone-orange">'.uiIcon('wallet').'</span><div><small>Total Credit (Cr)</small><strong>PKR '.m((int)$sum['k']).'</strong></div></div><div class="card simple-metric"><span class="stat-ic tone-violet">'.uiIcon('reports').'</span><div><small>Entries in Period</small><strong>'.(int)$sum['c'].'</strong></div></div></div>'.
 '<div class="card simple-entry noprint"><div class="panel-head"><div><h3>Add New Entry</h3><p class="muted">Record ticket, payment and adjustment transactions in this ledger.</p></div></div><form method="post" class="simple-entry-form" data-ledger-entry data-entry-categories="'.e(json_encode(['dr'=>DEBIT_CATS,'cr'=>CREDIT_CATS],JSON_THROW_ON_ERROR)).'">'.T().'<input type="hidden" name="act" value="ent"><input type="hidden" name="account_id" value="'.$id.'"><div class="simple-toggle"><label><input type="radio" name="type" value="dr" checked><span>'.uiIcon('ticket').' Debit (Dr)<small>Amount owed to you</small></span></label><label><input type="radio" name="type" value="cr"><span>'.uiIcon('wallet').' Credit (Cr)<small>Amount you owe</small></span></label></div><label>Category<select name="cat" id="entry-category" required aria-label="Transaction category">'.ledgerCatOptions(DEBIT_CATS).'</select></label><label>Amount (<span data-currency-label>PKR</span>)<input name="amount" id="entry-amount" type="number" step="0.01" min="0.01" max="9999999999" required placeholder="0.00"><small class="field-hint">Enter the original amount in the selected currency</small></label><label>Currency'.sel(array_combine(LEDGER_CURRENCY_CODES,LEDGER_CURRENCY_CODES),'currency').'</label><label>Rate (PKR per unit)<input name="fx_rate" id="fx-rate" type="number" step="0.000001" min="0.000001" max="100000" value="1.000000" required><small class="field-hint" id="fx-rate-hint">PKR is fixed at 1. For other currencies, enter the agreed rate.</small></label><label>Date<input type="date" name="edate" value="'.date('Y-m-d').'" required aria-label="Entry date"><small class="field-hint">Defaults to today</small></label><label>Details<input name="note" maxlength="500" placeholder="e.g. Air ticket sold - Dubai return"><small class="field-hint">Optional · passenger or payment notes</small></label><label>PNR / Receipt #<input name="ref" maxlength="100" placeholder="e.g. PNR1234 or RCPT-001"><small class="field-hint">Optional · reference for this entry</small></label><label class="reference-field" id="reference-field" hidden><span class="reference-title">Payment Against</span><input type="search" id="reference-search" placeholder="Search PNR, receipt or entry number" autocomplete="off" aria-label="Search payment references"><select name="linked_entry_id" id="linked-entry"><option value="">General payment (no reference)</option></select><small id="linked-due-note">Select a ticket to link this payment.</small></label><button class="btn">Save Debit Entry</button></form></div>'.
 '<div class="card statement-card"><div class="simple-section-head"><div><h3>Ledger Statement</h3><span class="muted">Dr / Cr · Running balance</span></div><form class="filter noprint" novalidate data-validate><input type="hidden" name="p" value="acc"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="per" value="'.$per.'"><label>From<input type="date" name="from" value="'.e($fr).'" aria-label="From date"></label><label>To<input type="date" name="to" value="'.e($to).'" aria-label="To date"></label><button class="mini">'.uiIcon('search').' Filter</button></form></div><div class="listing-tools">'.perSwitch($per,'acc',['id'=>$id,'from'=>$fr,'to'=>$to]).'</div></div>'.
 '<div class="card ledger-table-card"><div class="tw listing-scroll"><table class="stack"><thead><tr><th>Date</th><th>Category</th><th>Detail</th><th class="r">Debit</th><th class="r">Credit</th><th class="r">Balance</th><th class="noprint"></th></tr></thead><tbody>'.($off?'':'<tr class="op"><td data-l="Entry"></td><td data-l="Category"></td><td data-l="Detail">Opening Balance</td><td class="r" data-l="Debit"></td><td class="r" data-l="Credit"></td><td class="r" data-l="Balance">'.dc($open).'</td><td class="noprint"></td></tr>');
 if(!$es)$h.='<tr><td colspan="7" class="muted">No transactions in the selected period.</td></tr>';
 foreach($es as $r){$run+=$r['debit']-$r['credit'];
  $billStatus=isset($outstanding[(int)$r['id']])?'<small class="allocation-status">Current reference outstanding: PKR '.m($outstanding[(int)$r['id']]).'</small>':'';
  $fxNote=(string)($r['currency']??'PKR')!=='PKR'?'<small class="fx-note">'.e((string)$r['currency']).' '.m((int)$r['foreign_amount']).' × '.e(ledgerFxRateText((int)$r['fx_rate'])).' = PKR '.m((int)$r['debit']+(int)$r['credit']).'</small>':'';
  $h.='<tr><td data-l="Date">'.e(date('d/m/Y',strtotime($r['edate']))).'</td><td data-l="Category">'.badgeCat((string)$r['cat'],CATS[$r['cat']]??$r['cat']).'</td><td data-l="Detail">'.e($r['note']).$fxNote.$billStatus.($r['ref']?' <small>#'.e($r['ref']).'</small>':'').($r['linked_entry_id']?' <small class="badge">Against entry #'.(int)$r['linked_entry_id'].'</small>':'').($r['bill_id']?' <small class="badge">Legacy entry #'.(int)$r['bill_id'].'</small>':'').'</td><td class="r dr" data-l="Debit">'.($r['debit']?m((int)$r['debit']):'').'</td><td class="r cr" data-l="Credit">'.($r['credit']?m((int)$r['credit']):'').'</td><td class="r" data-l="Balance">'.dc($run).'</td><td class="noprint act r" data-l="Actions"><form method="post">'.T().'<input type="hidden" name="act" value="del"><input type="hidden" name="id" value="'.$r['id'].'"><input type="hidden" name="account_id" value="'.$id.'"><button class="mini danger" data-confirm="Void this entry? Its balance and allocation will be released. History will be retained.">Void</button></form></td></tr>';}
  $h.='</tbody><tfoot class="listing-foot"><tr class="tot"><td data-l="Entry"></td><td data-l="Category"></td><td data-l="Detail">Total ('.(int)$sum['c'].' entries)</td><td class="r" data-l="Debit">'.m((int)$sum['d']).'</td><td class="r" data-l="Credit">'.m((int)$sum['k']).'</td><td class="r" data-l="Balance">'.dc($close).'</td><td class="noprint"></td></tr></tfoot></table></div>'.pager((int)$sum['c'],$per,$pg,'?p=acc'.$qs).'</div><form method="post" class="noprint" style="margin-top:14px">'.T().'<input type="hidden" name="act" value="delacc"><input type="hidden" name="id" value="'.$id.'"><button class="mini danger" data-confirm="Archive this account? All financial history will be preserved.">Archive Account</button></form>'.(isset($_GET['print'])?'<script src="assets/print.js"></script>':'');
 if((int)$ac['archived']===1){$h.='<form method="post" class="noprint">'.T().'<input type="hidden" name="act" value="restoreacc"><input type="hidden" name="id" value="'.$id.'"><button class="ghost">Restore Account</button></form>';$start=strpos($h,'<div class="card simple-entry noprint">');$end=strpos($h,'<div class="card statement-card">',$start);if($start!==false&&$end!==false)$h=substr_replace($h,'<div class="card noprint"><p>This account is archived. Restore it to add entries.</p></div>',$start,$end-$start);}
 page($ac['name'],$h);}

/* ---- reports: no nested scrollbars; Dr/Cr labels accurately reflect both directions ---- */
if($p==='reports'){
 db()->beginTransaction();
 $y=max(2000,min(2100,(int)($_GET['y']??date('Y'))));$ys=sprintf('%04d',$y);
 $mm=q("SELECT substr(edate,1,7) k,COUNT(*) c,COALESCE(SUM(debit),0) d,COALESCE(SUM(credit),0) r FROM entries WHERE agency_id=? AND deleted=0 AND edate>=? AND edate<? GROUP BY k ORDER BY k",[$aid,$ys.'-01-01',sprintf('%04d',$y+1).'-01-01'])->fetchAll();
 $cc=q("SELECT cat k,COUNT(*) c,COALESCE(SUM(debit),0) d,COALESCE(SUM(credit),0) r FROM entries WHERE agency_id=? AND deleted=0 AND edate>=? AND edate<? GROUP BY k ORDER BY d+r DESC",[$aid,$ys.'-01-01',sprintf('%04d',$y+1).'-01-01'])->fetchAll();
 $activeLedgerCount=(int)q('SELECT COUNT(*) FROM accounts WHERE agency_id=? AND archived=0', [$aid])->fetchColumn();
 $top=q("SELECT a.id,a.name,$BAL bal FROM accounts a $J WHERE a.agency_id=? AND a.archived=0 GROUP BY a.id HAVING bal>0 ORDER BY bal DESC LIMIT 10",[$aid])->fetchAll();
 $allReceivables=(int)q("SELECT COALESCE(SUM(bal),0) FROM (SELECT $BAL bal FROM accounts a $J WHERE a.agency_id=? AND a.archived=0 GROUP BY a.id HAVING bal>0) receivables",[$aid])->fetchColumn();
 $management=q("SELECT COALESCE(SUM(CASE WHEN cat IN ('ticket_sold','umrah_sold','visa_sold','hotel_sold','transport_sold','tour_sold') THEN debit ELSE 0 END),0) sales,COALESCE(SUM(CASE WHEN cat IN ('ticket_bought','umrah_bought','visa_bought','hotel_bought','transport_bought','tour_bought') THEN credit ELSE 0 END),0) service_cost,COALESCE(SUM(CASE WHEN cat IN ('ticket_refund','travel_refund') THEN credit ELSE 0 END),0) refunds,COALESCE(SUM(CASE WHEN cat='commission_earned' THEN debit ELSE 0 END),0) commissions,COALESCE(SUM(CASE WHEN cat='expense_paid' THEN credit ELSE 0 END),0) expenses FROM entries WHERE agency_id=? AND deleted=0 AND edate>=? AND edate<?",[$aid,$ys.'-01-01',sprintf('%04d',$y+1).'-01-01'])->fetch();
 $serviceMargin=ledgerServiceMargin($management);
 $totD=array_sum(array_column($mm,'d'));$totC=array_sum(array_column($mm,'r'));$n=array_sum(array_column($mm,'c'));
 $palette=['#12a394','#0e6f77','#d9525f','#f0913f','#8b7cf6','#f0b93d','#5c8a8e','#2aa198','#c2678a','#7fa8a0'];
 $catParts=[];foreach($cc as $i=>$r)$catParts[]=['label'=>CATS[$r['k']]??$r['k'],'value'=>(int)$r['c'],'color'=>$palette[$i%10]];
 $share=fn(int $v):string=>$n>0?number_format($v/$n*100,1).'%':'0%';
 $t=function(array $rs,bool $category=false)use($share,$n){$o='';foreach($rs as $r){$c=(int)$r['c'];$label=$category?(CATS[$r['k']]??$r['k']):date('M Y',strtotime($r['k'].'-01'));$o.='<tr><td data-l="'.($category?'Category':'Month').'">'.e($label).'</td><td class="r" data-l="Entries">'.$c.'</td><td class="r" data-l="Share"><span class="share"><i style="width:'.($n>0?round($c/$n*100):0).'%"></i></span>'.e($share($c)).'</td><td class="r dr" data-l="Debit">'.m((int)$r['d']).'</td><td class="r cr" data-l="Credit">'.m((int)$r['r']).'</td></tr>';}return $o?:'<tr><td colspan="5" class="muted">No transactions for this period.</td></tr>';};
 $mn=[];foreach($mm as $row)$mn[(int)substr($row['k'],5,2)]=$row;$rows=[];for($i=1;$i<=12;$i++)$rows[]=[date('M',mktime(0,0,0,$i,1)),(int)($mn[$i]['d']??0),(int)($mn[$i]['r']??0)];
 $up=$totD>=$totC?'green':'orange';$net=(int)$totD-(int)$totC;
 $best=null;foreach($mm as $row){if($best===null||(int)$row['d']+(int)$row['r']>(int)$best['d']+(int)$best['r'])$best=$row;}
 $h='<div class="print-only print-report-heading"><div><h1>MIZORA · Financial Report</h1><p>'.e((string)($U['an']??'')).' · Financial Year '.$y.'</p></div><p>Generated '.date('d M Y h:i A').'</p></div><div class="page-head report-head"><div><h1>Reports</h1><p class="muted">Financial performance, category distribution and outstanding receivables for '.$y.'.</p></div><div class="row noprint"><div class="year-switch"><a href="?p=reports&y='.($y-1).'" aria-label="Previous year">‹</a><span>'.$y.'</span><a href="?p=reports&y='.($y+1).'" aria-label="Next year">›</a></div><button class="ghost" type="button" data-print>'.uiIcon('print').' Print Report</button></div></div>'.
 statCards([['trend','Total Debit','PKR '.m((int)$totD),'green'],['wallet','Total Credit','PKR '.m((int)$totC),'orange'],['ledger','Net Movement',dc($net),$up],['reports','Total Transactions',$n,'violet']]).
 '<div class="report-band"><span class="rb-item"><small>Active Ledgers</small><b>'.$activeLedgerCount.'</b></span><span class="rb-item"><small>Busiest Month</small><b>'.($best?date('M Y',strtotime($best['k'].'-01')):'—').'</b></span><span class="rb-item"><small>Average Per Entry</small><b>PKR '.($n?m((int)round(((int)$totD+(int)$totC)/$n)):m(0)).'</b></span><span class="rb-item"><small>Categories Used</small><b>'.count($cc).'</b></span><span class="rb-item rb-note"><small>Report Basis</small><b>Posted entries only · voided rows excluded</b></span></div>'.
 '<section class="card report-panel margin-estimate"><div class="report-panel-head"><div><h3>Service Margin Estimate</h3><small>From tagged sales, supplier costs, refunds, commissions and expenses in '.$y.'</small></div><strong class="margin-value">PKR '.m($serviceMargin).'</strong></div><p class="margin-formula">Sales − supplier costs − refunds + commissions − expenses</p><div class="report-band margin-components"><span class="rb-item"><small>Service sales</small><b>PKR '.m((int)$management['sales']).'</b></span><span class="rb-item"><small>Supplier costs</small><b>− PKR '.m((int)$management['service_cost']).'</b></span><span class="rb-item"><small>Refunds</small><b>− PKR '.m((int)$management['refunds']).'</b></span><span class="rb-item"><small>Commission earned</small><b>+ PKR '.m((int)$management['commissions']).'</b></span><span class="rb-item"><small>Expenses paid</small><b>− PKR '.m((int)$management['expenses']).'</b></span></div><p class="margin-disclaimer">This is an estimate from category-tagged transactions. It excludes opening balances and untagged costs, and refunds are not matched to original sales. The ledger is not a balanced double-entry journal, so this figure is not formal accounting profit.</p></section>'.
 '<div class="report-grid"><section class="card report-panel report-panel-wide"><div class="report-panel-head"><div><h3>Monthly Trend</h3><small>Debit and Credit movement for each month of '.$y.'</small></div><span class="legend-inline"><i class="lg-debit"></i>Debit<i class="lg-credit"></i>Credit</span></div><div class="chart-box pad">'.barsSvg($rows).'</div><span class="chart-hint">Swipe to see every month</span></section></div>'.
 '<div class="report-grid"><section class="card report-panel"><div class="report-panel-head"><div><h3>Category Wise Distribution</h3><small>Share of transactions by travel service</small></div></div><div class="report-split">'.pieSvg($catParts,168).pieLegend($catParts).'</div></section>'.
 '<section class="card report-panel"><div class="report-panel-head"><div><h3>Monthly Activity</h3><small>Entries, share and Dr/Cr totals by month</small></div></div><div class="report-table-wrap"><table class="stack"><thead><tr><th>Month</th><th class="r">Entries</th><th class="r">Share</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead><tbody>'.$t($mm).'</tbody></table></div></section></div>'.
 '<div class="report-grid"><section class="card report-panel report-panel-wide"><div class="report-panel-head"><div><h3>Category Performance</h3><small>Every travel service category used in '.$y.'</small></div></div><div class="report-table-wrap"><table class="stack"><thead><tr><th>Category</th><th class="r">Entries</th><th class="r">Share</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead><tbody>'.$t($cc,true).'</tbody></table></div></section></div>'.
 '<div class="report-grid"><section class="card report-panel top-panel"><div class="report-panel-head"><div><h3>Top Receivables</h3><small>Highest outstanding balances · share of all receivables</small></div></div><div class="report-table-wrap"><table class="stack"><thead><tr><th>Travel Account</th><th class="r">Receivable</th><th class="r">All receivables</th></tr></thead><tbody>';
 foreach($top as $r)$h.='<tr><td data-l="Account"><a class="cell-main" href="?p=acc&id='.(int)$r['id'].'"><span class="ava-sm">'.e(strtoupper(substr($r['name'],0,1))).'</span><span><b>'.e($r['name']).'</b></span></a></td><td class="r dr" data-l="Receivable"><b>PKR '.m((int)$r['bal']).'</b></td><td class="r" data-l="All receivables">'.($allReceivables>0?number_format((int)$r['bal']/$allReceivables*100,1):'0.0').'%</td></tr>';
 $h.=($top?'':'<tr><td colspan="3" class="muted">No outstanding receivables. All ledgers are settled.</td></tr>').'</tbody></table></div></section>'.
 '<section class="card report-panel"><div class="report-panel-head"><div><h3>Report Summary</h3><small>What this report covers</small></div></div><ul class="report-notes"><li>Totals include posted entries only; voided rows are excluded from every figure.</li><li>Opening balances are not part of the yearly movement but are included in receivables.</li><li>Debit shows amounts billed to travel agencies; Credit shows payments and purchases.</li><li>Use the year switcher to review previous financial years.</li></ul><div class="report-actions noprint"><a class="ghost" href="?p=accounts">'.uiIcon('ledger').' Open Travel Ledger</a><a class="ghost" href="?p=home">'.uiIcon('dashboard').' Dashboard</a></div></section></div>';
 page('Reports',$h);
}

/* ---- dashboard ---- */
db()->beginTransaction();
$t=q("SELECT COUNT(*) n,COALESCE(SUM(archived=0),0) active,COALESCE(SUM(archived=1),0) archived,COALESCE(SUM(archived=0 AND b=0),0) settled,COALESCE(SUM(CASE WHEN archived=0 AND b>0 THEN b ELSE 0 END),0) r,COALESCE(SUM(CASE WHEN archived=0 AND b<0 THEN -b ELSE 0 END),0) p FROM (SELECT a.archived,$BAL b FROM accounts a $J WHERE a.agency_id=? GROUP BY a.id) dashboard_accounts",[$aid])->fetch();
$rc=q("SELECT e.*,a.name FROM entries e JOIN accounts a ON a.id=e.account_id AND a.agency_id=e.agency_id WHERE e.agency_id=? AND e.deleted=0 ORDER BY e.id DESC LIMIT 10",[$aid])->fetchAll();
$yearNow=(int)date('Y');$y=$yearNow;$ys=sprintf('%04d',$y);
$activeCnt=(int)$t['active'];$archCnt=(int)$t['archived'];$settled=(int)$t['settled'];$sumDr=(int)$t['r'];$sumCr=(int)$t['p'];$unsettled=$activeCnt-$settled;
$cntRows=q("SELECT substr(edate,6,2) k,COUNT(*) c,COALESCE(SUM(debit),0) d,COALESCE(SUM(credit),0) r FROM entries WHERE agency_id=? AND deleted=0 AND edate>=? AND edate<? GROUP BY k",[$aid,$ys.'-01-01',sprintf('%04d',$y+1).'-01-01'])->fetchAll();
$by=[];foreach($cntRows as $row)$by[(int)$row['k']]=$row;$bars=[];for($i=1;$i<=12;$i++)$bars[]=[date('M',mktime(0,0,0,$i,1)),(int)($by[$i]['d']??0),(int)($by[$i]['r']??0)];
db()->commit();
$dashName=e(trim((string)($U['name']??''))?:trim((string)($U['an']??''))?:'Admin');
$h='<div class="page-head"><div><h1>Dashboard</h1><p class="muted">Your agency at a glance.</p></div><a class="btn btn-inline" href="?p=accounts">'.uiIcon('ledger').' Open Travel Ledgers</a></div>'.
'<div class="dash"><section class="hero"><div class="hero-text"><h2>Welcome back, '.$dashName.'</h2><p>Manage your travel agency ledger, track payments and monitor outstanding balances.</p><div class="hero-kpis"><div class="hk"><span class="stat-ic tone-teal">'.uiIcon('agencies').'</span><div><small>Active Ledgers</small><b>'.$activeCnt.'</b><em>Active travel agency ledgers</em></div></div><div class="hk"><span class="stat-ic tone-teal">'.uiIcon('accounts').'</span><div><small>Total Accounts</small><b>'.(int)$t['n'].'</b><em>Payment and service accounts</em></div></div><div class="hk"><span class="stat-ic tone-green">'.uiIcon('trend').'</span><div><small>Total Debit</small><b>PKR '.m($sumDr).'</b><em>Net amounts owed to you</em></div></div><div class="hk"><span class="stat-ic tone-orange">'.uiIcon('wallet').'</span><div><small>Total Credit</small><b>PKR '.m($sumCr).'</b><em>Net amounts you owe</em></div></div></div></div><div class="hero-date"><span>'.date('l').'</span><b>'.date('d M Y').'</b></div></section>'.
'<div class="dash-grid"><section class="card panel"><div class="panel-head"><div><h3>Monthly Transactions</h3><small>Debit vs Credit for '.$y.'</small></div><span class="legend-inline"><i class="lg-debit"></i>Debit<i class="lg-credit"></i>Credit</span></div><div class="chart-box">'.barsSvg($bars).'</div><span class="chart-hint">Swipe to see every month</span></section>'.
'<section class="card panel"><div class="panel-head"><div><h3>Account Status</h3><small>Active travel ledgers overview</small></div></div><div class="report-split account-status">'.pieSvg([['label'=>'Unsettled','value'=>$unsettled,'color'=>'#1f52b5'],['label'=>'Archived','value'=>$archCnt,'color'=>'#f0b93d'],['label'=>'Settled','value'=>$settled,'color'=>'#d9525f']],170).pieLegend([['label'=>'Unsettled','value'=>$unsettled,'color'=>'#1f52b5'],['label'=>'Archived','value'=>$archCnt,'color'=>'#f0b93d'],['label'=>'Settled','value'=>$settled,'color'=>'#d9525f']]).'</div></section></div>'.
'<section class="card table-card"><div class="panel-head"><div><h3>Recent Entries</h3><small>Latest transactions across all ledgers</small></div><a class="ghost" href="?p=reports">View Reports '.uiIcon('trend').'</a></div><div class="tw listing-scroll"><table class="stack"><thead><tr><th>#</th><th>Date</th><th>Account</th><th>Category</th><th>Detail</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead><tbody>';
foreach($rc as $i=>$r)$h.='<tr><td data-l="Entry"><span class="num">'.($i+1).'</span></td><td data-l="Date">'.e($r['edate']).'</td><td data-l="Account"><a class="cell-main" href="?p=acc&id='.$r['account_id'].'"><span class="ava-sm">'.e(strtoupper(substr($r['name'],0,1))).'</span><span><b>'.e($r['name']).'</b></span></a></td><td data-l="Category">'.badgeCat((string)$r['cat'],CATS[$r['cat']]??$r['cat']).'</td><td data-l="Detail">'.e($r['note']).($r['ref']?' <small class="muted">#'.e($r['ref']).'</small>':'').'</td><td class="r dr" data-l="Debit">'.($r['debit']?m((int)$r['debit']):'').'</td><td class="r cr" data-l="Credit">'.($r['credit']?m((int)$r['credit']):'').'</td></tr>';
if(!$rc)$h.='<tr><td colspan="7" class="muted">No entries yet. Open a ledger to add your first entry.</td></tr>';
page('Dashboard',$h.'</tbody></table></div></section></div>');
