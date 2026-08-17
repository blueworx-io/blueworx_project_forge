import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

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
  const muDir = path.resolve(HERE, '..', '.wp-test', 'wp', 'wp-content', 'mu-plugins');

  // Absent when the run targets something this repo did not provision. Nothing to
  // do then — the specs still assert the same things, just against a site whose
  // outbound calls are its own business.
  if (!fs.existsSync(path.dirname(muDir))) return;

  fs.mkdirSync(muDir, { recursive: true });
  fs.writeFileSync(path.join(muDir, 'bwx-forge-test-offline.php'), MU_PLUGIN);
}
