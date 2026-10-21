<?php
declare(strict_types=1);

// All money remains integer minor units, including formatting and exports.
function moneyDecimal(int $value): string {
    $negative = $value < 0;
    $digits = (string)abs($value);
    $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);
    return ($negative ? '-' : '').substr($digits, 0, -2).'.'.substr($digits, -2);
}
function requestKey(): string {
    return '<input type="hidden" name="request_key" value="'.bin2hex(random_bytes(24)).'">';
}
function migrateIntegrity(PDO $p): void {
    if ((int)$p->query('PRAGMA user_version')->fetchColumn() >= 5) return;
    $p->exec('BEGIN IMMEDIATE');
    try {
        $columns = array_column($p->query('PRAGMA table_info(entries)')->fetchAll(), 'name');
        if (!in_array('request_key', $columns, true)) $p->exec('ALTER TABLE entries ADD COLUMN request_key TEXT');
        $p->exec("CREATE UNIQUE INDEX IF NOT EXISTS i_entry_request ON entries(agency_id,request_key) WHERE request_key IS NOT NULL;
        CREATE INDEX IF NOT EXISTS i_report_date ON entries(agency_id,deleted,edate);
        CREATE TABLE IF NOT EXISTS login_limits (key TEXT PRIMARY KEY, attempts INTEGER NOT NULL, until_time INTEGER NOT NULL);
        CREATE TRIGGER IF NOT EXISTS entry_integrity_insert BEFORE INSERT ON entries BEGIN
          SELECT CASE WHEN typeof(NEW.debit)!='integer' OR typeof(NEW.credit)!='integer' OR NEW.debit<0 OR NEW.credit<0 OR (NEW.debit=0)=(NEW.credit=0) OR NEW.debit>9999999999999 OR NEW.credit>9999999999999 THEN RAISE(ABORT,'Invalid monetary entry') END;
          SELECT CASE WHEN NOT EXISTS(SELECT 1 FROM accounts WHERE id=NEW.account_id AND agency_id=NEW.agency_id AND archived=0) THEN RAISE(ABORT,'Invalid tenant account') END;
          SELECT CASE WHEN COALESCE(NEW.linked_entry_id,NEW.bill_id) IS NOT NULL AND NOT EXISTS(SELECT 1 FROM entries s WHERE s.id=COALESCE(NEW.linked_entry_id,NEW.bill_id) AND s.agency_id=NEW.agency_id AND s.account_id=NEW.account_id AND s.deleted=0 AND ((NEW.debit>0 AND s.credit>0) OR (NEW.credit>0 AND s.debit>0))) THEN RAISE(ABORT,'Invalid payment reference') END;
          SELECT CASE WHEN NEW.linked_entry_id IS NOT NULL AND NEW.bill_id IS NOT NULL AND NEW.linked_entry_id!=NEW.bill_id THEN RAISE(ABORT,'Conflicting references') END;
          SELECT CASE WHEN COALESCE(NEW.linked_entry_id,NEW.bill_id) IS NOT NULL AND (NEW.cat NOT IN ('payment_paid','cash_paid','bank_paid','payment_received','cash_received','bank_received','ticket_refund','travel_refund') OR EXISTS(SELECT 1 FROM entries WHERE id=COALESCE(NEW.linked_entry_id,NEW.bill_id) AND cat IN ('payment_paid','cash_paid','bank_paid','payment_received','cash_received','bank_received','ticket_refund','travel_refund'))) THEN RAISE(ABORT,'Only payments or refunds can be allocated to charges') END;
          SELECT CASE WHEN COALESCE(NEW.linked_entry_id,NEW.bill_id) IS NOT NULL AND NEW.debit+NEW.credit+COALESCE((SELECT SUM(debit+credit) FROM entries WHERE agency_id=NEW.agency_id AND account_id=NEW.account_id AND deleted=0 AND (linked_entry_id=COALESCE(NEW.linked_entry_id,NEW.bill_id) OR bill_id=COALESCE(NEW.linked_entry_id,NEW.bill_id))),0)>(SELECT MAX(debit,credit) FROM entries WHERE id=COALESCE(NEW.linked_entry_id,NEW.bill_id)) THEN RAISE(ABORT,'Payment exceeds remaining reference amount') END;
        END;
        CREATE TRIGGER IF NOT EXISTS entry_immutable BEFORE UPDATE ON entries WHEN NEW.agency_id IS NOT OLD.agency_id OR NEW.account_id IS NOT OLD.account_id OR NEW.debit IS NOT OLD.debit OR NEW.credit IS NOT OLD.credit OR NEW.edate IS NOT OLD.edate OR NEW.cat IS NOT OLD.cat OR NEW.ref IS NOT OLD.ref OR NEW.note IS NOT OLD.note OR NEW.bill_id IS NOT OLD.bill_id OR NEW.linked_entry_id IS NOT OLD.linked_entry_id OR NEW.request_key IS NOT OLD.request_key OR NEW.deleted NOT IN (0,1) OR NEW.deleted<OLD.deleted BEGIN SELECT RAISE(ABORT,'Financial history is immutable'); END;
        CREATE TRIGGER IF NOT EXISTS entry_void_guard BEFORE UPDATE OF deleted ON entries WHEN NEW.deleted=1 AND OLD.deleted=0 AND EXISTS(SELECT 1 FROM entries WHERE agency_id=OLD.agency_id AND account_id=OLD.account_id AND deleted=0 AND (linked_entry_id=OLD.id OR bill_id=OLD.id)) BEGIN SELECT RAISE(ABORT,'Void linked payments first'); END;
        PRAGMA user_version=5;");
        $dr = implode(',', array_map([$p, 'quote'], array_keys(DEBIT_CATS)));
        $cr = implode(',', array_map([$p, 'quote'], array_keys(CREDIT_CATS)));
        $p->exec("CREATE TRIGGER IF NOT EXISTS entry_category_guard BEFORE INSERT ON entries WHEN (NEW.debit>0 AND NEW.cat NOT IN ($dr)) OR (NEW.credit>0 AND NEW.cat NOT IN ($cr)) BEGIN SELECT RAISE(ABORT,'Category direction mismatch'); END;
          CREATE TRIGGER IF NOT EXISTS account_opening_guard BEFORE INSERT ON accounts WHEN typeof(NEW.opening)!='integer' OR ABS(NEW.opening)>9999999999999 BEGIN SELECT RAISE(ABORT,'Invalid opening balance'); END;
          CREATE TRIGGER IF NOT EXISTS entry_no_delete BEFORE DELETE ON entries BEGIN SELECT RAISE(ABORT,'Void entries to retain history'); END;");
        $p->exec('COMMIT');
    } catch (Throwable $ex) { $p->exec('ROLLBACK'); throw $ex; }
}
function migrateChat(PDO $p):void {
    if((int)$p->query('PRAGMA user_version')->fetchColumn()>=6)return;
    $p->exec('BEGIN IMMEDIATE');
    try{if((int)$p->query('PRAGMA user_version')->fetchColumn()>=6){$p->exec('COMMIT');return;}$p->exec("CREATE TABLE IF NOT EXISTS chat_messages(id INTEGER PRIMARY KEY,agency_id INTEGER NOT NULL REFERENCES agencies(id),sender_id INTEGER NOT NULL REFERENCES users(id),from_master INTEGER NOT NULL CHECK(from_master IN (0,1)),body TEXT NOT NULL CHECK(length(body) BETWEEN 1 AND 2000),request_key TEXT NOT NULL,read_at TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(sender_id,request_key));CREATE INDEX IF NOT EXISTS i_chat_thread ON chat_messages(agency_id,id);CREATE INDEX IF NOT EXISTS i_chat_unread ON chat_messages(from_master,read_at,agency_id);PRAGMA user_version=6;");$p->exec('COMMIT');}catch(Throwable $ex){$p->exec('ROLLBACK');throw $ex;}
}
function migrateChatSupport(PDO $p):void {
    if((int)$p->query('PRAGMA user_version')->fetchColumn()>=7)return;
    $p->exec('BEGIN IMMEDIATE');
    try{
        if((int)$p->query('PRAGMA user_version')->fetchColumn()>=7){$p->exec('COMMIT');return;}
        if(!in_array('is_auto',array_column($p->query('PRAGMA table_info(chat_messages)')->fetchAll(),'name'),true))$p->exec('ALTER TABLE chat_messages ADD COLUMN is_auto INTEGER NOT NULL DEFAULT 0 CHECK(is_auto IN (0,1))');
        $p->exec("CREATE TABLE IF NOT EXISTS chat_presence(id INTEGER PRIMARY KEY CHECK(id=1),mode TEXT NOT NULL DEFAULT 'available' CHECK(mode IN ('available','away')),last_seen INTEGER NOT NULL DEFAULT 0);
        INSERT OR IGNORE INTO chat_presence(id) VALUES(1);
        CREATE TABLE IF NOT EXISTS chat_threads(agency_id INTEGER PRIMARY KEY REFERENCES agencies(id),last_id INTEGER NOT NULL DEFAULT 0,unread_master INTEGER NOT NULL DEFAULT 0,unread_agent INTEGER NOT NULL DEFAULT 0,bot_topic TEXT NOT NULL DEFAULT '',bot_count INTEGER NOT NULL DEFAULT 0,last_bot_at INTEGER NOT NULL DEFAULT 0,human_until INTEGER NOT NULL DEFAULT 0,waiting INTEGER NOT NULL DEFAULT 0);
        INSERT OR IGNORE INTO chat_threads(agency_id,last_id,unread_master,unread_agent)
          SELECT agency_id,MAX(id),SUM(CASE WHEN from_master=0 AND read_at IS NULL THEN 1 ELSE 0 END),SUM(CASE WHEN from_master=1 AND read_at IS NULL THEN 1 ELSE 0 END) FROM chat_messages GROUP BY agency_id;
        CREATE INDEX IF NOT EXISTS i_chat_last ON chat_threads(last_id DESC,agency_id DESC);
        CREATE INDEX IF NOT EXISTS i_chat_rate ON chat_messages(sender_id,created_at) WHERE is_auto=0;
        CREATE INDEX IF NOT EXISTS i_chat_pending ON chat_messages(agency_id,from_master,id) WHERE read_at IS NULL;
        CREATE TRIGGER IF NOT EXISTS chat_insert_count AFTER INSERT ON chat_messages BEGIN
          INSERT INTO chat_threads(agency_id,last_id,unread_master,unread_agent) VALUES(NEW.agency_id,NEW.id,CASE WHEN NEW.from_master=0 AND NEW.read_at IS NULL THEN 1 ELSE 0 END,CASE WHEN NEW.from_master=1 AND NEW.read_at IS NULL THEN 1 ELSE 0 END)
          ON CONFLICT(agency_id) DO UPDATE SET last_id=MAX(last_id,NEW.id),unread_master=unread_master+CASE WHEN NEW.from_master=0 AND NEW.read_at IS NULL THEN 1 ELSE 0 END,unread_agent=unread_agent+CASE WHEN NEW.from_master=1 AND NEW.read_at IS NULL THEN 1 ELSE 0 END;
        END;
        CREATE TRIGGER IF NOT EXISTS chat_read_count AFTER UPDATE OF read_at ON chat_messages WHEN OLD.read_at IS NULL AND NEW.read_at IS NOT NULL BEGIN
          UPDATE chat_threads SET unread_master=MAX(0,unread_master-CASE WHEN NEW.from_master=0 THEN 1 ELSE 0 END),unread_agent=MAX(0,unread_agent-CASE WHEN NEW.from_master=1 THEN 1 ELSE 0 END) WHERE agency_id=NEW.agency_id;
        END;
        PRAGMA user_version=7;");
        $p->exec('COMMIT');
    }catch(Throwable $ex){$p->exec('ROLLBACK');throw $ex;}
}
function migrateLedgerCurrency(PDO $p):void{
    if((int)$p->query('PRAGMA user_version')->fetchColumn()>=8)return;
    $p->exec('BEGIN IMMEDIATE');
    try{
        if((int)$p->query('PRAGMA user_version')->fetchColumn()>=8){$p->exec('COMMIT');return;}
        $columns=array_column($p->query('PRAGMA table_info(entries)')->fetchAll(),'name');
        if(!in_array('currency',$columns,true))$p->exec("ALTER TABLE entries ADD COLUMN currency TEXT NOT NULL DEFAULT 'PKR' CHECK(length(currency)=3)");
        if(!in_array('fx_rate',$columns,true))$p->exec('ALTER TABLE entries ADD COLUMN fx_rate INTEGER NOT NULL DEFAULT 1000000 CHECK(fx_rate>0 AND fx_rate<=100000000000)');
        if(!in_array('foreign_amount',$columns,true))$p->exec('ALTER TABLE entries ADD COLUMN foreign_amount INTEGER NOT NULL DEFAULT 0 CHECK(foreign_amount>=0 AND foreign_amount<=9999999999999)');
        $p->exec('UPDATE entries SET foreign_amount=debit+credit WHERE foreign_amount=0');
        $p->exec('DROP TRIGGER IF EXISTS entry_immutable; DROP TRIGGER IF EXISTS entry_category_guard;');
        $dr=implode(',',array_map([$p,'quote'],array_merge(array_keys(DEBIT_CATS),['commission_earned'])));
        $cr=implode(',',array_map([$p,'quote'],array_merge(array_keys(CREDIT_CATS),['expense_paid'])));
        $currencies=implode(',',array_map([$p,'quote'],LEDGER_CURRENCY_CODES));
        $fxCheck='CAST(NEW.foreign_amount/1000000 AS INTEGER)*NEW.fx_rate+CAST(((NEW.foreign_amount%1000000)*NEW.fx_rate+500000)/1000000 AS INTEGER)';
        $p->exec("CREATE TRIGGER entry_immutable BEFORE UPDATE ON entries WHEN NEW.agency_id IS NOT OLD.agency_id OR NEW.account_id IS NOT OLD.account_id OR NEW.debit IS NOT OLD.debit OR NEW.credit IS NOT OLD.credit OR NEW.edate IS NOT OLD.edate OR NEW.cat IS NOT OLD.cat OR NEW.ref IS NOT OLD.ref OR NEW.note IS NOT OLD.note OR NEW.bill_id IS NOT OLD.bill_id OR NEW.linked_entry_id IS NOT OLD.linked_entry_id OR NEW.request_key IS NOT OLD.request_key OR NEW.currency IS NOT OLD.currency OR NEW.fx_rate IS NOT OLD.fx_rate OR NEW.foreign_amount IS NOT OLD.foreign_amount OR NEW.deleted NOT IN (0,1) OR NEW.deleted<OLD.deleted BEGIN SELECT RAISE(ABORT,'Financial history is immutable'); END;
        CREATE TRIGGER entry_category_guard BEFORE INSERT ON entries WHEN (NEW.debit>0 AND NEW.cat NOT IN ($dr)) OR (NEW.credit>0 AND NEW.cat NOT IN ($cr)) OR NEW.fx_rate<=0 OR NEW.fx_rate>100000000000 OR NEW.foreign_amount<=0 OR NEW.foreign_amount>9999999999999 OR NEW.currency NOT IN ($currencies) OR (NEW.currency='PKR' AND (NEW.fx_rate!=1000000 OR NEW.foreign_amount!=NEW.debit+NEW.credit)) OR NEW.debit+NEW.credit!=($fxCheck) BEGIN SELECT RAISE(ABORT,'Invalid currency conversion, rate, amount or category direction'); END;
        PRAGMA user_version=8;");
        $p->exec('COMMIT');
    }catch(Throwable $ex){$p->exec('ROLLBACK');throw $ex;}
}
function chatAvailability(int $agency=0):array {
    $presence=q('SELECT mode,last_seen FROM chat_presence WHERE id=1')->fetch();
    $mode=(string)($presence['mode']??'available');
    // Away is the safe default: the assistant only stays silent while the admin is genuinely active.
    $available=$mode==='available'&&(int)($presence['last_seen']??0)>=time()-90;
    $thread=$agency?q('SELECT human_until,waiting FROM chat_threads WHERE agency_id=?',[$agency])->fetch():false;
    return ['mode'=>$mode,'available'=>$available,'handling'=>$thread&&(int)$thread['human_until']>time(),'waiting'=>$thread&&(bool)$thread['waiting']];
}
function recordMariaChatMessage(int $agency,int $messageId,int $fromMaster):void {
    if(ledgerDriver()!=='mysql')return;
    q('INSERT INTO chat_threads(agency_id,last_id,unread_master,unread_agent) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE last_id=GREATEST(last_id,VALUES(last_id)),unread_master=unread_master+VALUES(unread_master),unread_agent=unread_agent+VALUES(unread_agent)',[$agency,$messageId,$fromMaster?0:1,$fromMaster?1:0]);
}
// Local, explicit FAQ responses. No external AI service, credentials or financial actions.
function chatAssistantWaitText():string {
    return "Thank you for your message. I could not resolve this automatically, so I have placed your chat in the queue for Abubakar Sidique (Master Admin). He will personally review it and reply when he is available. Please wait patiently; your patience is appreciated and your messages will not be lost. If you have more details (screenshots, error text, or steps), you can add them here anytime. Thank you for your understanding.";
}
function chatAssistantReply(int $agency,string $body,int $messageId):void {
    $availability=chatAvailability($agency);
    if($availability['available']||$availability['handling'])return;
    $state=q('SELECT * FROM chat_threads WHERE agency_id=?',[$agency])->fetch();$now=time();
    $fresh=$now-(int)($state['last_bot_at']??0)>1800;
    if(!$fresh&&(int)($state['waiting']??0))return;
    $previous=$fresh?'':(string)($state['bot_topic']??'');
    $count=$fresh?0:(int)($state['bot_count']??0);
    $text=strtolower(trim($body));$topic='unknown';$reply='';
    $wait=chatAssistantWaitText();
    if(preg_match('/\b(admin|abubak[ae]r|abu ?bakr|human|person|call|contact|connect|reset|forgot|bhool|bhul)\b/iu',$text)){$topic='handoff';$reply=$wait;}
    elseif(preg_match('/^(hi+|hii+|hello+|he+llo+|hey+|hy+|aoa|as.?salam[ou]?[a-z]*|salam[ou]?[a-z]*|assalam[ou]?[a-z]*|good (morning|evening|afternoon|night)|help(?: me)?|i need help|need help|madad|koi madad|kya help|support|anyone (there|here)|koi hai|koi hy)\b[\s!?.]*$/iu',$text)
        ||preg_match('/^(h[a-z]{0,3}|hi|hello|hey|hy|aoa|salam|help)[\s!?.]*$/iu',$text)){$topic='welcome';$reply='Assalamualaikum and welcome! I am the MIZORA automated support assistant. Abubakar Sidique (Master Admin) is currently away, but I am here to guide you. Please tell me what you need help with, for example: login/access, saving a ledger entry, payments or references, date filters, balance totals, or Print/Excel export. You may write in English or Roman Urdu — dono chalenge. Aapko kis cheez mein madad chahiye?';}
    elseif(preg_match('/\b(thanks?|thank you|thn?x|shukriya|jazakallah|jazzakallah|resolved|solved|theek ho gaya|thik ho gaya|ho gaya|hogaya|masla hal|issue hal)\b/iu',$text)){$topic='thanks';$reply='You are most welcome! Alhamdulillah the issue is sorted. If you face anything else later, just message here — I can guide you anytime, and Abubakar Sidique can also reply personally when available. Happy to help!';}
    elseif(preg_match('/\b(login|log ?in|sign ?in|password|pass ?word|username|user ?name|locked|lock|access|account (blocked|disabled)|inactive|active nahi)\b/iu',$text)){$topic='login';$reply='For sign-in issues, please try these steps:\n1) Check your username and password carefully (Caps Lock off).\n2) After too many failed attempts the system locks sign-in for 5 minutes — wait and try again.\n3) If the agency account is inactive or the password must be reset, only Abubakar Sidique (Master Admin) can restore it.\nPlease share the exact error text and your agency name here (never share your password). Kya error text aa raha hai?';}
    elseif(preg_match('/\b(print|pdf|excel|xlsx|export|download|save as|file)\b/iu',$text)){$topic='export';$reply='For exports:\n1) Open the customer ledger and choose the date range first.\n2) Use the Excel or Print / PDF button above the transactions — exports include the selected period across all pages.\n3) For PDF, choose \"Save as PDF\" in the browser print window.\nPlease tell me which button fails and any error shown. Excel ya Print mein kya masla aa raha hai?';}
    elseif(preg_match('/\b(save|saving|saved|entry|debit|credit|payment|pay|reference|ref|pnr|paisa|pese|rupees|jama|bill|invoice)\b/iu',$text)){$topic='entry';$reply='For saving an entry:\n1) Open the correct ledger, choose Debit or Credit.\n2) Fill the date, category and amount, then save.\n3) For an allocated payment, pick a valid reference and keep it within the remaining amount.\n4) Before retrying, check whether the entry already appears in the ledger (to avoid duplicates).\nIf an error appears, share the exact error text and the step where it happens. Please never send passwords or bank details here. Kaunsa step par masla aa raha hai?';}
    elseif(preg_match('/\b(date|calendar|filter|range|tareekh|tarikh|pagination|pages?|limit|rows|next page|previous page)\b/iu',$text)){$topic='filter';$reply='For dates and filtering:\n1) Use the date filters above the transactions — start date must be on or before the end date, then apply.\n2) Clear both dates to see the full history.\n3) Rows per page and page navigation are below the listing.\nWhich date range or page is giving you trouble?';}
    elseif(preg_match('/\b(balance|total|opening|closing|hisab|hisaab|calculate|calculation|wrong amount|galat)\b/iu',$text)){$topic='balance';$reply='For balance and totals:\n1) Check the selected date range and the opening balance shown.\n2) A filtered statement carries forward transactions before its start date, so totals reflect that period.\n3) The Dr/Cr label shows the direction of the balance.\nPlease describe the mismatch and the period you are viewing. The assistant cannot change or verify your financial records — Abubakar Sidique can review it with you when available. Kis period ka total mismatch kar raha hai?';}
    elseif(preg_match('/\b(slow|slowness|loading|refresh|hang|hanging|stuck|freeze|frozen|network|internet|load|not responding|khul nahi|open nahi)\b/iu',$text)){$topic='loading';$reply='For slow or stuck pages:\n1) Check your internet connection first.\n2) Wait for the current request to finish — do not submit twice quickly (duplicates ban sakte hain).\n3) If a save appears stuck, check the ledger before submitting it again.\n4) For a page-loading issue, refresh once and note the page name and any error text.\nPlease send those details here so the admin can investigate it properly. Kaunsa page slow hai?';}
    if(!$fresh&&$previous===$topic&&$now-(int)$state['last_bot_at']<15)return;
    $waiting=$topic==='handoff'||$topic==='unknown'||($count>=3&&$topic!=='thanks')||($previous===$topic&&$topic!=='thanks');
    if($waiting){$reply=$wait;$topic='handoff';}
    elseif($count===0&&$topic!=='welcome')$reply="Automated support: Abubakar Sidique is currently away.\n\n".$reply;
    $master=q("SELECT id FROM users WHERE role='master' ORDER BY id LIMIT 1")->fetchColumn();
    if(!$master)return;
    q('INSERT INTO chat_messages(agency_id,sender_id,from_master,body,request_key,is_auto) VALUES(?,?,1,?,?,1)',[$agency,(int)$master,$reply,'auto-reply-'.$messageId]);
    recordMariaChatMessage($agency,(int)db()->lastInsertId(),1);
    q('UPDATE chat_threads SET bot_topic=?,bot_count=?,last_bot_at=?,waiting=? WHERE agency_id=?',[$topic,$count+1,$now,$waiting?1:0,$agency]);
}
function writeRollback(): void {
    // PDO does not reliably track transactions opened with BEGIN IMMEDIATE.
    try { db()->exec('ROLLBACK'); } catch (Throwable $ignored) {}
}
