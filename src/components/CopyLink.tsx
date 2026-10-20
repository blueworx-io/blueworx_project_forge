import { useEffect, useState } from 'react';
import { Link } from 'lucide-react';
import { forgeData } from '../api';
import { IconButton, TextInput } from '../kit';

/** How long "Link copied" stays, so the next copy is announced afresh. */
const SAID_FOR = 4000;

/**
 * Copies a link to an item (#559). Where the browser will not allow the
 * clipboard, the link is shown in a field to copy by hand instead.
 */
export function CopyLink( { itemId }: { itemId: string } ) {
  const [ done, setDone ] = useState( false );
  const [ manual, setManual ] = useState( false );
  const url = `${ forgeData()?.appUrl ?? '' }#item=${ encodeURIComponent( itemId ) }`;

  // Cleared after a few seconds, so a second copy is announced again.
  useEffect( () => {
    if ( ! done ) return undefined;

    const timer = window.setTimeout( () => setDone( false ), SAID_FOR );

    return () => window.clearTimeout( timer );
  }, [ done ] );

  async function copy() {
    try {
      await navigator.clipboard.writeText( url );
      setManual( false );
      setDone( true );
    } catch {
      setDone( false );
      setManual( true );
    }
  }

  return (
    <>
      <IconButton icon={ Link } label="Copy link" data-testid="bwx-copy-link" data-link={ url } onClick={ () => void copy() } />
      <span className="bwx-visually-hidden" role="status" aria-live="polite" data-testid="bwx-copy-link-done">
        { done ? 'Link copied' : '' }
      </span>
      { manual && (
        <TextInput
          readOnly
          aria-label="Link to this item"
          data-testid="bwx-copy-link-field"
          value={ url }
          autoFocus
          style={ { width: '14rem' } }
          onFocus={ ( event ) => event.target.select() }
        />
      ) }
    </>
  );
}
