<?php
declare(strict_types=1);

function productionMode(): bool { return getenv('LEDGER_ENV') === 'production'; }
function pathWithin(string $path,string $root): bool {
    $path=str_replace('\\','/',$path);$root=rtrim(str_replace('\\','/',$root),'/');
    if(PHP_OS_FAMILY==='Windows'){$path=strtolower($path);$root=strtolower($root);}
    return $path===$root || str_starts_with($path,$root.'/');
}
function productionGuard(string $public): void {
    if(!productionMode())return;
    $driver=strtolower((string)(getenv('LEDGER_DRIVER')?:'sqlite'));$sessions=getenv('LEDGER_SESSION_PATH');
    $realSessions=$sessions?realpath($sessions):false;
    if(!$realSessions||!is_dir($realSessions)||pathWithin($realSessions,$public)||!is_writable($realSessions))throw new RuntimeException('Production requires a writable private session directory');
    if($driver==='sqlite'){
        $db=getenv('LEDGER_DB');$realDb=$db?realpath($db):false;
        if(!$realDb||!is_file($realDb)||pathWithin($realDb,$public)||!is_writable($realDb)||!is_writable(dirname($realDb)))throw new RuntimeException('Production requires a writable private SQLite database');
    }elseif($driver==='mysql'){
        foreach(['LEDGER_DB_HOST','LEDGER_DB_NAME','LEDGER_DB_USER','LEDGER_DB_PASSWORD'] as $name)if(getenv($name)===false||getenv($name)==='')throw new RuntimeException('Production MariaDB configuration is incomplete');
    }else throw new RuntimeException('Production database driver is invalid');
    if(PHP_OS_FAMILY!=='Windows'){
        $privatePaths=[$realSessions];if($driver==='sqlite')array_push($privatePaths,$realDb,dirname($realDb));
        foreach(array_unique($privatePaths) as $path)if((fileperms($path)&0007)!==0)throw new RuntimeException('Private storage grants access to other users');
        if(PHP_SAPI!=='cli')foreach(['','/assets','/index.php','/ui.php','/xlsx.php','/statement.php','/hardening.php','/runtime.php','/database.php','/schema-mariadb.php','/mariadb-backup.php','/assets/app.css','/assets/app.js','/assets/print.js'] as $part)if(is_writable($public.$part))throw new RuntimeException('Application code must be read-only to the FPM user');
    }
    // The web server supplies trusted HTTPS and REMOTE_ADDR. Never trust raw forwarded headers here.
    if(PHP_SAPI!=='cli'&&strtolower((string)($_SERVER['HTTPS']??''))!=='on'&&($_SERVER['HTTPS']??'')!=='1'){
        http_response_code(503);throw new RuntimeException('Production requires trusted HTTPS termination');
    }
    if(PHP_SAPI!=='cli'){
        ini_set('session.save_path',$realSessions);
        ini_set('session.gc_maxlifetime','1800');
    }
}
