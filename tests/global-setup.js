import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { stageArtifact } from '../bin/stage-artifact.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));

// The plugin checks GitHub for updates on wp-admin screens, and WordPress itself
// calls api.wordpress.org. Both are blocking server-side requests on a
// single-threaded PHP test server: one slow answer, or none at all on a machine
// without network, and an admin page that normally takes two seconds takes
// thirty. Nothing in this suite tests those calls, so the harness is put offline
// for the duration by a must-use plugin — written here, alongside the tests that
// need it, rather than hidden in the shared harness every project uses.
const MU_PLUGIN = `<?php
/**
 * Test-only: written by tests/global-setup.js. Blocks outbound HTTP so the suite
 * neither waits on GitHub/WordPress.org nor depends on having a network at all.
 */

add_filter(
	'pre_http_request',
	static function () {
		return new WP_Error( 'bwx_forge_test_offline', 'Outbound HTTP is blocked during the test run.' );
	},
	0
);
`;

export default function globalSetup() {
  const contentDir = path.resolve(HERE, '..', '.wp-test', 'wp', 'wp-content');
  const muDir = path.join(contentDir, 'mu-plugins');

  // Absent when the run targets something this repo did not provision. Nothing to
  // do then — the specs still assert the same things, just against a site whose
  // outbound calls are its own business.
  if (!fs.existsSync(contentDir)) return;

  fs.mkdirSync(muDir, { recursive: true });
  fs.writeFileSync(path.join(muDir, 'bwx-forge-test-offline.php'), MU_PLUGIN);

  installClientArtifact(path.join(contentDir, 'plugins'));
}

// The harness links one plugin — the studio one. The client artifact is a
// separate plugin from the same repo, and the thing worth proving is that it
// installs and activates on the same WordPress without either one needing the
// other. Staged through bin/stage-artifact.mjs, the same code the two-instance
// harness uses, so there is one description of what a client site runs.
function installClientArtifact(pluginsDir) {
  const config = JSON.parse(
    fs.readFileSync(path.resolve(HERE, '..', 'bin', 'artifacts.json'), 'utf8')
  );

  stageArtifact('client', path.join(pluginsDir, config.artifacts.client.slug));
}
