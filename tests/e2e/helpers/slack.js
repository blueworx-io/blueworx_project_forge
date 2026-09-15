import { copyFileSync, existsSync, mkdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// A stand-in Slack webhook inside the test WordPress: Forge posts to it as
// it would to hooks.slack.com, and a spec reads back what arrived.

const ROOT = fileURLToPath(new URL('../../..', import.meta.url));
const WP = process.env.BWX_WP_DIR || join(ROOT, '.wp-test', 'wp');
const MU = join(WP, 'wp-content', 'mu-plugins');

export function installSlackStub() {
  if (!existsSync(WP)) {
    throw new Error(`No test WordPress at ${WP}; set BWX_WP_DIR or run npm run wp:up`);
  }

  mkdirSync(MU, { recursive: true });
  copyFileSync(join(ROOT, 'tests', 'support', 'slack-stub.php'), join(MU, 'bwx-forge-slack-stub.php'));
}

export function removeSlackStub() {
  rmSync(join(MU, 'bwx-forge-slack-stub.php'), { force: true });
}

/** The webhook URL a person pastes, unique per label so messages can be told apart. */
export function stubWebhook(baseURL, who) {
  return `${baseURL.replace(/\/$/, '')}/wp-json/bwx-forge-test/v1/slack/hook/${who}`;
}

/** Everything the stub has received, optionally for one person. */
export async function slackMessages(request, who = '') {
  const answer = await request.get('/wp-json/bwx-forge-test/v1/slack/messages');
  const all = await answer.json();

  return who ? all.filter((one) => one.who === who) : all;
}

export async function clearSlackMessages(request) {
  await request.delete('/wp-json/bwx-forge-test/v1/slack/messages');
}
