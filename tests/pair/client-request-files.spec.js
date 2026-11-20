import { test, expect } from '@playwright/test';
import { connectedPair, requireEnvironment } from './helpers/pair.js';

// A client sent a policy PDF with a request and it never arrived: the form
// took images only and dropped anything else without a word. A PDF now goes
// through, and a file that cannot be sent is said so, with the words still sent.

const RUN = `file${ Date.now() }`;
const PAGE = '/forge/#requests';

// A real, if empty, PDF: WordPress reads the file, not its name.
const PDF = Buffer.from( '%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n' );

test.beforeAll( requireEnvironment );

async function raise( page, title, file ) {
  const form = page.getByTestId( 'bwx-new-submission' );
  await form.getByRole( 'radio', { name: 'Request' } ).click();
  await form.getByRole( 'textbox', { name: /Title/ } ).fill( title );
  await form.getByRole( 'textbox', { name: /Problem or need/ } ).fill( 'File attached.' );
  await form.getByRole( 'textbox', { name: /Desired outcome/ } ).fill( 'It is on the site.' );
  await form.getByTestId( 'bwx-submission-file' ).setInputFiles( file );
  await form.getByRole( 'button', { name: /Submit/ } ).click();
}

test( 'a PDF sent with a request reaches the studio, and a file that cannot be sent is said so', async ( { browser } ) => {
  test.setTimeout( 360_000 );

  const pair = await connectedPair( browser, 'Filing Co', RUN );
  const page = await pair.clientSite.context.newPage();
  await page.goto( PAGE );
  const screen = page.getByTestId( 'bwx-requests' );
  await expect( screen ).toBeVisible();
  await expect( screen.getByRole( 'status' ) ).toHaveCount( 0, { timeout: 60_000 } );

  const withPdf = `Upload the officials policy ${ RUN }`;
  await raise( page, withPdf, { name: 'policy.pdf', mimeType: 'application/pdf', buffer: PDF } );
  await expect( page.getByTestId( 'bwx-submission' ).filter( { hasText: withPdf } ) ).toBeVisible( { timeout: 60_000 } );
  await expect( page.getByTestId( 'bwx-submission-file-refused' ) ).toHaveCount( 0 );

  const withText = `Use these notes ${ RUN }`;
  await raise( page, withText, { name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from( 'Some notes.' ) } );
  await expect( page.getByTestId( 'bwx-submission' ).filter( { hasText: withText } ) ).toBeVisible( { timeout: 60_000 } );
  await expect( page.getByTestId( 'bwx-submission-file-refused' ) ).toContainText( 'could not be attached' );

  // The studio has both requests: the PDF as a link it can open, the other
  // in the client's words with no file.
  const queue = ( await pair.studio.get( '/submissions' ) ).submissions;
  const pdf = queue.find( ( one ) => one.title === withPdf );
  expect( pdf.evidence ).toMatch( /File: https?:\/\/\S+\.pdf/ );
  const text = queue.find( ( one ) => one.title === withText );
  expect( text, 'the words still reach the studio' ).toBeTruthy();
  expect( text.evidence ).not.toMatch( /File:|Screenshot:/ );

  await page.close();
  await pair.close();
} );
