import { test, expect } from '@playwright/test';
import * as Forge from './helpers/forge.js';

// #524. A Staff filter on the bar Kanban, Gantt and Calendar share: all staff,
// one person, or several. A task shows when any picked person has a part in it.

const RUN = `staff${ Date.now() }`;
const DAY = 86400000;
const on = ( offset ) => new Date( Date.now() + offset * DAY ).toISOString().slice( 0, 10 );

test( 'the staff filter narrows every view to the people picked', async ( { browser, baseURL } ) => {
  test.setTimeout( 300_000 );

  const admin = await Forge.signedIn( browser, baseURL, process.env.WP_ADMIN_USER ?? 'admin', process.env.WP_ADMIN_PASS ?? 'admin' );
  const { client, site } = await Forge.makeSite( admin.api, `Staff Co ${ RUN }`, RUN );
  const ana = await Forge.makePerson( admin.api, client.id, 'staff', `ana${ RUN }` );
  const bo = await Forge.makePerson( admin.api, client.id, 'staff', `bo${ RUN }` );

  const seat = async ( title, patch ) => {
    const made = await Forge.makeItem( admin.api, site.id, { title } );
    expect( made.status(), await made.text() ).toBe( 200 );
    const item = ( await made.json() ).item;
    const edited = await admin.api.patch( `/work-items/${ item.id }`, { ...patch, record_version: item.record_version } );
    expect( edited.status(), await edited.text() ).toBe( 200 );
  };
  // Dated, so the Gantt and the Calendar have somewhere to draw them.
  await seat( `Ana does ${ RUN }`, { primary_user_id: ana.id, planned_start: on( 0 ), planned_due: on( 1 ) } );
  await seat( `Bo checks ${ RUN }`, { reviewer_id: bo.id, planned_start: on( 0 ), planned_due: on( 1 ) } );
  await seat( `Nobody yet ${ RUN }`, { planned_start: on( 0 ), planned_due: on( 1 ) } );

  const page = await admin.context.newPage();
  await page.goto( '/blueworx-forge/' );
  await page.waitForSelector( '[data-testid="bwx-board"]' );
  await page.selectOption( '[data-testid="bwx-client-choice"]', site.id );

  const cards = page.getByTestId( 'bwx-card' );
  await expect( cards ).toHaveCount( 3 );

  const staff = page.getByTestId( 'bwx-filter-staff' );
  await expect( staff ).toContainText( 'All staff' );

  // It looks like the "Any kind" picker beside it (#614).
  await expect( staff ).toHaveText( 'All staff' );
  const look = ( box ) => box.evaluate( ( el ) => {
    const s = getComputedStyle( el );
    return { height: el.getBoundingClientRect().height, radius: s.borderRadius, size: s.fontSize, color: s.color, background: s.backgroundColor, arrow: s.backgroundImage };
  } );
  expect( await look( staff ) ).toEqual( await look( page.getByTestId( 'bwx-filter-type' ) ) );

  // One person.
  await staff.click();
  await page.getByTestId( `bwx-filter-staff-${ ana.id }` ).click();
  await expect( cards ).toHaveCount( 1 );
  await expect( cards ).toContainText( `Ana does ${ RUN }` );
  await expect( staff ).toContainText( `ana${ RUN }` );

  // Two: either of them.
  await page.getByTestId( `bwx-filter-staff-${ bo.id }` ).click();
  await expect( cards ).toHaveCount( 2 );
  await expect( page.getByTestId( 'bwx-board' ) ).not.toContainText( `Nobody yet ${ RUN }` );
  await expect( staff ).toContainText( '2 people' );
  await page.keyboard.press( 'Escape' );

  // The choice carries into the Gantt and the Calendar.
  await page.getByTestId( 'bwx-view-gantt' ).click();
  await expect( page.getByTestId( 'bwx-gantt' ) ).toBeVisible();
  await expect( page.getByTestId( 'bwx-gantt' ) ).toContainText( `Ana does ${ RUN }` );
  await expect( page.getByTestId( 'bwx-gantt' ) ).not.toContainText( `Nobody yet ${ RUN }` );

  await page.getByTestId( 'bwx-view-calendar' ).click();
  await expect( page.getByTestId( 'bwx-calendar' ) ).toBeVisible();
  await expect( page.getByTestId( 'bwx-calendar' ) ).toContainText( `Bo checks ${ RUN }` );
  await expect( page.getByTestId( 'bwx-calendar' ) ).not.toContainText( `Nobody yet ${ RUN }` );

  // Back to everyone.
  await staff.click();
  await page.getByTestId( 'bwx-filter-staff-all' ).click();
  await expect( staff ).toContainText( 'All staff' );
  await expect( page.getByTestId( 'bwx-calendar' ) ).toContainText( `Nobody yet ${ RUN }` );

  await page.close();
  await admin.context.close();
} );
