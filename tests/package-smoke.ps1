$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$tempRoot = Join-Path $env:TEMP ('MizoraPackageTest-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $tempRoot | Out-Null
$archivePath = Join-Path $tempRoot 'release.zip'
$php = (Get-Command php).Source

try {
    & $php (Join-Path $projectRoot 'ops/build-package.php') $archivePath
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $archivePath)) { throw 'Allowlisted package build failed' }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [IO.Compression.ZipFile]::OpenRead($archivePath)
    try {
        $names = @($archive.Entries | ForEach-Object FullName)
        foreach ($required in @('ledger/index.php','ledger/.htaccess','ledger/database.php','ledger/schema-mariadb.php','ledger/mariadb-backup.php','ops/.htaccess','ops/hostinger-shared.md','ops/README.md','SHA256SUMS')) {
            if ($required -notin $names) { throw "Package is missing $required" }
        }
        foreach ($excluded in @('ledger/data/ledger.sqlite','tests/http-smoke.ps1','ops/backup.env','ops/migrate-sqlite-to-mariadb.php')) {
            if ($excluded -in $names) { throw "Package unexpectedly contains $excluded" }
        }
    } finally { $archive.Dispose() }
    Write-Output 'PASS release package includes runtime/deployment guidance and excludes data, tests and secrets.'
}
finally {
    $resolved = [IO.Path]::GetFullPath($tempRoot)
    $tempBase = ([IO.Path]::GetFullPath($env:TEMP)).TrimEnd([IO.Path]::DirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
    if ($resolved.StartsWith($tempBase, [StringComparison]::OrdinalIgnoreCase) -and (Split-Path -Leaf $resolved).StartsWith('MizoraPackageTest-', [StringComparison]::Ordinal)) {
        Remove-Item -LiteralPath $resolved -Recurse -Force -ErrorAction SilentlyContinue
    } else { throw "Refusing to remove unexpected test path: $resolved" }
}
