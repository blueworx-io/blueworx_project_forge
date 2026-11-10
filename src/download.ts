/** Hands a text file to the browser as a download. */
export function downloadText( name: string, text: string, type = 'text/csv;charset=utf-8' ): void {
  const url = URL.createObjectURL( new Blob( [ text ], { type } ) );
  const link = document.createElement( 'a' );

  link.href = url;
  link.download = name;
  document.body.appendChild( link );
  link.click();
  link.remove();
  setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}
