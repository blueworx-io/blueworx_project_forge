import { useEffect, useId, useState } from 'react';
import { api } from '../api';
import { Select } from '../kit';
import { ALL_SITES, rememberSite, rememberedSite, siteLabel, type SiteOption } from '../sites';

/**
 * One site, chosen: the picker for a screen that is about a single site's
 * record rather than about work (Support first; Meetings next).
 *
 * The picker owns the list and the memory. It reads the sites once, opens on
 * the site it was given (a link that landed here) or else on the one last
 * chosen in this browser, and remembers every pick the way the board does —
 * so a person moving between the board and this screen finds the same site
 * on both. Nothing is guessed: with nothing remembered it opens on nothing,
 * and the screen says what to do.
 *
 * `allOption` adds one more choice, for every site at once (Meetings' "All
 * Clients", #383). It is a prop rather than a default so that Support, which
 * has no across-every-site view to switch to, is unchanged.
 */
export function SitePicker( {
  value,
  onChange,
  testId,
  label = 'Site',
  allOption,
}: {
  value: string;
  onChange: ( id: string ) => void;
  testId: string;
  label?: string;
  /** The label for an "every site at once" choice, when the screen offers one. */
  allOption?: string;
} ) {
  const id = useId();
  const [ sites, setSites ] = useState< SiteOption[] >( [] );
  const [ ready, setReady ] = useState( false );

  useEffect( () => {
    let live = true;

    api< { sites: SiteOption[] } >( '/client-sites' )
      .then( ( answer ) => {
        if ( ! live ) {
          return;
        }

        setSites( answer.sites );
        setReady( true );

        // What was asked for wins if it is a site at all, or "All Clients"
        // where that is offered; otherwise the last one chosen. Told to the
        // screen only when it differs from what the screen already holds,
        // so a landing is not loaded twice.
        const named = answer.sites.some( ( one ) => one.id === value ) || ( undefined !== allOption && ALL_SITES === value );
        const opening = named ? value : rememberedSite( answer.sites, undefined !== allOption );

        if ( opening !== value ) {
          onChange( opening );
        }
      } )
      .catch( () => {
        // The screen's own read says what went wrong; an empty picker is
        // what a failed list looks like.
        if ( live ) {
          setReady( true );
        }
      } );

    return () => {
      live = false;
    };
    // Read once on mount; the value and the handler are for picks after that.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [] );

  const options = ready
    ? [
        { value: '', label: 0 === sites.length ? 'No sites yet' : 'Choose a site' },
        ...( undefined !== allOption && 0 < sites.length ? [ { value: ALL_SITES, label: allOption } ] : [] ),
        ...sites.map( ( one ) => ( { value: one.id, label: siteLabel( one, sites ) } ) ),
      ]
    : [ { value: '', label: 'Loading sites…' } ];

  return (
    <div className="bwx-site-picker">
      <label htmlFor={ id }>{ label }</label>
      <Select
        id={ id }
        data-testid={ testId }
        disabled={ ! ready }
        value={ ready ? value : '' }
        options={ options }
        onChange={ ( event ) => {
          const picked = event.target.value;

          if ( picked === value ) {
            return;
          }

          if ( '' !== picked ) {
            rememberSite( picked );
          }

          onChange( picked );
        } }
      />
    </div>
  );
}
