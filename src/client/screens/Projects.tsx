import { ArrowLeft, CloudOff, FolderKanban } from 'lucide-react';
import { Button, Card, EmptyState, StageChip, Tag } from '../../kit';
import { api, day } from '../api';
import type { WorkItem } from '../api';
import { phaseOf } from '../../phases';
import { buildTree, summary, topLevel } from '../../tree.mjs';
import { itemWeeks } from '../../weeks.mjs';
import { useView } from '../useView';

/*
 * The client's projects and releases (#565), read only: the same tree the
 * studio draws, from the board the client already reads. Every row opens the
 * item record the board opens, at #board/<id>, so commenting works the same
 * way from here.
 *
 * Fixes raised in a release's review are not marked as such for a client:
 * they are ordinary bugs and feedback under the release.
 */

interface Node {
  item: WorkItem;
  parts: Node[];
  attached: WorkItem[];
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

/** Work that ended without being done: still listed, but in no count. */
const Ended = ( { item }: { item: WorkItem } ) => ( item.ended ? <Tag tone="neutral">Ended</Tag> : null );

/** Work hanging off an item: its own, and anything its review raised. */
const leaves = ( node: Node ) => [ ...node.attached, ...node.review ];

/** "1 Jul 2026 – 24 Jul 2026", "Until 24 Jul 2026", or "Ongoing" without an end. */
function span( node: Node ): string {
  const { start, due, ongoing } = summary( node );

  if ( ongoing ) return 'Ongoing';

  return start ? `${ day( start ) } – ${ day( due ) }` : `Until ${ day( due ) }`;
}

/** "N of M done" with a bar, or that there is nothing yet: never 0% of nothing. */
function Progress( { node }: { node: Node } ) {
  const { total, finished } = summary( node );

  if ( 0 === total ) {
    return <span className="fc-muted">No work yet</span>;
  }

  const share = Math.round( ( finished / total ) * 100 );

  return (
    <span className="fc-tree-progress">
      <span className="fc-progress-track" aria-hidden="true">
        <span style={ { width: `${ share }%` } } />
      </span>
      <span className="fc-tree-progress-figures">{ `${ finished } of ${ total } done` }</span>
    </span>
  );
}

/** One row of work: what it is, its title as a link to its record, its stage. */
function Line( { item }: { item: WorkItem } ) {
  return (
    <span className="fc-tree-line">
      <span className="fc-tree-kind">{ kindOf( item ) }</span>
      <a className="fc-tree-title" href={ `#board/${ item.id }` }>
        { item.title }
      </a>
      <StageChip stage={ item.stage } dense />
      <Ended item={ item } />
    </span>
  );
}

/** Everything under one item: features with their own work, then the rest. */
function Beneath( { node }: { node: Node } ) {
  const work = leaves( node );

  if ( 0 === node.parts.length + work.length ) return null;

  return (
    <ul className="fc-tree">
      { node.parts.map( ( part ) => (
        <li key={ part.item.id } data-testid="bwx-client-project-item" data-item={ part.item.id }>
          <span className="fc-tree-row">
            <Line item={ part.item } />
            { 0 < part.parts.length + leaves( part ).length && <Progress node={ part } /> }
          </span>
          <Beneath node={ part } />
        </li>
      ) ) }
      { work.map( ( one ) => (
        <li key={ one.id } data-testid="bwx-client-project-item" data-item={ one.id }>
          <span className="fc-tree-row">
            <Line item={ one } />
          </span>
        </li>
      ) ) }
    </ul>
  );
}

function ProjectList( { items }: { items: WorkItem[] } ) {
  const projects = topLevel( items, 'project' ) as WorkItem[];

  if ( 0 === projects.length ) {
    return (
      <Card testId="bwx-client-projects-list">
        <EmptyState
          icon={ FolderKanban }
          dense
          title="No projects yet"
          body="When the studio groups your work into a project, it shows here with its releases and dates. Everything is on Your work in the meantime."
        />
      </Card>
    );
  }

  return (
    <Card pad={ 0 } className="fc-projects" testId="bwx-client-projects-list">
      <ul className="fc-projects-list">
        { projects.map( ( project ) => {
          const node = buildTree( items, project.id ) as Node;
          const releases = node.parts.filter( ( part ) => 'milestone' === part.item.level ).length;

          return (
            <li key={ project.id } className="fc-projects-row" data-testid="bwx-client-project-row" data-item={ project.id }>
              <span className="fc-projects-what">
                <span className="fc-tree-line">
                  <a className="fc-tree-title" href={ `#projects/${ project.id }` }>
                    { project.title }
                  </a>
                  <Ended item={ project } />
                </span>
                <span className="fc-muted">
                  { span( node ) }
                  { 0 < releases && `, ${ releases } ${ 1 === releases ? 'release' : 'releases' }` }
                </span>
              </span>
              <Progress node={ node } />
            </li>
          );
        } ) }
      </ul>
    </Card>
  );
}

function Project( { items, id }: { items: WorkItem[]; id: string } ) {
  const tree = items.some( ( one ) => one.id === id && 'project' === one.level ) ? ( buildTree( items, id ) as Node ) : null;

  if ( ! tree ) {
    return (
      <Card>
        <EmptyState
          icon={ FolderKanban }
          dense
          title="This project is not here"
          body="It may have been finished and archived, or the link may be for another site."
          action={
            <a className="fk-btn" data-variant="secondary" data-size="sm" href="#projects">
              See all projects
            </a>
          }
        />
      </Card>
    );
  }

  const releases = tree.parts.filter( ( part ) => 'milestone' === part.item.level );
  const others = { ...tree, parts: tree.parts.filter( ( part ) => 'milestone' !== part.item.level ) };

  return (
    <div className="fc-stack" data-testid="bwx-client-project" data-item={ id }>
      <Card>
        <div className="fc-card-head">
          <h3>{ tree.item.title }</h3>
          <StageChip stage={ tree.item.stage } dense />
          <Ended item={ tree.item } />
        </div>
        <p className="fc-muted fc-project-span">{ span( tree ) }</p>
        <Progress node={ tree } />
      </Card>

      <section className="fc-releases" aria-labelledby="fc-releases-title">
        <h3 className="fc-releases-title" id="fc-releases-title">
          Releases
        </h3>
        { 0 === releases.length ? (
          <Card>
            <p className="fc-muted">No releases planned yet.</p>
          </Card>
        ) : (
          releases.map( ( node ) => (
            <Card key={ node.item.id } pad={ 0 } className="fc-release" testId="bwx-client-release">
              <div className="fc-release-inner" data-phase={ phaseOf( node.item.stage ) } data-item={ node.item.id }>
                <span className="fc-release-rail" aria-hidden="true" />
                <div className="fc-release-head">
                  <span className="fc-release-weeks fk-mono" data-testid="bwx-client-release-weeks">
                    { itemWeeks( node.item ) || 'No dates yet' }
                  </span>
                  <a className="fc-release-title" href={ `#board/${ node.item.id }` }>
                    { node.item.title }
                  </a>
                  <StageChip stage={ node.item.stage } dense />
                  <Ended item={ node.item } />
                  <Progress node={ node } />
                </div>
                <Beneath node={ node } />
              </div>
            </Card>
          ) )
        ) }
      </section>

      { 0 < others.parts.length + leaves( others ).length && (
        <section className="fc-releases" aria-labelledby="fc-also-title">
          <h3 className="fc-releases-title" id="fc-also-title">
            Also in this project
          </h3>
          <Card>
            <Beneath node={ others } />
          </Card>
        </section>
      ) }
    </div>
  );
}

/**
 * With no id, the client's projects with dates and progress; with one, that
 * project's releases and the work in each.
 */
export function Projects( { project }: { project: string } ) {
  const board = useView( api.board );
  const items = board.view?.ok ? board.view.items : [];

  return (
    <div className="fc-stack" data-testid="bwx-client-projects">
      { '' !== project && (
        <a className="fc-link fc-back" href="#projects">
          <ArrowLeft size={ 14 } strokeWidth={ 1.5 } aria-hidden="true" /> All projects
        </a>
      ) }

      { board.loading && ! board.view ? (
        <Card>
          <p className="fc-muted" role="status" aria-busy="true">
            Reading your projects…
          </p>
        </Card>
      ) : board.view?.ok ? (
        '' === project ? (
          <ProjectList items={ items } />
        ) : (
          <Project items={ items } id={ project } />
        )
      ) : (
        <Card>
          <EmptyState
            icon={ CloudOff }
            hue="amber"
            dense
            title="Your projects could not be read"
            body="The studio did not answer. What you last saw is still on your WordPress dashboard."
            action={
              <Button variant="secondary" size="sm" onClick={ () => board.reload( true ) }>
                Try again
              </Button>
            }
          />
        </Card>
      ) }
    </div>
  );
}
