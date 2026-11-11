import { useEffect, useState } from 'react';
import { Link, Send } from 'lucide-react';
import { forgeData } from '../api';
import { IconButton, TextInput } from '../kit';

/** How long "Link copied" stays, so the next copy is announced afresh. */
const SAID_FOR = 4000;

/**
 * Copies a link to an item (#559). Where the browser will not allow the
 * clipboard, the link is shown in a field to copy by hand instead.
 */
export function CopyLink( { itemId, clientSiteUrl }: { itemId: string; clientSiteUrl?: string } ) {
  const [ done, setDone ] = useState( false );
  const [ manual, setManual ] = useState( false );
  /*
   * #671. With a client site's address this is the link to send the client:
   * their own site, with the item in the query, which their site turns into
   * the item once they have signed in.
   */
  const forClient = undefined !== clientSiteUrl;
  const url = forClient
    ? `${ clientSiteUrl.replace( /\/+$/, '' ) }/?forge_item=${ encodeURIComponent( itemId ) }`
    : `${ forgeData()?.appUrl ?? '' }#item=${ encodeURIComponent( itemId ) }`;
  const testId = forClient ? 'bwx-copy-client-link' : 'bwx-copy-link';

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
      <IconButton
        icon={ forClient ? Send : Link }
        label={ forClient ? 'Copy client link' : 'Copy link' }
        data-testid={ testId }
        data-link={ url }
        onClick={ () => void copy() }
      />
      <span className="bwx-visually-hidden" role="status" aria-live="polite" data-testid={ `${ testId }-done` }>
        { done ? ( forClient ? 'Client link copied' : 'Link copied' ) : '' }
      </span>
      { manual && (
        <TextInput
          readOnly
          aria-label={ forClient ? 'Link to this item for the client' : 'Link to this item' }
          data-testid={ `${ testId }-field` }
          value={ url }
          autoFocus
          style={ { width: '14rem' } }
          onFocus={ ( event ) => event.target.select() }
        />
      ) }
    </>
  );
}
