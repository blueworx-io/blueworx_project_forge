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
$zipPath     = Join-Path $outputDir "$slug.zip"

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
if ( Test-Path $zipPath ) { Remove-Item -Force $zipPath }
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
