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
$zipPath     = Join-Path $projectRoot "$slug.zip"

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

# Overwrite any previous zip. ZipFile.CreateFromDirectory is more reliable than
# Compress-Archive on Windows (which intermittently fails when AV/indexer briefly
# locks just-written files). includeBaseDirectory=$true keeps the WP-standard
# `forge-project-management/` wrapping folder inside the archive.
if ( Test-Path $zipPath ) { Remove-Item -Force $zipPath }
Add-Type -AssemblyName System.IO.Compression.FileSystem

$attempt = 0
while ( $true ) {
    try {
        [System.IO.Compression.ZipFile]::CreateFromDirectory(
            $pluginDir, $zipPath,
            [System.IO.Compression.CompressionLevel]::Optimal, $true
        )
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
