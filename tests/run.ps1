$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$php = (Get-Command php).Source
$node = (Get-Command node).Source

& $php (Join-Path $PSScriptRoot 'regression.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

$phpFiles = Get-ChildItem -Path (Join-Path $projectRoot 'ledger'), (Join-Path $projectRoot 'ops'), $PSScriptRoot -Filter *.php -File -Recurse
foreach ($file in $phpFiles) {
    & $php -l $file.FullName
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

$jsFiles = Get-ChildItem -Path (Join-Path $projectRoot 'ledger/assets') -Filter *.js -File
foreach ($file in $jsFiles) {
    & $node --check $file.FullName
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}
& $node --check (Join-Path $PSScriptRoot 'browser-smoke.mjs')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
Write-Output "PASS syntax: $($phpFiles.Count) PHP files; $($jsFiles.Count) JavaScript files."

& (Join-Path $PSScriptRoot 'http-smoke.ps1')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& (Join-Path $PSScriptRoot 'migrations.ps1')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& (Join-Path $PSScriptRoot 'mariadb-integration.ps1')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

& (Join-Path $PSScriptRoot 'package-smoke.ps1')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Output 'PASS all automated regression and smoke suites.'
