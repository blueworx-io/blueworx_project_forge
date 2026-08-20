import { useEffect, useState } from 'react';
import type { ClientSite, Stage, WorkItem } from './types';
import { api, ApiError, forgeData, isConnected } from './api';
import { Board } from './components/Board';
import { ItemPanel } from './components/ItemPanel';
import { NewWork } from './components/NewWork';

interface Site extends ClientSite {
  client_name: string;
}

/**
 * The board screen.
 *
 * The board is scoped to one site and never to more than one, because that is
 * how the work itself is scoped (ARCH-3): an item belongs to a site, and a
 * board showing two sites at once would be showing two different pieces of
 * work in the same column with no way to tell them apart.
 */
export function App() {
  const data = forgeData();
  const [ sites, setSites ] = useState< Site[] >( [] );
  const [ siteId, setSiteId ] = useState( '' );
  const [ stages, setStages ] = useState< Stage[] >( [] );
  const [ columns, setColumns ] = useState< string[] >( [] );
  const [ items, setItems ] = useState< WorkItem[] >( [] );
  const [ openId, setOpenId ] = useState( '' );
  const [ adding, setAdding ] = useState( false );
  const [ notice, setNotice ] = useState( '' );
  const [ loading, setLoading ] = useState( true );

  useEffect( () => {
    if ( ! isConnected() ) {
      setLoading( false );
      return;
    }

    void ( async () => {
      try {
        const [ stageList, siteList ] = await Promise.all( [
          api< { stages: Stage[]; columns: string[] } >( '/stages' ),
          api< { sites: Site[] } >( '/client-sites' ),
        ] );

        setStages( stageList.stages );
        setColumns( stageList.columns );
        setSites( siteList.sites );
        setSiteId( siteList.sites[ 0 ]?.id ?? '' );
      } catch ( error ) {
        setNotice( error instanceof ApiError ? error.message : 'Forge could not be loaded.' );
      } finally {
        setLoading( false );
      }
    } )();
  }, [] );

  async function loadItems( id: string ) {
    if ( '' === id ) {
      setItems( [] );
      return;
    }

    try {
      const loaded = await api< { items: WorkItem[] } >(
        `/work-items?client_site_id=${ encodeURIComponent( id ) }`
      );
      setItems( loaded.items );
    } catch ( error ) {
      setNotice( error instanceof ApiError ? error.message : 'That work could not be loaded.' );
    }
  }

  useEffect( () => {
    void loadItems( siteId );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ siteId ] );

  async function move( itemId: string, to: string ) {
    const item = items.find( ( candidate ) => candidate.id === itemId );

    if ( ! item ) {
      return;
    }

    /*
     * Moved on the board first, then confirmed by the server. A refusal puts it
     * back where it was and says why — which is the honest outcome, because the
     * gates mean a move genuinely can be refused and pretending otherwise would
     * leave the board showing something that never happened.
     */
    setItems( items.map( ( each ) => ( each.id === itemId ? { ...each, stage: to } : each ) ) );
    setNotice( '' );

    try {
      await api( `/work-items/${ itemId }/transition`, {
        method: 'POST',
        body: { to, record_version: item.record_version },
      } );
      await loadItems( siteId );
    } catch ( error ) {
      setItems( items );
      setNotice( error instanceof ApiError ? error.message : 'That move did not work.' );
    }
  }

  if ( ! isConnected() ) {
    return (
      <main className="bwx-app" data-testid="bwx-forge-ready">
        <div style={ { margin: 'auto', textAlign: 'center' } }>
          <h1 className="bwx-wordmark">
            Blueworx <span>Forge</span>
          </h1>
          <p className="bwx-empty">Running outside WordPress — there is no data to show.</p>
        </div>
      </main>
    );
  }

  const site = sites.find( ( candidate ) => candidate.id === siteId );

  return (
    <main className="bwx-app" data-testid="bwx-forge-ready">
      <header className="bwx-header">
        <h1 className="bwx-wordmark">
          Blueworx <span>Forge</span>
        </h1>

        <select
          className="bwx-select"
          data-testid="bwx-site"
          aria-label="Site"
          value={ siteId }
          onChange={ ( event ) => setSiteId( event.target.value ) }
        >
          { 0 === sites.length && <option value="">No sites yet</option> }
          { sites.map( ( option ) => (
            <option key={ option.id } value={ option.id }>
              { '' === option.client_name
                ? option.name
                : `${ option.client_name } — ${ option.name }` }
            </option>
          ) ) }
        </select>

        <span className="bwx-header-spacer" />

        <span className="bwx-mono">{ items.length } { 1 === items.length ? 'item' : 'items' }</span>

        <button
          type="button"
          className="bwx-button"
          data-testid="bwx-add"
          disabled={ '' === siteId }
          onClick={ () => setAdding( true ) }
        >
          Add work
        </button>
      </header>

      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-notice" role="status" style={ { margin: '12px 20px 0' } }>
          { notice }
        </p>
      ) }

      { loading && <p className="bwx-empty" style={ { padding: '20px' } }>Loading…</p> }

      { ! loading && 0 === sites.length && (
        <p className="bwx-empty" style={ { padding: '20px' } }>
          There are no sites yet. Add a client and a site in Forge → Clients, then work can be
          planned against it.
        </p>
      ) }

      { ! loading && 0 < sites.length && (
        <Board
          stages={ stages }
          columns={ columns }
          items={ items }
          onOpen={ ( item ) => setOpenId( item.id ) }
          onMove={ ( itemId, to ) => void move( itemId, to ) }
        />
      ) }

      { '' !== openId && (
        <ItemPanel
          itemId={ openId }
          stages={ stages }
          onClose={ () => setOpenId( '' ) }
          onChanged={ () => void loadItems( siteId ) }
        />
      ) }

      { adding && '' !== siteId && (
        <NewWork
          clientSiteId={ siteId }
          onClose={ () => setAdding( false ) }
          onCreated={ () => void loadItems( siteId ) }
        />
      ) }

      <footer style={ { padding: '0 20px 16px' } }>
        <span className="bwx-mono">
          { site ? `${ site.client_name } — ${ site.name }` : '' } · v{ data?.version ?? '' }
        </span>
      </footer>
    </main>
  );
}
