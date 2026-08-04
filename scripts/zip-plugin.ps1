# Packages the plugin into an installable zip containing ONLY the runtime files
# WordPress needs. node_modules, src/, and dev configs are never staged, so they
# can never end up in the zip — node_modules can stay in place for fast dev.
#
# Run via: npm run zip  (which builds fresh assets first, then invokes this script)

$ErrorActionPreference = 'Stop'

$slug        = 'forge-project-management'
$projectRoot = Split-Path -Parent $PSScriptRoot
$staging     = Join-Path $projectRoot 'dist-zip'
$pluginDir   = Join-Path $staging $slug
# The zip is written one level ABOVE the project folder (beside it), so it never
# lives inside the repo and is trivial to grab without digging into the project.
$outputDir   = Split-Path -Parent $projectRoot

# The version goes in the FILENAME only, so a zip on disk says which build it is.
# The folder inside the archive stays "$slug" with no version — WordPress installs
# to that folder name, so versioning it would install a second copy of the plugin
# on every update instead of replacing the first.
$header = ( Get-Content ( Join-Path $projectRoot "$slug.php" ) -TotalCount 20 ) -join "`n"
if ( $header -notmatch '(?m)^\s*\*\s*Version:\s*(\S+)' ) {
    throw "Could not read Version from $slug.php"
}
$version = $Matches[1]
$zipPath = Join-Path $outputDir "$slug-$version.zip"

# Only these items are shipped to WordPress.
$runtimeItems = @(
    "$slug.php",
    'includes',
    'templates',
    'assets'
)

# Fresh staging dir each run.
if ( Test-Path $staging ) { Remove-Item -Recurse -Force $staging }
New-Item -ItemType Directory -Path $pluginDir | Out-Null

foreach ( $item in $runtimeItems ) {
    $source = Join-Path $projectRoot $item
    if ( -not ( Test-Path $source ) ) {
        throw "Required runtime item not found: $item (did you run the build?)"
    }
    Copy-Item -Path $source -Destination $pluginDir -Recurse -Force
}

# Overwrite any previous zip, then build entries manually.
#
# We do NOT use ZipFile::CreateFromDirectory here: on Windows PowerShell 5.1
# (.NET Framework) it writes entry names with backslash (\) separators, which
# violates the ZIP spec. WordPress runs on Linux/PHP and then reads
# `forge-project-management\forge-project-management.php` as a single root-level
# filename, never finding the plugin folder — install fails with
# "Plugin file does not exist." Adding each entry with forward-slash (/) names
# (relative to the staging root so the `forge-project-management/` wrapper folder
# is included) produces an archive WordPress can unpack.
#
# Older builds are cleared out too, so exactly one zip per plugin is ever present
# and there is no doubt which one to install.
Get-ChildItem -Path $outputDir -Filter "$slug*.zip" -File -ErrorAction SilentlyContinue |
    Remove-Item -Force
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$base = ( Resolve-Path $staging ).Path.TrimEnd( '\' ) + '\'

$attempt = 0
while ( $true ) {
    try {
        $zip = [System.IO.Compression.ZipFile]::Open( $zipPath, [System.IO.Compression.ZipArchiveMode]::Create )
        try {
            Get-ChildItem -Path $pluginDir -Recurse -File | ForEach-Object {
                $entryName = $_.FullName.Substring( $base.Length ).Replace( '\', '/' )
                [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                    $zip, $_.FullName, $entryName,
                    [System.IO.Compression.CompressionLevel]::Optimal
                ) | Out-Null
            }
        } finally {
            $zip.Dispose()
        }
        break
    } catch [System.IO.IOException] {
        if ( ++$attempt -ge 5 ) { throw }
        if ( Test-Path $zipPath ) { Remove-Item -Force $zipPath }
        Start-Sleep -Milliseconds 500   # let the transient file lock clear
    }
}

# Leave only the zip behind.
Remove-Item -Recurse -Force $staging

Write-Host "Created $zipPath" -ForegroundColor Green
