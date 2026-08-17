#!/usr/bin/env bash
#
# Build the deployable plugin zip from an explicit allowlist, then verify the
# artifact it just built.
#
#   bash bin/build-zip.sh [output-dir]      # default output-dir: parent of the repo
#
# WHY THIS EXISTS
# Updates ship as GitHub Releases — the foundation's release workflow builds the
# zip on a tag push. This script is for the one case that workflow cannot serve:
# a site's FIRST install, before any release exists. It replaced a PowerShell
# script that used .NET's ZipFile, which on Windows PowerShell 5.1 writes
# backslash entry paths; WordPress (Linux) then reports "Plugin file does not
# exist." on activate.
#
# WHAT ENFORCES THIS
# The allowlist below is a convenience, NOT a source of truth. The foundation
# checks what would ship on every pull request and again at release time, from
# scripts/plugin-zip-excludes.txt plus the exclude_paths input in .github/
# workflows/{ci,release}.yml. If the two ever disagree, the foundation is right
# and this one is stale.

set -euo pipefail

SLUG="forge-project-management"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${1:-$(cd "$ROOT/.." && pwd)}"

# ---------------------------------------------------------------------------
# THE ALLOWLIST — what ships. Allowlist, not denylist: a new dev directory is
# excluded by default rather than shipped because nobody remembered to add it.
# `assets` is the built Vite bundle WordPress serves; `src` is its TypeScript
# source and has no business on a live site.
# ---------------------------------------------------------------------------
INCLUDE=(
	"$SLUG.php"
	"uninstall.php"
	"CHANGELOG.md"
	"includes"
	"templates"
	"assets"
	"plugin-update-checker"
)

# Belt and braces. The allowlist alone already excludes these, so a hit here
# means one is nested inside a shipped directory — exactly the case a human
# misses. "vendor" is not listed: plugin-update-checker ships its own.
FORBIDDEN_SEGMENTS=( "src" "tests" "test-results" "docs" "bin" "node_modules" ".superpowers" ".github" ".git" ".wp-test" )
FORBIDDEN_FILES=( "*.spec.js" "*.ts" "*.tsx" "phpcs.xml*" "composer.json" "composer.lock" "package.json" "package-lock.json" "approved-deps.json" "playwright.config.js" "CLAUDE.md" ".gitignore" "*.zip" )

say() { printf '%s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

# --- pick an archiver that writes real zip entries with forward slashes --------
# GNU tar cannot write zip format at all, so a plain `tar` on PATH is checked for
# libarchive and otherwise rejected.
ZIP_TOOL=""
for candidate in "/c/Windows/System32/tar.exe" "$(command -v bsdtar || true)" "$(command -v tar || true)"; do
	[ -n "$candidate" ] && [ -x "$candidate" ] || continue
	if "$candidate" --version 2>&1 | grep -qi 'bsdtar\|libarchive'; then
		ZIP_TOOL="bsdtar:$candidate"
		break
	fi
done
if [ -z "$ZIP_TOOL" ] && command -v zip >/dev/null 2>&1; then
	ZIP_TOOL="zip:$(command -v zip)"
fi
[ -n "$ZIP_TOOL" ] || die "no zip-capable archiver found (need bsdtar or zip; GNU tar cannot write zip)"

TOOL_KIND="${ZIP_TOOL%%:*}"
TOOL_BIN="${ZIP_TOOL#*:}"
say "Archiver : $TOOL_KIND ($TOOL_BIN)"

VERSION="$(grep -oE "define\( 'FORGE_PM_VERSION', *'[^']+'" "$ROOT/$SLUG.php" | grep -oE "[0-9]+\.[0-9]+\.[0-9]+")"
[ -n "$VERSION" ] || die "could not read the plugin version from $SLUG.php"
say "Version  : $VERSION"

# The version lives in the FILENAME only. The folder inside the archive stays
# "$SLUG/" — WordPress identifies a plugin by that folder, so putting the version
# there would install a second copy of the plugin on every update.
ZIP="$OUT_DIR/$SLUG-$VERSION.zip"

# --- stage -------------------------------------------------------------------
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG"
for item in "${INCLUDE[@]}"; do
	[ -e "$ROOT/$item" ] || die "allowlisted path is missing from the repo: $item (did you run the build?)"
	cp -R "$ROOT/$item" "$STAGE/$SLUG/"
done

# --- build -------------------------------------------------------------------
mkdir -p "$OUT_DIR"
# Exactly one zip per plugin is ever present: an older build left beside the new
# one is how the wrong version reaches a live site.
rm -f "$OUT_DIR/$SLUG.zip" "$OUT_DIR"/"$SLUG"-*.zip
case "$TOOL_KIND" in
	bsdtar) ( cd "$STAGE" && "$TOOL_BIN" -a -c -f "$ZIP" "$SLUG" ) ;;
	zip)    ( cd "$STAGE" && "$TOOL_BIN" -q -r -X "$ZIP" "$SLUG" ) ;;
esac
[ -f "$ZIP" ] || die "no zip was produced at $ZIP"

# --- verify the artifact, not the intent -------------------------------------
if command -v unzip >/dev/null 2>&1; then
	ENTRIES="$(unzip -Z1 "$ZIP")"
elif [ "$TOOL_KIND" = "bsdtar" ]; then
	ENTRIES="$("$TOOL_BIN" -tf "$ZIP")"
else
	die "need unzip (or bsdtar) to list the zip — refusing to ship an unverified artifact"
fi

fail=0
check() { # check <description> <offending-entries>
	if [ -n "$2" ]; then
		printf 'FAIL: %s\n%s\n' "$1" "$(printf '%s\n' "$2" | sed 's/^/    /')" >&2
		fail=1
	else
		say "  ok: $1"
	fi
}

say "Verifying $ZIP"

# A backslash entry mis-extracts on a Linux host: WordPress then reports
# "Plugin file does not exist." on activate.
check "every entry uses forward slashes" "$(printf '%s\n' "$ENTRIES" | grep -F '\' || true)"
check "every entry is nested under $SLUG/" "$(printf '%s\n' "$ENTRIES" | grep -vE "^$SLUG/" || true)"

offenders=""
for seg in "${FORBIDDEN_SEGMENTS[@]}"; do
	hit="$(printf '%s\n' "$ENTRIES" | grep -E "(^|/)$(printf '%s' "$seg" | sed 's/\./\\./g')(/|$)" || true)"
	[ -n "$hit" ] && offenders="$offenders$hit"$'\n'
done
check "no development directories ship" "$(printf '%s' "$offenders" | sed '/^$/d')"

offenders=""
for pat in "${FORBIDDEN_FILES[@]}"; do
	hit="$(printf '%s\n' "$ENTRIES" | grep -E "(^|/)${pat//\*/[^/]*}$" || true)"
	[ -n "$hit" ] && offenders="$offenders$hit"$'\n'
done
check "no development files ship" "$(printf '%s' "$offenders" | sed '/^$/d')"

check "the main plugin file sits directly inside $SLUG/" \
	"$(printf '%s\n' "$ENTRIES" | grep -qxF "$SLUG/$SLUG.php" && true || echo "missing $SLUG/$SLUG.php")"

check "the built app bundle ships" \
	"$(printf '%s\n' "$ENTRIES" | grep -qxF "$SLUG/assets/js/forge-app.js" && true || echo "missing $SLUG/assets/js/forge-app.js — run npm run build")"

[ "$fail" -eq 0 ] || die "the zip is not shippable — see the failures above"

say ""
say "Built $ZIP ($SLUG $VERSION, $(printf '%s\n' "$ENTRIES" | grep -c . ) entries)"
