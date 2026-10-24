import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #517. Work the client is reviewing is in nobody's My tasks: it stays on the
// Daily standup, and comes back to the doer's list once it leaves the client.

const RUN = `clientrev${ Date.now() }`;

test( 'a task with the client leaves My tasks, stays on the standup, and returns when it comes back', async ( { browser, baseURL } ) => {
  test.setTimeout( 300_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Client Review Co ${ RUN }`, RUN );
  const person = await Forge.makePerson( admin.api, client.id, 'staff', `cr${ RUN }` );

  const title = `With the client ${ RUN }`;
  const made = await Forge.makeItem( admin.api, site.id, { title } );
  expect( made.status(), await made.text() ).toBe( 200 );
  let item = ( await made.json() ).item;
  const edited = await admin.api.patch( `/work-items/${ item.id }`, { primary_user_id: person.id, reviewer_id: 'client', priority: 'urgent', record_version: item.record_version } );
  expect( edited.status(), await edited.text() ).toBe( 200 );
  item = ( await edited.json() ).item;
  const moved = await admin.api.post( `/work-items/${ item.id }/override`, { to: 'in-review', reason: 'Sent to the client for the test.', record_version: item.record_version } );
  expect( moved.status(), await moved.text() ).toBe( 200 );
  item = ( await moved.json() ).item;

  const me = await Forge.signedIn( browser, baseURL, person.login, Forge.PASSWORD );
  const page = await me.context.newPage();
  await page.goto( '/blueworx-forge/' );
  await page.getByTestId( 'bwx-screen-mytasks' ).click();

  const table = page.getByTestId( 'bwx-mytasks-table' );
  await expect( table ).toBeVisible( { timeout: 60_000 } );

  // Urgent would put it in Today, if it were theirs. It is not, in any view.
  await expect( table ).not.toContainText( title );
  await table.getByRole( 'button', { name: /^Everything/ } ).click();
  await expect( table ).not.toContainText( title );
  const everything = table.getByRole( 'button', { name: /^Everything/ } );
  expect( parseInt( ( await everything.innerText() ).replace( /\D/g, '' ), 10 ) ).toBe( 0 );

  // The standup still carries it, waiting on the client.
  const standup = await admin.api.get( '/standup' );
  expect( JSON.stringify( standup ) ).toContain( title );

  // Back from the client: it is the doer's again.
  const back = await admin.api.post( `/work-items/${ item.id }/override`, { to: 'in-development', reason: 'The client sent it back.', record_version: item.record_version } );
  expect( back.status(), await back.text() ).toBe( 200 );

  await page.reload();
  await page.getByTestId( 'bwx-screen-mytasks' ).click();
  await expect( table ).toBeVisible( { timeout: 60_000 } );
  await expect( table.locator( 'tbody tr, [data-testid="bwx-day-block"]', { hasText: title } ) ).toHaveCount( 1 );

  await page.close();
  await me.context.close();
  await admin.context.close();
} );
