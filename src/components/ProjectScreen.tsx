import { useEffect, useState } from 'react';
import { ArrowLeft, CheckCircle2, ChevronDown, ChevronRight, Circle } from 'lucide-react';
import type { Stage, WorkItem } from '../types';
import { api, ApiError, isDenied } from '../api';
import { useLiveReload } from '../live';
import type { SiteOption } from '../sites';
import { buildTree, isDone, reviewProgress, summary } from '../tree.mjs';
import { itemWeeks } from '../weeks.mjs';
import { Button, Panel, StageChip } from '../kit';
import { CopyLink } from './CopyLink';
import { ItemPanel } from './ItemPanel';
import { NewWork, type NewWorkPreset } from './NewWork';
import { Screen } from './States';

interface Node {
  item: WorkItem;
  parts: Node[];
  attached: WorkItem[];
  /** #564. What this item's own review raised. */
  review: WorkItem[];
}

const KIND: Record< string, string > = {
  milestone: 'Release',
  feature: 'Feature',
  'sub-feature': 'Sub-feature',
  bug: 'Bug',
  task: 'Task',
  feedback: 'Feedback',
};

/** What an item is, as a person reads it: its type for a leaf, else its level. */
const kindOf = ( one: WorkItem ) => KIND[ 'feature' === one.work_type ? one.level : one.work_type ] ?? one.level_label;

/** The level a feature added inside something takes, or '' when nothing can be. */
const featureLevel = ( level: string ) => ( 'milestone' === level ? 'feature' : 'feature' === level ? 'sub-feature' : '' );

/** 3 Nov 2026, from a Y-m-d date, without the timezone moving the day. */
const day = ( date: string ) => new Date( `${ date }T00:00:00Z` ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' } );

/** "N of M done", or that there is nothing yet, never 0% of nothing. */
export function Progress( { node }: { node: Node } ) {
  const { total, finished } = summary( node );

  if ( 0 === total ) {
    return <span className="bwx-progress-figures">No work yet</span>;
  }

  return (
    <span className="bwx-project-progress">
      <span className="bwx-progress" aria-hidden="true">
        <span className="bwx-progress-fill" style={ { inlineSize: `${ Math.round( ( finished / total ) * 100 ) }%` } } />
      </span>
      <span className="bwx-progress-figures">{ `${ finished } of ${ total } done` }</span>
    </span>
  );
}

/**
 * #562. One project: its dates and progress, and its releases with the work in
 * each. Everything is added from where it belongs, and any row opens its panel.
 */
export function ProjectScreen( { projectId, onBack }: { projectId: string; onBack: ( clientId?: string ) => void } ) {
  const [ items, setItems ] = useState< WorkItem[] >( [] );
  const [ site, setSite ] = useState< SiteOption | null >( null );
  const [ stages, setStages ] = useState< Stage[] >( [] );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'missing' | 'denied' | 'error' >( 'loading' );
  const [ open, setOpen ] = useState< Set< string > >( () => new Set() );
  const [ opened, setOpened ] = useState( '' );
  const [ adding, setAdding ] = useState< NewWorkPreset | null >( null );

  async function load() {
    try {
      const { item } = await api< { item: WorkItem } >( `/work-items/${ projectId }` );
      const [ list, sites ] = await Promise.all( [
        api< { items: WorkItem[] } >( `/work-items?client_site_id=${ item.client_site_id }` ),
        api< { sites: SiteOption[] } >( '/client-sites' ).catch( () => ( { sites: [] as SiteOption[] } ) ),
      ] );

      const kept = list.items.filter( ( one ) => ! one.archived );

      setItems( kept );
      setSite( sites.sites.find( ( one ) => one.id === item.client_site_id ) ?? null );
      setState( kept.some( ( one ) => one.id === projectId ) ? 'ready' : 'missing' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : error instanceof ApiError && 404 === error.status ? 'missing' : 'error' );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
    void api< { stages: Stage[] } >( '/stages' ).then( ( answer ) => setStages( answer.stages ) ).catch( () => undefined );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ projectId ] );

  useLiveReload( () => load() );

  const tree = 'ready' === state ? ( buildTree( items, projectId ) as Node ) : null;
  const project = tree?.item;
  const releases = tree?.parts.filter( ( part ) => 'milestone' === part.item.level ) ?? [];
  const others = tree?.parts.filter( ( part ) => 'milestone' !== part.item.level ) ?? [];
  const when = tree ? summary( tree ) : null;

  const toggle = ( id: string ) =>
    setOpen( ( held ) => {
      const next = new Set( held );

      if ( next.has( id ) ) {
        next.delete( id );
      } else {
        next.add( id );
      }

      return next;
    } );

  /** Opens the add form for work under one item, keeping that item open. */
  const addUnder = ( preset: NewWorkPreset ) => {
    if ( preset.parentId ) {
      setOpen( ( held ) => new Set( held ).add( preset.parentId as string ) );
    }
    setAdding( preset );
  };

  /** The title of a row, which opens its panel. */
  const title = ( one: WorkItem ) => (
    <button type="button" className="bwx-project-open" onClick={ () => setOpened( one.id ) }>
      { one.title }
    </button>
  );

  /** What can be added under one item. */
  const adds = ( one: WorkItem ) => {
    const level = featureLevel( one.level );

    return (
      <div className="bwx-project-adds">
        { '' !== level && (
          <Button size="sm" variant="ghost" data-testid="bwx-project-add-feature" onClick={ () => addUnder( { level, parentId: one.id, workType: 'feature' } ) }>
            + Add { 'feature' === level ? 'feature' : 'sub-feature' }
          </Button>
        ) }
        <Button size="sm" variant="ghost" data-testid="bwx-project-add-leaf" onClick={ () => addUnder( { level: 'sub-feature', parentId: one.id } ) }>
          + Add bug / task / feedback
        </Button>
      </div>
    );
  };

  /** One leaf: a bug, task or feedback item. */
  const leaf = ( one: WorkItem ) => (
    <li key={ one.id } className="bwx-project-node" data-testid="bwx-project-node" data-item={ one.id }>
      <div className="bwx-project-line">
        <span className="bwx-project-kind">{ kindOf( one ) }</span>
        { title( one ) }
        <StageChip stage={ one.stage } dense />
      </div>
    </li>
  );

  /**
   * #564. A release's review: what it raised and how many are done, and while
   * the release is in review, a way to raise more. Each fix is an ordinary bug
   * or feedback item under the release, marked as raised in its review, so the
   * box stays once the review is over: it is the only place the fixes are drawn.
   */
  const reviewBox = ( node: Node ) => {
    const { total, finished } = reviewProgress( node );
    const reviewing = 'in-review' === node.item.stage;
    const raise = ( workType: 'bug' | 'feedback' ) => addUnder( { level: 'sub-feature', parentId: node.item.id, workType, reviewOf: node.item.id } );

    return (
      <section className="bwx-release-review" data-testid="bwx-release-review" aria-labelledby={ `bwx-review-${ node.item.id }` }>
        <h3 className="bwx-release-review-title" id={ `bwx-review-${ node.item.id }` }>
          Release review
        </h3>
        <p className="bwx-release-review-count" data-testid="bwx-release-review-count">
          { 0 === node.review.length
            ? 'Nothing raised yet. Anything raised here has to be completed before this can be released.'
            : 0 === total
              ? 'Nothing left to do: everything raised here was ended.'
              : reviewing
                ? `${ finished } of ${ total } done before this can be released`
                : `${ finished } of ${ total } done` }
        </p>
        { 0 < node.review.length && (
          <ul className="bwx-release-review-list">
            { node.review.map( ( one ) => {
              const done = isDone( one );
              const ended = Boolean( one.terminal_outcome ) || Boolean( one.archived );

              return (
                <li key={ one.id } className="bwx-project-line" data-testid="bwx-release-fix" data-item={ one.id } data-done={ done ? 'true' : undefined }>
                  { done ? (
                    <CheckCircle2 size={ 16 } className="bwx-release-fix-mark" data-done="true" role="img" aria-label="Done" />
                  ) : (
                    <Circle size={ 16 } className="bwx-release-fix-mark" role="img" aria-label={ ended ? 'Ended, not counted' : 'Not done yet' } />
                  ) }
                  <span className="bwx-project-kind">{ kindOf( one ) }</span>
                  { title( one ) }
                  <StageChip stage={ one.stage } dense />
                </li>
              );
            } ) }
          </ul>
        ) }
        { reviewing && (
          <div className="bwx-project-adds">
            <Button size="sm" variant="ghost" data-testid="bwx-release-raise" data-type="bug" onClick={ () => raise( 'bug' ) }>
              + Raise a bug
            </Button>
            <Button size="sm" variant="ghost" data-testid="bwx-release-raise" data-type="feedback" onClick={ () => raise( 'feedback' ) }>
              + Raise feedback
            </Button>
          </div>
        ) }
      </section>
    );
  };

  /** A feature or sub-feature, with what is under it. */
  const branch = ( node: Node ) => (
    <li key={ node.item.id } className="bwx-project-node" data-testid="bwx-project-node" data-item={ node.item.id }>
      <div className="bwx-project-line">
        <span className="bwx-project-kind">{ kindOf( node.item ) }</span>
        { title( node.item ) }
        <StageChip stage={ node.item.stage } dense />
        { 0 < node.parts.length + node.attached.length && <Progress node={ node } /> }
      </div>
      { beneath( node ) }
    </li>
  );

  /** Everything under one item, then what can be added there. */
  const beneath = ( node: Node ) => (
    <>
      { 0 < node.parts.length + node.attached.length && (
        <ul className="bwx-project-tree">
          { node.parts.map( branch ) }
          { node.attached.map( leaf ) }
        </ul>
      ) }
      { adds( node.item ) }
    </>
  );

  return (
    <div className="bwx-project" data-testid="bwx-project">
      <div className="bwx-moves">
        <Button size="sm" variant="ghost" data-testid="bwx-project-back" onClick={ () => onBack( site?.client_id ) }>
          <ArrowLeft size={ 14 } aria-hidden="true" /> { site?.client_name ? `Back to ${ site.client_name }` : 'Back' }
        </Button>
      </div>

      { 'loading' === state && <Screen state="loading" testId="bwx-project-state" /> }
      { 'denied' === state && <Screen state="denied" testId="bwx-project-state" /> }
      { 'missing' === state && <Screen state="empty" testId="bwx-project-state" title="No such project" detail="It may have been deleted or archived." /> }
      { 'error' === state && <Screen state="error" testId="bwx-project-state" detail="That project could not be read." /> }

      { tree && project && when && (
        <>
          <Panel
            title={ title( project ) }
            right={
              <span className="bwx-moves">
                <CopyLink itemId={ project.id } />
                <Button size="sm" data-testid="bwx-project-add-release" onClick={ () => addUnder( { level: 'milestone', parentId: project.id, workType: 'feature' } ) }>
                  + Add release
                </Button>
              </span>
            }
          >
            <p className="bwx-hint" data-testid="bwx-project-summary">
              { [ site?.client_name || site?.name || '', when.ongoing ? 'Ongoing' : when.start ? `${ day( when.start ) } – ${ day( when.due ) }` : `Until ${ day( when.due ) }` ].filter( Boolean ).join( ' · ' ) }
            </p>
            <Progress node={ tree } />
          </Panel>

          <Panel title="Releases" flush>
            { 0 === releases.length ? (
              <p className="bwx-hint bwx-panel-lead" data-testid="bwx-project-no-releases">No releases yet.</p>
            ) : (
              <ul className="bwx-project-releases">
                { releases.map( ( node ) => {
                  const expanded = open.has( node.item.id );
                  const weeks = itemWeeks( node.item );

                  return (
                    <li key={ node.item.id } className="bwx-project-release" data-testid="bwx-project-release" data-item={ node.item.id }>
                      <div className="bwx-project-line">
                        <button
                          type="button"
                          className="fk-icon-btn"
                          data-testid="bwx-project-toggle"
                          aria-expanded={ expanded }
                          aria-label={ `${ expanded ? 'Hide' : 'Show' } the work in ${ node.item.title }` }
                          onClick={ () => toggle( node.item.id ) }
                        >
                          { expanded ? <ChevronDown size={ 16 } aria-hidden="true" /> : <ChevronRight size={ 16 } aria-hidden="true" /> }
                        </button>
                        { title( node.item ) }
                        <span className="bwx-progress-figures" data-testid="bwx-project-weeks">{ weeks || 'No dates yet' }</span>
                        <StageChip stage={ node.item.stage } dense />
                        <Progress node={ node } />
                      </div>
                      { expanded && (
                        <div className="bwx-project-inside">
                          { ( 'in-review' === node.item.stage || 0 < node.review.length ) && reviewBox( node ) }
                          { beneath( node ) }
                        </div>
                      ) }
                    </li>
                  );
                } ) }
              </ul>
            ) }
          </Panel>

          { 0 < others.length + tree.attached.length && (
            <Panel title="Also in this project">
              <ul className="bwx-project-tree">
                { others.map( branch ) }
                { tree.attached.map( leaf ) }
              </ul>
            </Panel>
          ) }
        </>
      ) }

      { null !== adding && project && (
        <NewWork clientSiteId={ project.client_site_id } preset={ adding } onClose={ () => setAdding( null ) } onCreated={ () => void load() } />
      ) }

      { '' !== opened && <ItemPanel itemId={ opened } stages={ stages } onClose={ () => setOpened( '' ) } onChanged={ () => void load() } /> }
    </div>
  );
}
