import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';

// Reproduces the timing #294 describes: WordPress's login page moves focus to
// the username box on a 200ms timer, and on a slow server that timer fires
// between Playwright focusing the password box and typing into it.
//
// The timer is made to fire just after the password box takes focus — the
// worst possible instant — and at 1s if nothing has focused it, which is what
// a helper that waits for the focus to arrive will see.
test('signing in survives the login page focusing the username box mid-fill', async ({ page }) => {
  await page.addInitScript(() => {
    const original = window.setTimeout;
    window.setTimeout = (fn, ms, ...args) => {
      if (ms !== 200) return original(fn, ms, ...args);
      let fired = false;
      const fire = () => {
        if (fired) return;
        fired = true;
        fn();
      };
      document.addEventListener('focusin', (event) => {
        if (event.target?.id === 'user_pass') queueMicrotask(fire);
      }, true);
      return original(fire, 1000);
    };
  });

  await signIn(page);

  await expect(page.locator('#adminmenu')).toBeVisible();
});
