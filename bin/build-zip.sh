#!/usr/bin/env bash
#
# Build the deployable plugin zip from an explicit allowlist, then verify the
# artifact it just built.
#
#   bash bin/build-zip.sh [artifact] [output-dir]
#
#   artifact:   studio (default) | client | all
#   output-dir: default is the parent of the repo
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

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARTIFACT="${1:-studio}"
OUT_DIR="${2:-$(cd "$ROOT/.." && pwd)}"

# "all" is what the release publishes and what a human almost always wants:
# building one artifact and forgetting the other is how the two drift apart.
if [ "$ARTIFACT" = "all" ]; then
	for one in studio client; do
		bash "${BASH_SOURCE[0]}" "$one" "$OUT_DIR"
	done
	exit 0
fi

# The allowlists live in bin/artifacts.json, read by this script and enforced by
# bin/check-artifacts.mjs, so there is one list rather than two that agree until
# they don't. Refuse to build at all if that check is unhappy — a zip built from
# a broken allowlist is exactly the artifact nobody should be handed.
node "$ROOT/bin/check-artifacts.mjs" >/dev/null || {
	node "$ROOT/bin/check-artifacts.mjs" >&2 || true
	printf 'ERROR: the build allowlists are not shippable — refusing to build.\n' >&2
	exit 1
}

read_artifact() { node -e '
	const c = require(process.argv[1] + "/bin/artifacts.json").artifacts[process.argv[2]];
	if (!c) { console.error("no such artifact: " + process.argv[2]); process.exit(1); }
	const v = c[process.argv[3]];
	console.log(Array.isArray(v) ? v.join("\n") : v);
' "$ROOT" "$ARTIFACT" "$1"; }

SLUG="$(read_artifact slug)"
MAIN="$(read_artifact main)"
ART_ROOT="$(read_artifact root)"
mapfile -t INCLUDE < <(read_artifact include)
mapfile -t SHARED < <(read_artifact shared)

# Belt and braces. The allowlist alone already excludes these, so a hit here
# means one is nested inside a shipped directory — exactly the case a human
# misses. "vendor" is not listed: plugin-update-checker ships its own.
FORBIDDEN_SEGMENTS=( "src" "design" "tests" "test-results" "docs" "bin" "node_modules" ".superpowers" ".github" ".git" ".wp-test" )
FORBIDDEN_FILES=( "*.spec.js" "*.ts" "*.tsx" "phpcs.xml*" "phpunit.xml*" "composer.json" "composer.lock" "package.json" "package-lock.json" "approved-deps.json" "playwright.config.js" "CLAUDE.md" ".gitignore" "*.zip" )

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

# Read off the "Version:" header rather than a constant: the header is what
# WordPress and the release tag check both read, so it is the one that must be
# right, and each artifact names its constant differently anyway.
MAIN_PATH="$ROOT/$ART_ROOT/$MAIN"
[ -f "$MAIN_PATH" ] || die "the artifact's main file is missing: $ART_ROOT/$MAIN"
VERSION="$(grep -oE "^\s*\*?\s*Version:\s*[0-9]+\.[0-9]+\.[0-9]+" "$MAIN_PATH" | grep -oE "[0-9]+\.[0-9]+\.[0-9]+" | head -1)"
[ -n "$VERSION" ] || die "could not read the plugin version from $ART_ROOT/$MAIN"
say "Artifact : $ARTIFACT ($SLUG)"
say "Version  : $VERSION"

# The version lives in the FILENAME only. The folder inside the archive stays
# "$SLUG/" — WordPress identifies a plugin by that folder, so putting the version
# there would install a second copy of the plugin on every update.
ZIP="$OUT_DIR/$SLUG-$VERSION.zip"

# --- stage -------------------------------------------------------------------
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG"
# The artifact's own files, taken from its own root and nowhere else.
for item in "${INCLUDE[@]}"; do
	[ -e "$ROOT/$ART_ROOT/$item" ] || die "allowlisted path is missing from the repo: $ART_ROOT/$item (did you run the build?)"
	cp -R "$ROOT/$ART_ROOT/$item" "$STAGE/$SLUG/"
done
# The few paths both artifacts take from the repo root. Kept to a closed list in
# bin/check-artifacts.mjs, because this is the one door out of the artifact's
# own directory and widening it silently is how studio code reaches a client.
for item in "${SHARED[@]}"; do
	[ -n "$item" ] || continue
	[ -e "$ROOT/$item" ] || die "shared path is missing from the repo: $item"
	cp -R "$ROOT/$item" "$STAGE/$SLUG/"
done

# --- build -------------------------------------------------------------------
mkdir -p "$OUT_DIR"
# Exactly one zip per plugin is ever present: an older build left beside the new
# one is how the wrong version reaches a live site.
# Anchored on a digit, not on "$SLUG-*": "blueworx-forge-*" also matches
# "blueworx-forge-client-2.2.0.zip", so the loose glob would have the studio
# build quietly delete the client artifact sitting beside it.
rm -f "$OUT_DIR/$SLUG.zip" "$OUT_DIR"/"$SLUG"-[0-9]*.zip
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
	"$(printf '%s\n' "$ENTRIES" | grep -qxF "$SLUG/$MAIN" && true || echo "missing $SLUG/$MAIN")"

# A client site without the update checker could never receive a fix, so its
# absence is a shipping failure rather than a missing nicety.
check "the update checker ships" \
	"$(printf '%s\n' "$ENTRIES" | grep -qxF "$SLUG/plugin-update-checker/plugin-update-checker.php" && true || echo "missing $SLUG/plugin-update-checker/plugin-update-checker.php")"

if [ "$ARTIFACT" = "studio" ]; then
	check "the built app bundle ships" \
		"$(printf '%s\n' "$ENTRIES" | grep -qxF "$SLUG/assets/js/blueworx-forge.js" && true || echo "missing $SLUG/assets/js/blueworx-forge.js — run npm run build")"
fi

if [ "$ARTIFACT" = "client" ]; then
	# ARCH-1's actual guarantee, read off the built archive rather than off the
	# allowlist that produced it. The allowlist is checked separately; this is
	# the thing a client's server would physically contain.
	# Named for what could only have come from the studio tree. The client has
	# its own includes/Plugin.php, so matching on file name alone would flag the
	# artifact's own code; these are files the client half simply does not have.
	check "no studio code ships to a client" \
		"$(printf '%s\n' "$ENTRIES" | grep -E "^$SLUG/(blueworx-forge\.php|includes/(Rest/|Frontend\.php)|templates/|assets/)" || true)"

	# The studio plugin's namespace has no files on a client site, so a reference
	# to it is a boundary crossing that would fatal there rather than here.
	studio_refs=""
	while IFS= read -r php; do
		[ -n "$php" ] || continue
		hit="$(grep -lE 'Blueworx\\Forge\\(Rest|Frontend|Plugin)|BWX_FORGE_(VERSION|SLUG|PATH|URL|FILE)\b' "$php" || true)"
		[ -n "$hit" ] && studio_refs="$studio_refs$hit"$'\n'
	done < <(find "$STAGE/$SLUG" -name '*.php' -not -path '*/plugin-update-checker/*')
	check "no client file reaches for studio code" "$(printf '%s' "$studio_refs" | sed '/^$/d')"
fi

[ "$fail" -eq 0 ] || die "the zip is not shippable — see the failures above"

say ""
say "Built $ZIP ($SLUG $VERSION, $(printf '%s\n' "$ENTRIES" | grep -c . ) entries)"
