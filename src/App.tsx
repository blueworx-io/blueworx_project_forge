import { useEffect, useState } from 'react';
import type { ClientSite, Requirement, Stage, WorkItem } from './types';
import { api, GateError, forgeData, isConnected, isDenied, messageFor } from './api';
import { Board } from './components/Board';
import { ItemPanel } from './components/ItemPanel';
import { NewWork } from './components/NewWork';
import { Screen } from './components/States';

interface Site extends ClientSite {
  client_name: string;
}

/** What the board is currently able to show (#125). */
type Loading = 'loading' | 'ready' | 'error' | 'denied';

/**
 * The board screen.
 *
 * The board is scoped to one site and never to more than one, because that is
 * how the work itself is scoped (ARCH-3): an item belongs to a site, and a
 * board showing two sites at once would be showing two different pieces of
 * work in the same column with no way to tell them apart.
 *
 * Everything it can be other than a board — loading, empty, broken, not yours —
 * says which of those it is (#125). A blank board is the same picture for all
 * four, and they need four different things done about them.
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
  const [ unmet, setUnmet ] = useState< Requirement[] >( [] );
  const [ shell, setShell ] = useState< Loading >( 'loading' );
  const [ board, setBoard ] = useState< Loading >( 'loading' );

  async function loadShell() {
    setShell( 'loading' );

    try {
      const [ stageList, siteList ] = await Promise.all( [
        api< { stages: Stage[]; columns: string[] } >( '/stages' ),
        api< { sites: Site[] } >( '/client-sites' ),
      ] );

      setStages( stageList.stages );
      setColumns( stageList.columns );
      setSites( siteList.sites );
      setSiteId( siteList.sites[ 0 ]?.id ?? '' );
      setShell( 'ready' );
    } catch ( error ) {
      setShell( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'Forge could not be loaded.' ) );
    }
  }

  useEffect( () => {
    if ( ! isConnected() ) {
      setShell( 'ready' );
      return;
    }

    void loadShell();
  }, [] );

  async function loadItems( id: string ) {
    if ( '' === id ) {
      setItems( [] );
      setBoard( 'ready' );
      return;
    }

    setBoard( 'loading' );

    try {
      const loaded = await api< { items: WorkItem[] } >(
        `/work-items?client_site_id=${ encodeURIComponent( id ) }`
      );
      setItems( loaded.items );
      setBoard( 'ready' );
    } catch ( error ) {
      setBoard( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'That work could not be loaded.' ) );
    }
  }

  useEffect( () => {
    if ( 'ready' !== shell ) {
      return;
    }

    void loadItems( siteId );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ siteId, shell ] );

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
    setUnmet( [] );

    try {
      await api( `/work-items/${ itemId }/transition`, {
        method: 'POST',
        body: { to, record_version: item.record_version },
      } );
      await loadItems( siteId );
    } catch ( error ) {
      setItems( items );

      // A gate refusal is not "that did not work": it is a list of things that
      // would make it work, and dropping that list on the floor is what turns a
      // gate into an obstacle.
      if ( error instanceof GateError ) {
        setUnmet( error.unmet );
        setNotice( `${ item.title } is not ready for that yet.` );
        return;
      }

      setNotice( messageFor( error, 'That move did not work.' ) );
    }
  }

  if ( ! isConnected() ) {
    return (
      <main className="bwx-app" data-testid="bwx-forge-ready">
        <div style={ { margin: 'auto', textAlign: 'center' } }>
          <h1 className="bwx-wordmark">
            Blueworx <span>Forge</span>
          </h1>
          <Screen
            state="empty"
            title="Running outside WordPress"
            detail="There is no server to read work from, so the board has nothing to draw."
          />
        </div>
      </main>
    );
  }

  const site = sites.find( ( candidate ) => candidate.id === siteId );
  const blocked = items.filter( ( each ) => 'blocked' === each.stage );

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

      { 0 < unmet.length && (
        <ul className="bwx-unmet" data-testid="bwx-board-unmet" style={ { margin: '8px 20px 0' } }>
          { unmet.map( ( requirement ) => (
            <li key={ requirement.id } data-requirement={ requirement.id }>
              <span className="bwx-unmet-label">{ requirement.label }</span>
              <span className="bwx-unmet-how">{ requirement.satisfied_by }</span>
            </li>
          ) ) }
        </ul>
      ) }

      { 'loading' === shell && <Screen state="loading" detail="Reading the stages and your sites." /> }

      { 'denied' === shell && (
        <Screen
          state="denied"
          detail="You are signed in, but not as somebody who may see this studio's work. Ask for access, or sign in as an account that has it."
          action={
            <a className="bwx-button" href={ data?.loginUrl ?? '#' }>
              Sign in as somebody else
            </a>
          }
        />
      ) }

      { 'error' === shell && (
        <Screen
          state="error"
          detail={ notice }
          action={
            <button type="button" className="bwx-button" onClick={ () => void loadShell() }>
              Try again
            </button>
          }
        />
      ) }

      { 'ready' === shell && 0 === sites.length && (
        <Screen
          state="empty"
          title="No sites yet"
          detail="Add a client and a site in Forge → Clients, and work can be planned against it."
        />
      ) }

      { 'ready' === shell && 0 < sites.length && 'loading' === board && (
        <Screen state="loading" detail="Reading the work on this site." />
      ) }

      { 'ready' === shell && 0 < sites.length && 'denied' === board && (
        <Screen
          state="denied"
          detail="This site's work is not yours to read. Pick another site, or ask for a membership on this one."
        />
      ) }

      { 'ready' === shell && 0 < sites.length && 'error' === board && (
        <Screen
          state="error"
          detail={ notice }
          action={
            <button type="button" className="bwx-button" onClick={ () => void loadItems( siteId ) }>
              Try again
            </button>
          }
        />
      ) }

      { 'ready' === shell && 0 < sites.length && 'ready' === board && (
        <>
          { /*
             Said out loud rather than left to be inferred from ten empty
             columns. The board stays drawn underneath it: the columns are the
             answer to "what happens to work here", which is exactly what
             somebody looking at an empty site wants to know.
           */ }
          { 0 === items.length && (
            <Screen
              state="empty"
              title="No work on this site yet"
              detail="Add the first piece of work and it starts as a future idea."
              action={
                <button type="button" className="bwx-button" onClick={ () => setAdding( true ) }>
                  Add work
                </button>
              }
            />
          ) }

          <Board
            stages={ stages }
            columns={ columns }
            items={ items }
            onOpen={ ( item ) => setOpenId( item.id ) }
            onMove={ ( itemId, to ) => void move( itemId, to ) }
          />

          { /*
             Blocked work is not a column — it is work that left one and kept its
             place. Drawn as a strip under the board so it is visible without
             pretending it is queued somewhere.
           */ }
          { 0 < blocked.length && (
            <section style={ { padding: '0 20px 12px' } } data-testid="bwx-blocked-lane">
              <p className="bwx-eyebrow">Blocked · { blocked.length }</p>
              <div className="bwx-moves">
                { blocked.map( ( each ) => (
                  <button
                    key={ each.id }
                    type="button"
                    className="bwx-button"
                    data-testid="bwx-blocked-item"
                    data-item={ each.id }
                    onClick={ () => setOpenId( each.id ) }
                  >
                    { each.title }
                    <span className="bwx-mono">
                      { ' ' }· from{ ' ' }
                      { stages.find( ( stage ) => stage.id === each.prior_stage )?.label ??
                        each.prior_stage }
                    </span>
                  </button>
                ) ) }
              </div>
            </section>
          ) }
        </>
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
