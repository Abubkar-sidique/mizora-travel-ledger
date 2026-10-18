param([string]$ServerRoot = $env:MARIADB_HOME)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$php = (Get-Command php).Source
if (-not $ServerRoot) { $ServerRoot = Join-Path $env:TEMP 'MizoraMariaDBTest\server\mariadb-11.4.10-winx64' }
$serverExe = Join-Path $ServerRoot 'bin\mariadbd.exe'
$installer = Join-Path $ServerRoot 'bin\mariadb-install-db.exe'
$client = Join-Path $ServerRoot 'bin\mariadb.exe'
if (-not (Test-Path -LiteralPath $client)) { $client = Join-Path $ServerRoot 'bin\mysql.exe' }
if (-not (Test-Path -LiteralPath $serverExe) -or -not (Test-Path -LiteralPath $installer)) {
    if ($env:MARIADB_TEST_REQUIRED -eq '1') { throw 'MariaDB integration required, but mariadbd and mariadb-install-db were not found. Set MARIADB_HOME.' }
    Write-Output 'SKIP MariaDB integration: no MariaDB server installed (MySQL is not accepted as a substitute).'
    exit 0
}
$tempRoot = Join-Path $env:TEMP ('MizoraMariaDBIntegration-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $tempRoot | Out-Null
$resolvedTemp = [IO.Path]::GetFullPath($tempRoot)
$tempBoundary = [IO.Path]::GetFullPath($env:TEMP).TrimEnd([IO.Path]::DirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
if (-not $resolvedTemp.StartsWith($tempBoundary,[StringComparison]::OrdinalIgnoreCase) -or -not (Split-Path -Leaf $resolvedTemp).StartsWith('MizoraMariaDBIntegration-',[StringComparison]::Ordinal)) { throw "Unsafe test directory: $resolvedTemp" }
$data = Join-Path $tempRoot 'data'
$serverLog = Join-Path $tempRoot 'mariadb.log'
$serverError = Join-Path $tempRoot 'mariadb.err'
$rootPassword = 'MizoraRootTest731A'
$portListener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0);$portListener.Start();$port=$portListener.LocalEndpoint.Port;$portListener.Stop()
$server = $null
$phpServer = $null
$phpServerErr = Join-Path $tempRoot 'php.err'
$phpServerOut = Join-Path $tempRoot 'php.out'
$dbName = 'mizora_migrate_test'
$restoreDb = 'mizora_restore_test'
$backupPath = Join-Path $tempRoot 'restore.sql'

function Invoke-Maria([string]$sql,[string]$database='') {
    $args = @('--protocol=tcp','--host=127.0.0.1',"--port=$script:port",'--user=root',"--password=$script:rootPassword",'--batch','--skip-column-names')
    if ($database) { $args += "--database=$database" }
    $args += @('-e',$sql)
    $output = & $script:client @args 2>&1
    if ($LASTEXITCODE -ne 0) { throw "MariaDB command failed: $output" }
    return ($output -join "`n").Trim()
}
function Read-SharedLog([string]$path) {$stream=[IO.File]::Open($path,[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::Read,[IO.FileShare]::ReadWrite);try{$reader=[IO.StreamReader]::new($stream);return $reader.ReadToEnd()}finally{$stream.Dispose()}}
function Login([string]$username) {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $page = Invoke-WebRequest -Uri ($script:base + '?p=login') -WebSession $session -TimeoutSec 15
    $csrf = [regex]::Match($page.Content,'name="t" value="([^"]+)"').Groups[1].Value
    if (-not $csrf) { throw 'Login form has no CSRF token' }
    try{$response = Invoke-WebRequest -Uri ($script:base + '?p=login') -Method Post -WebSession $session -Body @{username=$username;password='Audit-Only-Owner-Password-731';t=$csrf} -TimeoutSec 15}catch{throw "Login request failed for $username; PHP log: $(Read-SharedLog $script:phpServerErr)"}
    if ($response.StatusCode -ne 200) { throw "Login failed for $username" }
    return $session
}

try {
    $initArgs = @("--datadir=$data","--password=$rootPassword","--port=$port")
    $init = Start-Process -FilePath $installer -ArgumentList $initArgs -WorkingDirectory (Split-Path -Parent $installer) -WindowStyle Hidden -Wait -PassThru
    if ($init.ExitCode -ne 0) { throw "MariaDB initialization failed with exit code $($init.ExitCode)" }
    $server = Start-Process -FilePath $serverExe -ArgumentList @('--no-defaults',"--basedir=$ServerRoot","--datadir=$data","--port=$port",'--bind-address=127.0.0.1','--console','--skip-log-error') -WorkingDirectory (Join-Path $ServerRoot 'bin') -WindowStyle Hidden -PassThru -RedirectStandardOutput $serverLog -RedirectStandardError $serverError
    $ready=$false
    for($i=0;$i -lt 80;$i++) {
        if($server.HasExited) { throw "MariaDB exited: $([IO.File]::ReadAllText($serverError)) $([IO.File]::ReadAllText($serverLog))" }
        try { $version=Invoke-Maria 'SELECT VERSION()';if($version -match 'MariaDB'){$ready=$true;break} } catch { Start-Sleep -Milliseconds 350 }
    }
    if(-not $ready) { throw "MariaDB did not become ready: $([IO.File]::ReadAllText($serverError)) $([IO.File]::ReadAllText($serverLog))" }
    Write-Output "MariaDB integration server: $version"
    $rollbackDb='mizora_rollback_'+$suffix
    Invoke-Maria "CREATE DATABASE $dbName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE $restoreDb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE $rollbackDb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null

    $source = Join-Path $tempRoot 'source.sqlite'
    & $php (Join-Path $PSScriptRoot 'create-mariadb-fixture.php') $source
    if ($LASTEXITCODE -ne 0) { throw 'Could not create migration fixture' }
    $env:LEDGER_DRIVER='mysql';$env:LEDGER_DB_HOST='127.0.0.1';$env:LEDGER_DB_PORT="$port";$env:LEDGER_DB_NAME=$dbName;$env:LEDGER_DB_USER='root';$env:LEDGER_DB_PASSWORD=$rootPassword;$env:LEDGER_ENV='test';Remove-Item Env:LEDGER_DB_SSL_CA -ErrorAction SilentlyContinue;Remove-Item Env:LEDGER_AUTO_MIGRATE -ErrorAction SilentlyContinue
    $importer=Join-Path $projectRoot 'ops/migrate-sqlite-to-mariadb.php'
    $importResult=& $php $importer "--source=$source" --confirm-empty-target 2>&1
    if($LASTEXITCODE -ne 0){throw "SQLite-to-MariaDB import failed: $importResult"}
    $report=$importResult -join "`n"
    if($report -notmatch '"status": "PASS"' -or $report -notmatch '"account_balances_verified": 4'){throw "Importer report is missing counts/balance verification: $report"}
    Write-Output 'PASS SQLite-to-MariaDB migration parity: counts, account balances, source-row hashes, tenant data and financial preflight.'
    try{$repeat=& $php $importer "--source=$source" --confirm-empty-target 2>&1}catch{$repeat=@($_.ToString())}
    if($LASTEXITCODE -eq 0 -or (($repeat -join "`n") -notmatch 'not empty')){throw 'Repeated importer did not refuse the populated destination'}
    Write-Output 'PASS importer repeat run refuses to overwrite existing MariaDB data.'
    $env:LEDGER_DB_NAME=$rollbackDb;& $php (Join-Path $PSScriptRoot 'prepare-mariadb-rollback.php');if($LASTEXITCODE -ne 0){throw 'Could not prepare rollback fault injection'}
    try{$failedImport=& $php $importer "--source=$source" --confirm-empty-target 2>&1}catch{$failedImport=@($_.ToString())}
    if($LASTEXITCODE -eq 0 -or (($failedImport -join "`n") -notmatch 'Import rolled back')){throw "Injected import failure did not report rollback: $failedImport"}
    foreach($table in @('agencies','users','accounts','entries','audit_log','login_limits','chat_presence','chat_messages','chat_threads')){$left=[int](Invoke-Maria "SELECT COUNT(*) FROM ``$table``" $rollbackDb);if($left -ne 0){throw "Importer rollback left $left rows in $table"}}
    Write-Output 'PASS injected mid-import failure rolls back every imported row and preserves an empty destination.'
    $env:LEDGER_DB_NAME=$dbName

    $script:base='http://127.0.0.1:'
    $appListener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0);$appListener.Start();$appPort=$appListener.LocalEndpoint.Port;$appListener.Stop();$script:base="http://127.0.0.1:$appPort/"
    $phpServer=Start-Process -FilePath $php -ArgumentList @('-S',"127.0.0.1:$appPort",'router.php') -WorkingDirectory (Join-Path $projectRoot 'ledger') -WindowStyle Hidden -PassThru -RedirectStandardOutput $phpServerOut -RedirectStandardError $phpServerErr
    $online=$false;for($i=0;$i -lt 60;$i++){if($phpServer.HasExited){throw "PHP/MariaDB app exited: $([IO.File]::ReadAllText($phpServerErr))"};try{$null=Invoke-WebRequest -Uri ($script:base+'?p=login') -TimeoutSec 5;$online=$true;break}catch{Start-Sleep -Milliseconds 250}}
    if(-not $online){throw 'MariaDB-backed application did not start'}
    $owner=Login 'audit.owner1';$accounts=Invoke-WebRequest -Uri ($script:base+'?p=accounts') -WebSession $owner -TimeoutSec 15
    if($accounts.Content -match 'Tenant Two Secret Account'){throw 'Tenant one can read tenant two account data'}
    if($accounts.Content -notmatch '&lt;script&gt;alert\(1\)&lt;/script&gt;' -or $accounts.Content -match '<script>alert\(1\)</script>'){throw 'MariaDB-backed XSS escaping failed'}
    $isolated=Invoke-WebRequest -Uri ($script:base+'?p=acc&id=3') -WebSession $owner -TimeoutSec 15
    if($isolated.Content -match 'Tenant Two Secret Account'){throw 'Tenant one can read tenant two account ledger'}
    $owner2=Login 'audit.owner2';$own2=Invoke-WebRequest -Uri ($script:base+'?p=accounts') -WebSession $owner2 -TimeoutSec 15
    if($own2.Content -notmatch 'Tenant Two Secret Account'){throw 'Tenant two cannot read its own account'}
    try{$null=Invoke-WebRequest -Uri ($script:base+'?p=home') -Method Post -WebSession $owner -Body @{act='profile'} -TimeoutSec 10;throw 'Missing CSRF token was accepted'}catch{if($_.Exception.Response.StatusCode.value__ -ne 419){throw}}
    Write-Output 'PASS MariaDB HTTP security: tenant isolation, ownership, XSS escaping, and CSRF rejection.'

    $workers=@();for($i=1;$i -le 2;$i++){$out=Join-Path $tempRoot "worker-$i.out";$err=Join-Path $tempRoot "worker-$i.err";$workerScript='"'+(Join-Path $PSScriptRoot 'mariadb-allocation-worker.php')+'"';$proc=Start-Process -FilePath $php -ArgumentList $workerScript -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput $out -RedirectStandardError $err;$workers+=@{Process=$proc;Out=$out;Err=$err}}
    foreach($w in $workers){if(-not $w.Process.WaitForExit(20000)){throw 'Concurrent allocation worker timed out'};$w.Process.Refresh();$workerOut=[IO.File]::ReadAllText($w.Out);$workerErr=[IO.File]::ReadAllText($w.Err);if($workerErr.Trim() -or $workerOut.Trim() -notin @('ACCEPTED','REJECTED')){throw "Concurrent allocation worker failed: stdout=$workerOut; stderr=$workerErr"}}
    $results=@($workers|ForEach-Object{[IO.File]::ReadAllText($_.Out).Trim()}|Sort-Object)
    if(($results -join ',') -ne 'ACCEPTED,REJECTED'){throw "Concurrent allocation lock did not serialize: $($results -join ',')"}
    $allocated=Invoke-Maria 'SELECT COALESCE(SUM(debit+credit),0) FROM entries WHERE agency_id=1 AND account_id=1 AND deleted=0 AND (linked_entry_id=1 OR bill_id=1)' $dbName
    if([int64]$allocated -gt 10000){throw 'Concurrent MariaDB allocations exceeded the source charge'}
    Write-Output 'PASS MariaDB concurrent allocation race: one concurrent payment accepted, one rejected, no over-allocation.'

    $chrome=Join-Path $env:ProgramFiles 'Google\Chrome\Application\chrome.exe';if(Test-Path $chrome){$profile=Join-Path $tempRoot 'chrome-profile';& node (Join-Path $PSScriptRoot 'browser-smoke.mjs') $script:base $chrome $profile;if($LASTEXITCODE -ne 0){throw 'MariaDB browser smoke failed'}}else{Write-Output 'SKIP MariaDB browser smoke: Chrome not found.'}

    $master=Login 'audit.master';$snapshotPath=Join-Path $tempRoot 'pre-backup-snapshot.json';& $php (Join-Path $PSScriptRoot 'verify-mariadb-restore.php') $dbName --save $snapshotPath;if($LASTEXITCODE -ne 0){throw 'Could not capture pre-backup snapshot'};Invoke-WebRequest -Uri ($script:base+'?p=backup&download=1') -WebSession $master -OutFile $backupPath -TimeoutSec 30
    $dump=[IO.File]::ReadAllText($backupPath)
    if(-not $dump.StartsWith('-- MIZORA MariaDB logical backup') -or $dump -match '(?i)CREATE\s+TRIGGER|CREATE\s+PROCEDURE|DEFINER=|DROP\s+DATABASE'){throw 'MariaDB backup is malformed or contains prohibited DDL'}
    $psi=[Diagnostics.ProcessStartInfo]::new();$psi.FileName=$client;$psi.Arguments="--protocol=tcp --host=127.0.0.1 --port=$port --user=root --password=$rootPassword --database=$restoreDb --default-character-set=utf8mb4";$psi.WorkingDirectory=(Split-Path -Parent $client);$psi.UseShellExecute=$false;$psi.RedirectStandardInput=$true;$psi.RedirectStandardOutput=$true;$psi.RedirectStandardError=$true
    $restore=[Diagnostics.Process]::new();$restore.StartInfo=$psi;if(-not $restore.Start()){throw 'Could not start MariaDB restore client'};$inputFile=[IO.File]::OpenRead($backupPath);try{$inputFile.CopyTo($restore.StandardInput.BaseStream)}finally{$inputFile.Dispose();$restore.StandardInput.Close()};$restore.WaitForExit();if($restore.ExitCode -ne 0){throw "MariaDB SQL restore failed: $($restore.StandardError.ReadToEnd())"}
    & $php (Join-Path $PSScriptRoot 'verify-mariadb-restore.php') $restoreDb $snapshotPath;if($LASTEXITCODE -ne 0){throw 'MariaDB backup restore verification failed'}
    Write-Output 'PASS MariaDB backup/restore: logical SQL restores into a new empty database with matching counts and balances.'
    Write-Output 'PASS MariaDB integration suite.'
}
finally {
    if($phpServer -and -not $phpServer.HasExited){Stop-Process -Id $phpServer.Id -Force}
    if($server -and -not $server.HasExited){Stop-Process -Id $server.Id -Force}
    $resolved=[IO.Path]::GetFullPath($tempRoot)
    $base=([IO.Path]::GetFullPath($env:TEMP)).TrimEnd([IO.Path]::DirectorySeparatorChar)+[IO.Path]::DirectorySeparatorChar
    if($resolved.StartsWith($base,[StringComparison]::OrdinalIgnoreCase)-and (Split-Path -Leaf $resolved).StartsWith('MizoraMariaDBIntegration-',[StringComparison]::Ordinal)){Remove-Item -LiteralPath $resolved -Recurse -Force -ErrorAction SilentlyContinue}else{throw "Refusing to remove unexpected test path $resolved"}
}
