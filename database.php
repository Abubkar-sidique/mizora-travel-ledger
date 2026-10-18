<?php
declare(strict_types=1);

function ledgerDriver(): string {
    $driver = strtolower(trim((string)(getenv('LEDGER_DRIVER') ?: 'sqlite')));
    if (!in_array($driver, ['sqlite','mysql'], true)) throw new RuntimeException('LEDGER_DRIVER must be sqlite or mysql');
    return $driver;
}
function ledgerPdoOptions(): array {
    return [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
}
function connectLedgerDatabase(): PDO {
    if (ledgerDriver()==='sqlite') {
        $path=getenv('LEDGER_DB') ?: __DIR__.'/data/ledger.sqlite';
        if(!is_dir(dirname($path))&&!mkdir(dirname($path),0700,true)&&!is_dir(dirname($path)))throw new RuntimeException('Cannot create SQLite data directory');
        $pdo=new PDO('sqlite:'.$path,null,null,ledgerPdoOptions());
        $pdo->exec('PRAGMA busy_timeout=3000; PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA synchronous=FULL');
        return $pdo;
    }
    if (!extension_loaded('pdo_mysql')) throw new RuntimeException('The pdo_mysql PHP extension is required');
    $host=(string)getenv('LEDGER_DB_HOST');$name=(string)getenv('LEDGER_DB_NAME');$user=(string)getenv('LEDGER_DB_USER');$pass=(string)getenv('LEDGER_DB_PASSWORD');
    if($host===''||$name===''||$user===''||$pass==='')throw new RuntimeException('MariaDB connection settings are incomplete');
    if(!preg_match('/^[A-Za-z0-9_.:-]{1,255}$/',$host)||!preg_match('/^[A-Za-z0-9_]{1,64}$/',$name))throw new RuntimeException('MariaDB host or database name is invalid');
    $port=(int)(getenv('LEDGER_DB_PORT') ?: 3306);
    if($port<1||$port>65535)throw new RuntimeException('MariaDB port is invalid');
    $dsn='mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4';
    $options=ledgerPdoOptions();$ca=(string)(getenv('LEDGER_DB_SSL_CA')?:'');
    if($ca!==''){
        if(!is_file($ca)||!defined('PDO::MYSQL_ATTR_SSL_CA'))throw new RuntimeException('MariaDB TLS CA file is unavailable');
        $options[PDO::MYSQL_ATTR_SSL_CA]=$ca;$options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true;
    }
    $pdo=new PDO($dsn,$user,$pass,$options);
    $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_BACKSLASH_ESCAPES'");
    $pdo->exec("SET SESSION time_zone='+00:00'");
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $server=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
    if(!str_contains(strtolower($server),'mariadb'))throw new RuntimeException('This deployment requires a MariaDB server');
    if(!preg_match('/^(\d+)\.(\d+)\.(\d+)/',$server,$version)||version_compare($version[1].'.'.$version[2].'.'.$version[3],'10.2.1','<'))throw new RuntimeException('MariaDB 10.2.1 or newer is required for enforced CHECK constraints');
    return $pdo;
}
function ledgerBegin(PDO $pdo, bool $writeLock=false): void {
    if($pdo->inTransaction())throw new LogicException('Nested database transaction is not supported');
    if(ledgerDriver()==='sqlite')$pdo->exec($writeLock?'BEGIN IMMEDIATE':'BEGIN');else $pdo->beginTransaction();
}
function ledgerCommit(PDO $pdo): void { if(ledgerDriver()==='sqlite')$pdo->exec('COMMIT');elseif($pdo->inTransaction())$pdo->commit(); }
function ledgerRollback(PDO $pdo): void { if(ledgerDriver()==='sqlite')$pdo->exec('ROLLBACK');elseif($pdo->inTransaction())$pdo->rollBack(); }
function ledgerSql(string $sql): string {
    if(ledgerDriver()==='sqlite')return $sql;
    $sql=preg_replace_callback('/\bMAX\(([^(),]+),([^()]+)\)/i',static fn(array $m):string=>'GREATEST('.$m[1].','.$m[2].')',$sql)??$sql;
    $sql=str_replace("datetime('now','-1 minute')",'DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MINUTE)',$sql);
    if(str_starts_with($sql,'INSERT INTO login_limits('))$sql='INSERT INTO login_limits(rate_key,attempts,until_time) VALUES(?,1,?) ON DUPLICATE KEY UPDATE attempts=IF(until_time<=?,1,attempts+1),until_time=IF(until_time<=?,VALUES(until_time),until_time)';
    return $sql;
}
