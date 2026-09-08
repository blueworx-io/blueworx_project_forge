import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// bin/build-zip.sh used to stage a shared path with `cp -R "$ROOT/$item"
// "$STAGE/$SLUG/"`, which keeps only the last segment. Every shared path then
// sat at the repo root, so there was no structure to lose. assets/fonts has
// structure, and losing it puts the stylesheet and its fonts in different
// places on a client site.
//
// This asserts on the script's text rather than its behaviour, and says so:
// build-zip.sh is a shell script with no seam to call, and copying its loop
// into the test would only prove the copy works. The behavioural proof is
// Task 3 Step 8, which builds a real client zip and lists the entries — that
// is the check that would actually catch a regression here.
test('build-zip.sh stages a shared path at its own relative path', () => {
  const script = readFileSync('bin/build-zip.sh', 'utf8');

  assert.match(
    script,
    /mkdir -p "\$STAGE\/\$SLUG\/\$\(dirname "\$item"\)"/,
    'the staging loop must create the shared path\'s parent directory'
  );
  assert.match(
    script,
    /cp -R "\$ROOT\/\$item" "\$STAGE\/\$SLUG\/\$item"/,
    'the staging loop must copy to the full relative path, not to the slug root'
  );
  assert.doesNotMatch(
    script,
    /cp -R "\$ROOT\/\$item" "\$STAGE\/\$SLUG\/"$/m,
    'the flattening form must be gone, not merely joined by the new one'
  );
});
