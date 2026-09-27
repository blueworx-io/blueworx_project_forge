import { createContext, useContext, useEffect, useId, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Building2, ChevronDown } from 'lucide-react';
import { api, isConnected } from './api';
import { ALL_SITES, openingSite, rememberSite, siteLabel, type SiteOption } from './sites';

/**
 * The one Client picker (#402): which client the whole app is looking at.
 *
 * App owns it and every client screen reads it, so a pick made once holds
 * on every screen and across reloads. `siteId` is always a site the person
 * reaches or `ALL_SITES`, never '' — screens need no "nothing chosen" state.
 */
export interface ClientChoice {
  siteId: string;
  sites: SiteOption[];
  /** False until the site list has arrived; screens wait for it. */
  ready: boolean;
  /** Why the site list could not be read (someone who reaches no site), or null. */
  failure: unknown;
  setSiteId: ( id: string ) => void;
  /** How a site reads: "All clients", or the client's name. */
  label: ( id?: string ) => string;
}

const Choice = createContext< ClientChoice >( {
  siteId: ALL_SITES,
  sites: [],
  ready: true,
  failure: null,
  setSiteId: () => undefined,
  label: () => 'All clients',
} );

export function useClientChoice(): ClientChoice {
  return useContext( Choice );
}

/**
 * Reads the sites once and settles the opening pick: a linked site, the
 * remembered one, or All. A linked site is remembered too, so the link
 * moves the top bar for good rather than for one visit.
 */
export function ClientChoiceProvider( { landing = '', children }: { landing?: string; children: ReactNode } ) {
  const [ sites, setSites ] = useState< SiteOption[] >( [] );
  const [ siteId, setChosen ] = useState( ALL_SITES );
  const [ ready, setReady ] = useState( () => ! isConnected() );
  const [ failure, setFailure ] = useState< unknown >( null );

  // Read once per page load: a site added elsewhere shows after a reload.
  // Re-reading in the background queued ahead of saves on a busy server.
  useEffect( () => {
    if ( ! isConnected() ) {
      return;
    }

    api< { sites: SiteOption[] } >( '/client-sites' )
      .then( ( answer ) => {
        const opening = openingSite( answer.sites, landing );

        // A link's site is remembered, so it holds after the next reload too.
        if ( '' !== landing && opening === landing ) {
          rememberSite( landing );
        }

        setSites( answer.sites );
        setChosen( opening );
      } )
      .catch( ( error: unknown ) => {
        // Somebody who reaches no site: kept, so a screen can say so.
        setFailure( error );
      } )
      .finally( () => setReady( true ) );
    // Once, on mount.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [] );

  const value = useMemo< ClientChoice >(
    () => ( {
      siteId,
      sites,
      ready,
      failure,
      setSiteId: ( id: string ) => {
        const next = ALL_SITES === id || sites.some( ( one ) => one.id === id ) ? id : ALL_SITES;

        rememberSite( next );
        setChosen( next );
      },
      label: ( id: string = siteId ) => {
        if ( ALL_SITES === id ) {
          return 'All clients';
        }

        const site = sites.find( ( one ) => one.id === id );

        return site ? siteLabel( site, sites ) : '';
      },
    } ),
    [ siteId, sites, ready, failure ]
  );

  return <Choice.Provider value={ value }>{ children }</Choice.Provider>;
}

/**
 * The picker itself, for the top bar. Tinted when narrowed to one client, so
 * a screen showing less than everything never looks like it shows it all.
 */
export function ClientPicker() {
  const id = useId();
  const { siteId, sites, ready, setSiteId, label } = useClientChoice();
  const narrowed = ALL_SITES !== siteId;

  return (
    <span className="fs-client" data-narrowed={ narrowed ? 'true' : undefined } title={ narrowed ? `Showing ${ label() } only` : 'Showing every client' }>
      <Building2 size={ 14 } strokeWidth={ 1.75 } aria-hidden="true" />
      <label className="fs-client-label" htmlFor={ id }>
        Client
      </label>
      <select
        id={ id }
        className="fs-client-select"
        data-testid="bwx-client-choice"
        aria-label="Client"
        disabled={ ! ready }
        value={ siteId }
        onChange={ ( event ) => setSiteId( event.target.value ) }
      >
        <option value={ ALL_SITES }>All clients</option>
        { sites.map( ( one ) => (
          <option key={ one.id } value={ one.id }>
            { siteLabel( one, sites ) }
          </option>
        ) ) }
      </select>
      <ChevronDown size={ 14 } strokeWidth={ 1.75 } aria-hidden="true" className="fs-client-chevron" />
    </span>
  );
}
