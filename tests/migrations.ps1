$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$tempRoot = Join-Path $env:TEMP ('MizoraMigrationTest-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $tempRoot | Out-Null
$database = Join-Path $tempRoot 'legacy-v4.sqlite'
$maintenance = Join-Path $tempRoot 'maintenance'
$php = (Get-Command php).Source
$manage = Join-Path $projectRoot 'ops/manage.php'
$fixture = Join-Path $PSScriptRoot 'create-v4-fixture.php'
$verify = Join-Path $PSScriptRoot 'verify-v4-migration.php'
$ledgerRoot = Join-Path $projectRoot 'ledger'

try {
    & $php $fixture $database
    if ($LASTEXITCODE -ne 0) { throw 'Could not create the disposable v4 database fixture' }
    Set-Content -LiteralPath $maintenance -Value 'maintenance' -NoNewline
    foreach ($pass in 1..2) {
        $backup = & $php $manage backup "--database=$database" "--directory=$tempRoot" "--public=$ledgerRoot"
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $backup)) { throw "Backup pass $pass failed" }
        & $php $manage migrate "--database=$database" "--backup=$backup" "--maintenance-file=$maintenance" --allow-write
        if ($LASTEXITCODE -ne 0) { throw "Offline migration pass $pass failed" }
        & $php $verify $database
        if ($LASTEXITCODE -ne 0) { throw "Migration verification pass $pass failed" }
    }
    Write-Output 'PASS offline migration from v4 to v7, preservation checks, repeat migration idempotency.'
}
finally {
    $resolved = [IO.Path]::GetFullPath($tempRoot)
    $tempBase = ([IO.Path]::GetFullPath($env:TEMP)).TrimEnd([IO.Path]::DirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
    if ($resolved.StartsWith($tempBase, [StringComparison]::OrdinalIgnoreCase) -and (Split-Path -Leaf $resolved).StartsWith('MizoraMigrationTest-', [StringComparison]::Ordinal)) {
        Remove-Item -LiteralPath $resolved -Recurse -Force -ErrorAction SilentlyContinue
    } else { throw "Refusing to remove unexpected test path: $resolved" }
}
