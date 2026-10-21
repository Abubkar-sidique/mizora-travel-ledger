param([int]$Port = 0)
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$ledgerRoot = Join-Path $projectRoot 'ledger'
$tempRoot = Join-Path $env:TEMP ('MizoraAuditTest-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $tempRoot | Out-Null
$database = Join-Path $tempRoot 'ledger.sqlite'
$stdout = Join-Path $tempRoot 'server.out.log'
$stderr = Join-Path $tempRoot 'server.err.log'
$php = (Get-Command php).Source
$server = $null

function Assert-That([bool]$condition, [string]$message) {
    if (-not $condition) { throw "FAIL: $message" }
}
function Get-Csrf([string]$html) {
    $value = [regex]::Match($html, 'name="t" value="([^"]+)"').Groups[1].Value
    if (-not $value) { throw 'CSRF token was not rendered' }
    return $value
}
function Get-RequestKey([string]$html) {
    $value = [regex]::Match($html, '(?s)data-ledger-entry.*?name="request_key" value="([a-f0-9]{48})"').Groups[1].Value
    if (-not $value) { throw 'Ledger idempotency key was not rendered' }
    return $value
}
function Invoke-Get($session, [string]$path) {
    return Invoke-WebRequest -Uri ($script:base + $path) -WebSession $session -TimeoutSec 10
}
function Invoke-Post($session, [string]$path, [hashtable]$body) {
    return Invoke-WebRequest -Uri ($script:base + $path) -Method Post -WebSession $session -Body $body -TimeoutSec 10
}

try {
    if ($Port -eq 0) {
        $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
        $listener.Start(); $Port = $listener.LocalEndpoint.Port; $listener.Stop()
    }
    $script:base = "http://127.0.0.1:$Port/"
    $env:LEDGER_DB = $database
    $server = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$Port", 'router.php') -WorkingDirectory $ledgerRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr

    $master = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $ready = $false
    for ($i = 0; $i -lt 50; $i++) {
        if ($server.HasExited) { throw "PHP server exited. $([IO.File]::ReadAllText($stderr))" }
        try { $response = Invoke-Get $master '?p=login'; $ready = $true; break } catch { Start-Sleep -Milliseconds 200 }
    }
    Assert-That $ready 'PHP server starts and serves the application'
    Assert-That ($response.Content -match 'Master Setup') 'First-run setup is shown on an empty database'
    $token = Get-Csrf $response.Content
    $null = Invoke-Post $master '' @{ t=$token; username='audit.master'; password='Audit-Only-Strong-Password-731' }
    $response = Invoke-Get $master '?p=login'
    $token = Get-Csrf $response.Content
    $null = Invoke-Post $master '?p=login' @{ t=$token; username='audit.master'; password='Audit-Only-Strong-Password-731' }
    $response = Invoke-Get $master '?p=agencies'
    Assert-That ($response.Content -match 'Travel Agencies') 'Master authentication reaches agency administration'

    $token = Get-Csrf $response.Content
    foreach ($number in 1..2) {
        $null = Invoke-Post $master '?p=agencies' @{ t=$token; act='new'; name="Audit Agency $number"; phone=''; username="audit.owner$number"; password='Audit-Only-Owner-Password-731' }
        $response = Invoke-Get $master '?p=agencies'
        $token = Get-Csrf $response.Content
    }
    Assert-That ($response.Content -match 'Audit Agency 2') 'Master can create multiple isolated agencies'
    foreach ($page in @('?p=backup', '?p=profile')) { Assert-That ((Invoke-Get $master $page).StatusCode -eq 200) "Master page loads: $page" }

    $owner1 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $response = Invoke-Get $owner1 '?p=login'; $token = Get-Csrf $response.Content
    $null = Invoke-Post $owner1 '?p=login' @{ t=$token; username='audit.owner1'; password='Audit-Only-Owner-Password-731' }
    foreach ($page in @('?p=home', '?p=accounts', '?p=reports', '?p=audit', '?p=profile')) { Assert-That ((Invoke-Get $owner1 $page).StatusCode -eq 200) "Owner page loads: $page" }

    $response = Invoke-Get $owner1 '?p=accounts'; $token = Get-Csrf $response.Content
    $null = Invoke-Post $owner1 '?p=accounts' @{ t=$token; act='acc'; name='Audit <img src=x onerror=alert(1)>'; phone=''; kind='customer'; opening='25.00'; opening_side='dr' }
    $response = Invoke-Get $owner1 '?p=accounts'
    Assert-That ($response.Content -match '&lt;img src=x onerror=alert\(1\)&gt;') 'Account names are HTML-escaped'
    $account1 = [regex]::Match($response.Content, 'href="\?p=acc&id=(\d+)"').Groups[1].Value
    Assert-That ([bool]$account1) 'Agency one account was created'

    $owner2 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $response2 = Invoke-Get $owner2 '?p=login'; $token2 = Get-Csrf $response2.Content
    $null = Invoke-Post $owner2 '?p=login' @{ t=$token2; username='audit.owner2'; password='Audit-Only-Owner-Password-731' }
    $response2 = Invoke-Get $owner2 '?p=accounts'; $token2 = Get-Csrf $response2.Content
    $null = Invoke-Post $owner2 '?p=accounts' @{ t=$token2; act='acc'; name='Private Agency Two'; phone=''; kind='customer'; opening='0'; opening_side='dr' }
    $response2 = Invoke-Get $owner2 '?p=accounts'
    $account2 = [regex]::Match($response2.Content, 'href="\?p=acc&id=(\d+)"').Groups[1].Value
    Assert-That ([bool]$account2) 'Agency two account was created'
    $crossTenant = Invoke-Get $owner1 "?p=acc&id=$account2"
    Assert-That ($crossTenant.Content -notmatch 'Private Agency Two') 'Tenant cannot view another agency account'
    $ownerAudit = Invoke-Get $owner1 '?p=audit'
    Assert-That ($ownerAudit.Content -match 'Activity Log' -and $ownerAudit.Content -notmatch ">account #$account2<") 'Activity log is available and hides another agency account records'

    $chromePath = Join-Path $env:ProgramFiles 'Google/Chrome/Application/chrome.exe'
    if (Test-Path -LiteralPath $chromePath) {
        $browserScript = Join-Path $PSScriptRoot 'browser-smoke.mjs'
        $browserProfile = Join-Path $tempRoot 'chrome-profile'
        & (Get-Command node).Source $browserScript $script:base $chromePath $browserProfile
        if ($LASTEXITCODE -ne 0) { throw 'Headless Chrome browser smoke failed' }
        & (Get-Command node).Source $browserScript $script:base $chromePath (Join-Path $tempRoot 'chrome-master-profile') master
        if ($LASTEXITCODE -ne 0) { throw 'Headless Chrome master browser smoke failed' }
    } else { Write-Output 'SKIP browser smoke: Chrome executable is not installed.' }

    $response = Invoke-Get $owner1 "?p=acc&id=$account1"
    Assert-That ($response.Content -match '25\.00 Dr') 'Opening balance is shown before transactions'
    $token = Get-Csrf $response.Content; $key = Get-RequestKey $response.Content
    $debit = @{ t=$token; request_key=$key; act='ent'; account_id=$account1; type='dr'; cat='ticket_sold'; amount='100.00'; edate='2026-10-18'; note='=2+2'; ref='PNR-AUDIT' }
    $null = Invoke-Post $owner1 "?p=acc&id=$account1" $debit
    $response = Invoke-Get $owner1 "?p=acc&id=$account1"
    Assert-That ($response.Content -match '125\.00 Dr') 'Debit posting adds to opening balance correctly'

    $token = Get-Csrf $response.Content; $key = Get-RequestKey $response.Content
    $credit = @{ t=$token; request_key=$key; act='ent'; account_id=$account1; type='cr'; cat='payment_received'; amount='40.00'; edate='2026-10-18'; note='partial settlement'; ref='RCPT-AUDIT'; linked_entry_id='1' }
    $null = Invoke-Post $owner1 "?p=acc&id=$account1" $credit
    $response = Invoke-Get $owner1 "?p=acc&id=$account1"
    Assert-That ($response.Content -match '85\.00 Dr') 'Linked credit reduces the financial balance correctly'

    $token = Get-Csrf $response.Content; $key = Get-RequestKey $response.Content
    $overpay = @{ t=$token; request_key=$key; act='ent'; account_id=$account1; type='cr'; cat='payment_received'; amount='70.00'; edate='2026-10-18'; note='over allocation'; ref='RCPT-OVER'; linked_entry_id='1' }
    $overpayResponse = Invoke-Post $owner1 "?p=acc&id=$account1" $overpay
    $response = Invoke-Get $owner1 "?p=acc&id=$account1"
    Assert-That ($response.Content -match '85\.00 Dr' -and $overpayResponse.Content -match 'exceeds remaining amount') 'Over-allocation is rejected without changing the balance'
    $period = Invoke-Get $owner1 "?p=acc&id=$account1&from=2025-01-01&to=2025-12-31"
    Assert-That ($period.Content -match '25\.00 Dr' -and $period.Content -match 'No transactions in the selected period') 'Date filtering carries forward the opening balance and explains an empty period'

    $csv = Invoke-Get $owner1 "?p=acc&id=$account1&csv=1"
    Assert-That ($csv.Content -match "'=2\+2") 'CSV export neutralizes formula-like user text'
    Assert-That ($csv.Content -match '85\.00 Dr') 'CSV closing balance matches the ledger'
    $print = Invoke-Get $owner1 "?p=acc&id=$account1&print=1"
    Assert-That ($print.Content -match '85\.00') 'Print statement includes the correct closing balance'

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $xlsxPath = Join-Path $tempRoot 'statement.xlsx'
    Invoke-WebRequest -Uri ($script:base + "?p=acc&id=$account1&xlsx=1") -WebSession $owner1 -OutFile $xlsxPath -TimeoutSec 20
    $zip = [IO.Compression.ZipFile]::OpenRead($xlsxPath)
    try { Assert-That ([bool]$zip.GetEntry('xl/worksheets/sheet1.xml')) 'Excel export is a readable XLSX archive' } finally { $zip.Dispose() }

    $response = Invoke-Get $owner1 '?p=accounts'; $token = Get-Csrf $response.Content
    $null = Invoke-Post $owner1 '?p=accounts' @{ t=$token; act='acc'; name='Operating Bank'; phone=''; kind='bank'; opening='500.00'; opening_side='dr' }
    $response = Invoke-Get $owner1 '?p=accounts'; $token = Get-Csrf $response.Content
    $null = Invoke-Post $owner1 '?p=accounts' @{ t=$token; act='acc'; name='Cash Drawer'; phone=''; kind='cash'; opening='0'; opening_side='dr' }
    $response = Invoke-Get $owner1 '?p=accounts'
    Assert-That ($response.Content -match 'Bank' -and $response.Content -match 'Cash') 'Dedicated cash/bank ledger account types can be created'
    $cashAccount = [regex]::Match($response.Content, 'href="\?p=acc&id=(\d+)"').Groups[1].Value
    $transferIds = (& $php (Join-Path $PSScriptRoot 'list-cash-bank-ids.php') $database).Split('|')
    Assert-That ($transferIds.Count -eq 2) 'Cash/bank transfer test has two tenant-owned accounts'
    $cashAccount = $transferIds[0]
    $response = Invoke-Get $owner1 '?p=accounts'; $token = Get-Csrf $response.Content
    $transferKey = [regex]::Match($response.Content, 'name="request_key" value="([a-f0-9]{48})"').Groups[1].Value
    $null = Invoke-Post $owner1 '?p=accounts' @{ t=$token; request_key=$transferKey; act='transfer'; from_account_id=$transferIds[0]; to_account_id=$transferIds[1]; amount='125.50'; edate='2026-10-18'; note='Till deposit' }
    $fromLedger = Invoke-Get $owner1 "?p=acc&id=$($transferIds[0])"; $toLedger = Invoke-Get $owner1 "?p=acc&id=$($transferIds[1])"
    Assert-That ($fromLedger.Content -match 'TRF-' -and $toLedger.Content -match 'TRF-' -and $fromLedger.Content -match '125\.50' -and $toLedger.Content -match '125\.50') 'Cash/bank transfers create matching entries in both ledgers'
    $response = Invoke-Get $owner1 "?p=acc&id=$cashAccount"; $token=Get-Csrf $response.Content; $key=Get-RequestKey $response.Content
    $null = Invoke-Post $owner1 "?p=acc&id=$cashAccount" @{ t=$token; request_key=$key; act='ent'; account_id=$cashAccount; type='dr'; cat='ticket_sold'; amount='100.00'; edate='2026-10-18'; note='Refund regression ticket'; ref='RFD-1' }
    $references = (Invoke-Get $owner1 "?p=references&id=$cashAccount&side=cr&s=RFD-1").Content | ConvertFrom-Json
    $sourceEntry = $references.items | Where-Object { $_.label -match 'RFD-1' } | Select-Object -First 1
    Assert-That ([bool]$sourceEntry) 'Original sale is searchable for linking a refund'
    $response = Invoke-Get $owner1 "?p=acc&id=$cashAccount"; $token=Get-Csrf $response.Content; $key=Get-RequestKey $response.Content
    $refundResponse = Invoke-Post $owner1 "?p=acc&id=$cashAccount" @{ t=$token; request_key=$key; act='ent'; account_id=$cashAccount; type='cr'; cat='ticket_refund'; amount='25.00'; edate='2026-10-18'; note='Partial ticket refund'; ref='RFD-CR-1'; linked_entry_id=$sourceEntry.id }
    if ($refundResponse.Content -match 'exceeds remaining amount|Unable to save|Invalid original') { throw "Refund posting rejected: $(($refundResponse.Content | Select-String -Pattern 'exceeds remaining amount|Unable to save|Invalid original' -AllMatches).Matches.Value -join '; ')" }
    $response = Invoke-Get $owner1 "?p=acc&id=$cashAccount"
    Assert-That ($response.Content -match 'Against entry #') 'Ticket refund is retained as a traceable linked entry'
    $token=Get-Csrf $response.Content; $key=Get-RequestKey $response.Content
    $overRefund = Invoke-Post $owner1 "?p=acc&id=$cashAccount" @{ t=$token; request_key=$key; act='ent'; account_id=$cashAccount; type='cr'; cat='ticket_refund'; amount='80.00'; edate='2026-10-18'; note='Excess refund blocked'; ref='RFD-OVER'; linked_entry_id=$sourceEntry.id }
    Assert-That ($overRefund.Content -match 'Refund exceeds remaining amount') 'Linked refund cannot exceed the original sale after prior allocations'
    $response = Invoke-Get $owner1 "?p=acc&id=$cashAccount"; $token=Get-Csrf $response.Content; $key=Get-RequestKey $response.Content
    $null = Invoke-Post $owner1 "?p=acc&id=$cashAccount" @{ t=$token; request_key=$key; act='ent'; account_id=$cashAccount; type='dr'; cat='commission_earned'; amount='100.00'; edate='2026-10-18'; note='Supplier commission'; ref='COMM-1' }
    $response = Invoke-Get $owner1 "?p=acc&id=$cashAccount"; $token=Get-Csrf $response.Content; $key=Get-RequestKey $response.Content
    $null = Invoke-Post $owner1 "?p=acc&id=$cashAccount" @{ t=$token; request_key=$key; act='ent'; account_id=$cashAccount; type='cr'; cat='expense_paid'; amount='35.00'; edate='2026-10-18'; note='Office expense'; ref='EXP-1' }
    $response = Invoke-Get $owner1 "?p=acc&id=$cashAccount"; $token=Get-Csrf $response.Content; $key=Get-RequestKey $response.Content
    $null = Invoke-Post $owner1 "?p=acc&id=$cashAccount" @{ t=$token; request_key=$key; act='ent'; account_id=$cashAccount; type='dr'; cat='ticket_sold'; amount='100.00'; currency='USD'; fx_rate='280.500000'; edate='2026-10-18'; note='Foreign currency ticket'; ref='FX-1' }
    $response = Invoke-Get $owner1 "?p=acc&id=$cashAccount"
    Assert-That ($response.Content -match 'USD 100\.00' -and $response.Content -match 'PKR 28,050\.00') 'Foreign sale stores the original amount and posts its fixed-point PKR equivalent'
    $csvFx = Invoke-Get $owner1 "?p=acc&id=$cashAccount&csv=1"
    Assert-That ($csvFx.Content -match 'USD 100\.00 @ 280\.5 PKR = PKR 28,050\.00') 'Statement export preserves the original currency and applied rate'
    $report = Invoke-Get $owner1 '?p=reports&y=2026'
    Assert-That ($report.Content -match 'Service Margin Estimate' -and $report.Content -match 'PKR 28,290\.00' -and $report.Content -match 'Management estimate') 'Service margin includes converted foreign sale, linked refund, commission and expense amounts and labels the estimate limitation'

    try {
        $null = Invoke-Post $owner1 "?p=acc&id=$account1" @{ act='delacc'; id=$account1 }
        throw 'POST without a CSRF token unexpectedly succeeded'
    } catch {
        $status = 0
        if ($_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode }
        Assert-That ($status -eq 419) 'State-changing requests require a valid CSRF token'
    }

    $master = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $response = Invoke-Get $master '?p=login'; $token = Get-Csrf $response.Content
    $null = Invoke-Post $master '?p=login' @{ t=$token; username='audit.master'; password='Audit-Only-Strong-Password-731' }
    $backupPath = Join-Path $tempRoot 'backup.sqlite'
    Invoke-WebRequest -Uri ($script:base + '?p=backup&download=1') -WebSession $master -OutFile $backupPath -TimeoutSec 20
    $stream = [IO.File]::OpenRead($backupPath)
    try { $header = New-Object byte[] 15; $null = $stream.Read($header,0,$header.Length); Assert-That ([Text.Encoding]::ASCII.GetString($header) -eq 'SQLite format 3') 'Online backup download is a SQLite database' } finally { $stream.Dispose() }

    $manage = Join-Path $projectRoot 'ops/manage.php'
    $managedBackup = & $php $manage backup "--database=$database" "--directory=$tempRoot" "--public=$ledgerRoot"
    Assert-That ($LASTEXITCODE -eq 0 -and (Test-Path -LiteralPath $managedBackup)) 'CLI backup creates a consistent snapshot'
    $restoredDatabase = Join-Path $tempRoot 'restored.sqlite'
    $restoreResult = & $php $manage restore-check "--backup=$managedBackup" "--destination=$restoredDatabase" "--public=$ledgerRoot"
    Assert-That ($LASTEXITCODE -eq 0 -and $restoreResult -match 'PASS restored') 'Restore-check verifies integrity, foreign keys, checksum and rows'

    Write-Output 'PASS HTTP smoke: setup, authentication, tenant isolation, activity log, cash/bank ledgers, foreign currency conversion/export, travel margin estimate, XSS, CSRF, debit/credit, allocation, CSV, print, XLSX, online backup, CLI backup and verified restore.'
}
finally {
    if ($server -and -not $server.HasExited) { Stop-Process -Id $server.Id -Force }
    Remove-Item Env:LEDGER_DB -ErrorAction SilentlyContinue
    $resolved = [IO.Path]::GetFullPath($tempRoot)
    $tempBase = ([IO.Path]::GetFullPath($env:TEMP)).TrimEnd([IO.Path]::DirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
    if ($resolved.StartsWith($tempBase, [StringComparison]::OrdinalIgnoreCase) -and (Split-Path -Leaf $resolved).StartsWith('MizoraAuditTest-', [StringComparison]::Ordinal)) {
        Remove-Item -LiteralPath $resolved -Recurse -Force -ErrorAction SilentlyContinue
    } else { throw "Refusing to remove unexpected test path: $resolved" }
}
