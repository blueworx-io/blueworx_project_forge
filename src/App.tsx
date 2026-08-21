import { useEffect, useState } from 'react';
import type { ClientSite, Requirement, SavedView, Stage, ViewName, WorkFilters, WorkItem } from './types';
import { api, GateError, forgeData, isConnected, isDenied, messageFor } from './api';
import { Board } from './components/Board';
import { Filters } from './components/Filters';
import { ItemPanel } from './components/ItemPanel';
import { GanttView } from './components/GanttView';
import { ListView } from './components/ListView';
import { NewWork } from './components/NewWork';
import { Screen } from './components/States';

/**
 * A filter set as query parameters.
 *
 * Built here rather than by each caller, because the API is strict about the
 * shape: a set-valued filter arrives as repeated `name[]` parameters, and one
 * built the other way is silently dropped rather than refused.
 */
function asQuery( filters: WorkFilters ): string {
  const parts: string[] = [];

  for ( const [ name, value ] of Object.entries( filters ) ) {
    if ( Array.isArray( value ) ) {
      for ( const one of value ) {
        parts.push( `${ encodeURIComponent( name ) }[]=${ encodeURIComponent( one ) }` );
      }
      continue;
    }

    if ( undefined !== value && '' !== value ) {
      parts.push( `${ encodeURIComponent( name ) }=${ encodeURIComponent( String( value ) ) }` );
    }
  }

  return 0 === parts.length ? '' : `&${ parts.join( '&' ) }`;
}

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

  /*
   * The filter set and the view are separate pieces of state on purpose (#123).
   * Filters belong to the shell rather than to either view, so switching views
   * keeps what somebody was looking at — a filter that belongs to a view has to
   * be set again on every switch, and two filters that drift apart are how two
   * views come to show different totals.
   */
  const [ view, setView ] = useState< ViewName >( 'board' );
  const [ filters, setFilters ] = useState< WorkFilters >( {} );
  const [ savedViews, setSavedViews ] = useState< SavedView[] >( [] );
  /*
   * Both start as loading rather than being set to it once an effect runs.
   * Running outside WordPress is known before the first render — it is a
   * property of the page, not something to go and find out — so it is the
   * initial value here rather than a state change on mount.
   */
  const [ shell, setShell ] = useState< Loading >( () => ( isConnected() ? 'loading' : 'ready' ) );
  const [ board, setBoard ] = useState< Loading >( 'loading' );

  async function loadShell() {
    try {
      const [ stageList, siteList, viewList ] = await Promise.all( [
        api< { stages: Stage[]; columns: string[] } >( '/stages' ),
        api< { sites: Site[] } >( '/client-sites' ),

        // A person with no saved views is the ordinary case, and a failure to
        // read them must not stop the board loading — so this one is allowed to
        // come back empty rather than throwing the whole shell away.
        api< { views: SavedView[] } >( '/saved-views' ).catch( () => ( { views: [] } ) ),
      ] );

      setStages( stageList.stages );
      setColumns( stageList.columns );
      setSites( siteList.sites );
      setSiteId( siteList.sites[ 0 ]?.id ?? '' );
      setSavedViews( viewList.views );
      setShell( 'ready' );
    } catch ( error ) {
      setShell( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'Forge could not be loaded.' ) );
    }
  }

  useEffect( () => {
    if ( isConnected() ) {
      // The rule cannot see that every state change in here happens after an
      // await. Reading from the server on mount is what an effect is for.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      void loadShell();
    }
  }, [] );

  async function loadItems( id: string, applied: WorkFilters = filters ) {
    try {
      /*
       * The filters go to the server rather than being applied here. That is
       * what makes #124 true rather than checked: both views render whatever
       * one answer contains, so there is no second place that could decide what
       * a filter means and disagree.
       */
      const loaded = await api< { items: WorkItem[] } >(
        `/work-items?client_site_id=${ encodeURIComponent( id ) }${ asQuery( applied ) }`
      );
      setItems( loaded.items );
      setBoard( 'ready' );
    } catch ( error ) {
      setBoard( isDenied( error ) ? 'denied' : 'error' );
      setNotice( messageFor( error, 'That work could not be loaded.' ) );
    }
  }

  /*
   * The work reloads when the shell arrives, when the site changes, and when
   * the filters change. Showing the loading state is the *caller's* job — the
   * initial value covers the first read, and the site picker sets it when
   * somebody switches — so this fetches and nothing else.
   *
   * The filter set is compared as JSON rather than by reference: it is rebuilt
   * on every keystroke in the search box, and a reference comparison would
   * refetch on each one whether or not anything had actually changed.
   */
  const filterKey = JSON.stringify( filters );

  useEffect( () => {
    if ( 'ready' === shell && '' !== siteId ) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      void loadItems( siteId );
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ siteId, shell, filterKey ] );

  /** Saves the filter set on screen as a view of its own. */
  async function saveView( name: string ) {
    try {
      const saved = await api< { view: SavedView } >( '/saved-views', {
        method: 'POST',
        body: { name, filters, grouping: '' },
      } );

      setSavedViews( [ ...savedViews, saved.view ] );
      setNotice( `Saved “${ saved.view.name }”.` );
    } catch ( error ) {
      setNotice( messageFor( error, 'That view could not be saved.' ) );
    }
  }

  /** Forgets one. */
  async function removeView( view: SavedView ) {
    try {
      const left = await api< { views: SavedView[] } >( `/saved-views/${ view.id }`, { method: 'DELETE' } );

      setSavedViews( left.views );
    } catch ( error ) {
      setNotice( messageFor( error, 'That view could not be removed.' ) );
    }
  }

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
          onChange={ ( event ) => {
            /*
             * Only when the site actually changes. Picking the site already
             * shown would otherwise put the board into loading with nothing on
             * its way to take it out again — the effect below does not run,
             * because from its point of view nothing happened.
             */
            if ( event.target.value === siteId ) {
              return;
            }

            setBoard( 'loading' );
            setSiteId( event.target.value );
          } }
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

        { /*
           * The view switch, in the shell rather than in either view (#117).
           * Every later view mounts here and brings no chrome of its own: the
           * header, the filters and the states above are the frame, and a view
           * is only what goes inside it.
           */ }
        <div className="bwx-views" role="group" aria-label="View">
          <button
            type="button"
            className="bwx-button"
            data-variant={ 'board' === view ? undefined : 'quiet' }
            data-testid="bwx-view-board"
            aria-pressed={ 'board' === view }
            onClick={ () => setView( 'board' ) }
          >
            Board
          </button>
          <button
            type="button"
            className="bwx-button"
            data-variant={ 'list' === view ? undefined : 'quiet' }
            data-testid="bwx-view-list"
            aria-pressed={ 'list' === view }
            onClick={ () => setView( 'list' ) }
          >
            List
          </button>
          <button
            type="button"
            className="bwx-button"
            data-variant={ 'gantt' === view ? undefined : 'quiet' }
            data-testid="bwx-view-gantt"
            aria-pressed={ 'gantt' === view }
            onClick={ () => setView( 'gantt' ) }
          >
            Schedule
          </button>
        </div>

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

      { /*
         One filter bar, above both views rather than inside either (#123).
         Only once there is something to filter: a bar over an empty screen is
         chrome asking a question nobody has.
       */ }
      { 'ready' === shell && 0 < sites.length && (
        <Filters
          filters={ filters }
          views={ savedViews }
          onChange={ ( next ) => {
            setBoard( 'loading' );
            setFilters( next );
          } }
          onSave={ ( name ) => void saveView( name ) }
          onOpenView={ ( saved ) => {
            setBoard( 'loading' );
            setFilters( saved.filters ?? {} );
          } }
          onRemoveView={ ( saved ) => void removeView( saved ) }
        />
      ) }

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
            <button
              type="button"
              className="bwx-button"
              onClick={ () => {
                setShell( 'loading' );
                void loadShell();
              } }
            >
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
            <button
              type="button"
              className="bwx-button"
              onClick={ () => {
                setBoard( 'loading' );
                void loadItems( siteId );
              } }
            >
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

          { 'board' === view && (
            <Board
              stages={ stages }
              columns={ columns }
              items={ items }
              onOpen={ ( item ) => setOpenId( item.id ) }
              onMove={ ( itemId, to ) => void move( itemId, to ) }
            />
          ) }

          { 'list' === view && (
            <ListView items={ items } onOpen={ ( item ) => setOpenId( item.id ) } />
          ) }

          { 'gantt' === view && (
            <GanttView items={ items } onOpen={ ( item ) => setOpenId( item.id ) } />
          ) }

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
