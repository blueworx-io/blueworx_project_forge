import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeItem } from './helpers/forge.js';

// The site picker: how sites are named in it, "All clients" at the top, and
// which site it opens on.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

async function clientWithSites(api, label, siteNames) {
  const client = (await (await api.post('/clients', { display_name: `${label} ${RUN_ID}`, timezone: 'Europe/London' })).json()).client;
  const sites = [];

  for (const name of siteNames) {
    const made = await api.post(`/clients/${client.id}/sites`, { name: `${name} ${RUN_ID}`, url: 'https://example.test' });
    expect(made.status(), await made.text()).toBe(200);
    sites.push((await made.json()).site);
  }

  return { client, sites };
}

test.describe('the site picker', () => {
  test('names a site once, offers All clients, and opens on the studio', async ({ browser, baseURL, page }) => {
    test.slow();

    const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
    const solo = await clientWithSites(admin.api, 'Solo Co', ['Solo site']);
    const pair = await clientWithSites(admin.api, 'Pair Co', ['First', 'Second']);

    const onSolo = (await (await makeItem(admin.api, solo.sites[0].id, { title: `Solo work ${RUN_ID}` })).json()).item;
    const onPair = (await (await makeItem(admin.api, pair.sites[1].id, { title: `Pair work ${RUN_ID}` })).json()).item;

    const listed = await admin.api.get('/client-sites');
    const studio = listed.sites.find((one) => one.studio);
    expect(studio, 'the studio has a site of its own').toBeTruthy();

    await signIn(page, ADMIN_USER, ADMIN_PASS);
    await page.goto('/blueworx-forge/');
    // A fresh memory: nothing chosen before on this browser.
    await page.evaluate(() => window.localStorage.removeItem('bwx-forge-site'));
    await page.goto('/blueworx-forge/');
    await page.waitForSelector('[data-testid="bwx-board"]');

    const picker = page.getByTestId('bwx-site');
    await expect(picker).toHaveValue(studio.id);

    const labels = await picker.locator('option').allTextContents();
    expect(labels[0]).toBe('All clients');
    expect(labels).toContain(`Solo Co ${RUN_ID}`);
    expect(labels).toContain(`Pair Co ${RUN_ID} — First ${RUN_ID}`);
    expect(labels).toContain(`Pair Co ${RUN_ID} — Second ${RUN_ID}`);
    expect(labels.some((one) => one.includes(`Solo site ${RUN_ID}`))).toBe(false);

    await picker.selectOption('all');
    const soloCard = page.locator(`[data-testid="bwx-card"][data-item="${onSolo.id}"]`);
    const pairCard = page.locator(`[data-testid="bwx-card"][data-item="${onPair.id}"]`);
    await expect(soloCard).toBeVisible();
    await expect(pairCard).toBeVisible();
    await expect(soloCard.getByTestId('bwx-card-client')).toHaveText(`Solo Co ${RUN_ID}`);
    await expect(pairCard.getByTestId('bwx-card-client')).toHaveText(`Pair Co ${RUN_ID}`);

    // Remembered across a reload.
    await page.reload();
    await page.waitForSelector('[data-testid="bwx-board"]');
    await expect(picker).toHaveValue('all');
    await expect(soloCard).toBeVisible();

    // Adding work under All clients asks which site, and defaults to the studio.
    await page.getByTestId('bwx-add').click();
    const siteField = page.getByTestId('bwx-new-site');
    await expect(siteField).toBeVisible();
    await expect(siteField).toHaveValue(studio.id);

    await admin.context.close();
  });
});
