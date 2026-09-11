import { useEffect, useState } from 'react';
import type { DragEvent, FormEvent } from 'react';
import { CloudOff, Inbox, X } from 'lucide-react';
import { Button, Card, DataView, EmptyState, Field, Modal, StageChip, Tag, TextArea, TextInput, useToast } from '../../kit';
import type { Column } from '../../kit';
import { api, day } from '../api';
import type { BoardView, Comment, DiscussionView, WorkItem } from '../api';
import { clientData } from '../data';
import { phaseOf } from '../../phases';
import { useView } from '../useView';

/*
 * The client's work, in the stage it is really in (#300). Every card is the
 * board's own record and every column is one of the studio's stages; nothing
 * here can move a card, and a drag says so rather than pretending.
 *
 * Opening a card shows the conversation on it — questions the studio is
 * waiting on first, then everything said in order — and the two things a
 * client may add: a comment, or a link to evidence. Both go to the same route
 * the wp-admin item screen posts to, and neither names a stage.
 */

type Mode = 'kanban' | 'dates';

function today(): string {
  return new Date().toISOString().slice( 0, 10 );
}

function isFinished( item: WorkItem ): boolean {
  return 'completed' === item.stage || 'released' === item.stage;
}

function isOverdue( item: WorkItem ): boolean {
  return '' !== item.planned_due && item.planned_due < today() && ! isFinished( item ) && 'blocked' !== item.stage;
}

function Standing( { item }: { item: WorkItem } ) {
  if ( 'blocked' === item.stage ) {
    return <Tag tone="danger">Blocked</Tag>;
  }
  if ( isOverdue( item ) ) {
    return <Tag tone="warn">Overdue</Tag>;
  }
  if ( isFinished( item ) ) {
    return <Tag tone="neutral">Done</Tag>;
  }
  return (
    <Tag tone="ok" dot>
      On track
    </Tag>
  );
}

function kind( item: WorkItem ): string {
  return [ item.level_label, item.work_type_label ].filter( Boolean ).join( ' · ' );
}

function Refusal( { onDismiss }: { onDismiss: () => void } ) {
  return (
    <div className="fc-refusal" role="alert" data-testid="bwx-board-refusal">
      <span>
        <strong>Cards cannot be moved here.</strong> Workflow movement is handled by the studio team. The item has not changed
        stage — you can still comment, attach evidence or answer an information request.
      </span>
      <button type="button" className="fk-icon-btn" aria-label="Dismiss" onClick={ onDismiss }>
        <X size={ 16 } strokeWidth={ 1.5 } aria-hidden="true" />
      </button>
    </div>
  );
}

function WorkCard( { item, onDrag }: { item: WorkItem; onDrag: () => void } ) {
  const blocked = 'blocked' === item.stage;

  return (
    <a
      className="fc-work-card"
      href={ `#board/${ item.id }` }
      draggable
      data-testid="bwx-work-card"
      data-item={ item.id }
      data-blocked={ blocked ? 'true' : undefined }
      onDragStart={ ( event: DragEvent ) => {
        event.preventDefault();
        onDrag();
      } }
    >
      <span className="fc-work-card-rail" aria-hidden="true" />
      <span className="fc-work-card-row">
        <span className="fk-mono">{ item.id }</span>
      </span>
      <span className="fc-work-card-title">{ item.title }</span>
      <span className="fc-work-card-kind">↳ { kind( item ) }</span>
      <span className="fc-work-card-row fc-work-card-foot">
        <Standing item={ item } />
        { item.planned_due && <span className="fk-mono">due { day( item.planned_due ) }</span> }
      </span>
    </a>
  );
}

function Kanban( { board, onDrag }: { board: BoardView; onDrag: () => void } ) {
  return (
    <div className="fc-board" data-testid="bwx-board">
      { board.stages.map( ( stage, index ) => {
        const items = board.items.filter( ( item ) => item.stage === stage.slug );
        return (
          <section key={ stage.slug } className="fc-column" data-stage={ stage.slug } data-phase={ phaseOf( stage.slug ) } aria-label={ stage.label }>
            <header className="fc-column-head">
              <StageChip stage={ stage.slug } dense />
              <span className="fk-mono" aria-label={ `${ items.length } items` }>
                { items.length }
              </span>
            </header>
            <div className="fc-column-body">
              { items.map( ( item ) => (
                <WorkCard key={ item.id } item={ item } onDrag={ onDrag } />
              ) ) }
              { 0 === items.length && <p className="fc-column-empty">No work at stage { index + 1 }</p> }
            </div>
          </section>
        );
      } ) }
    </div>
  );
}

interface DateRow extends Record< string, unknown > {
  id: string;
  when: string;
  item: WorkItem;
  what: string;
}

const DATE_LABEL: Array< [ keyof WorkItem, string ] > = [
  [ 'planned_start', 'Planned start' ],
  [ 'planned_due', 'Planned due' ],
  [ 'review_target', 'Review target' ],
  [ 'release_target', 'Release target' ],
];

function KeyDates( { board }: { board: BoardView } ) {
  const rows: DateRow[] = [];
  for ( const item of board.items ) {
    for ( const [ key, what ] of DATE_LABEL ) {
      const when = String( item[ key ] ?? '' );
      if ( when ) rows.push( { id: `${ item.id }-${ key }`, when, item, what } );
    }
  }
  rows.sort( ( a, b ) => a.when.localeCompare( b.when ) );

  const columns: Column< DateRow >[] = [
    { key: 'when', label: 'Date', mono: true, render: ( row ) => day( row.when ), width: 120 },
    { key: 'item', label: 'Item', mono: true, render: ( row ) => <a href={ `#board/${ row.item.id }` } className="fc-link">{ row.item.id }</a>, sortBy: ( row ) => row.item.id, width: 160 },
    { key: 'title', label: 'Work', render: ( row ) => row.item.title, sortBy: ( row ) => row.item.title, wrap: true },
    { key: 'what', label: 'Milestone' },
    { key: 'stage', label: 'Stage', render: ( row ) => <StageChip stage={ row.item.stage } dense />, sortBy: ( row ) => row.item.stage_label },
  ];

  return (
    <Card pad={ 0 } testId="bwx-key-dates">
      <DataView
        title="Key dates"
        titleRight={ <span className="fc-muted">Same records, same permissions — read-only for workflow movement</span> }
        columns={ columns }
        rows={ rows }
        defaultSort={ { key: 'when', dir: 'asc' } }
        empty="Nothing has a date on it yet. Dates appear here once work is scheduled."
        footer="Dates are the studio team's current plan and update as work moves."
      />
    </Card>
  );
}

function saidBy( comment: Comment ): string {
  const name = ( comment.author_name ?? '' ).trim();
  const who = comment.from_client ? name || 'Someone here' : name || 'The studio';
  const at = comment.created_at ?? 0;
  return at > 0 ? `${ who } · ${ new Date( at * 1000 ).toLocaleDateString( 'en-GB', { day: '2-digit', month: 'short', year: 'numeric' } ) }` : who;
}

function Thread( { comments }: { comments: Comment[] } ) {
  if ( 0 === comments.length ) {
    return <p className="fc-muted">Nothing has been said about this yet.</p>;
  }
  return (
    <ol className="fc-thread" data-testid="bwx-thread">
      { comments.map( ( comment ) => (
        <li key={ comment.id } data-kind={ comment.kind }>
          { comment.body && <p>{ comment.body }</p> }
          { comment.url && (
            <a href={ comment.url } target="_blank" rel="noreferrer" className="fc-link fk-mono">
              { comment.url }
            </a>
          ) }
          <span className="fc-thread-by fk-mono">{ saidBy( comment ) }</span>
        </li>
      ) ) }
    </ol>
  );
}

/**
 * One box, optionally a link, one button. There is nothing else in it and
 * nothing else that could go in it.
 */
function SayForm( {
  item,
  answers,
  label,
  submit,
  evidence,
  testId,
  onSaid,
}: {
  item: string;
  answers?: string;
  label: string;
  submit: string;
  evidence: boolean;
  testId: string;
  onSaid: () => void;
} ) {
  const [ body, setBody ] = useState( '' );
  const [ url, setUrl ] = useState( '' );
  const [ sending, setSending ] = useState( false );
  const [ problem, setProblem ] = useState< string | null >( null );
  const toast = useToast();

  const send = async ( event: FormEvent ) => {
    event.preventDefault();
    if ( ! body.trim() ) return;
    setSending( true );
    setProblem( null );
    try {
      const answer = await api.say( item, { body: body.trim(), url: url.trim() || undefined, answers } );
      if ( ! answer.ok ) {
        setProblem( answer.message || 'That could not be sent. The studio did not take it.' );
        return;
      }
      setBody( '' );
      setUrl( '' );
      toast( answers ? 'Answer sent to the studio' : 'Sent to the studio' );
      onSaid();
    } catch {
      setProblem( 'That could not be sent. The studio did not answer.' );
    } finally {
      setSending( false );
    }
  };

  return (
    <form className="fc-say" data-testid={ testId } onSubmit={ send }>
      <Field label={ label }>
        { ( id ) => (
          <TextArea
            id={ id }
            rows={ 3 }
            value={ body }
            onChange={ ( e ) => setBody( e.target.value ) }
            placeholder="Visible to the studio team and recorded in the item history."
          />
        ) }
      </Field>
      { evidence && (
        <Field label="Link to evidence" help="A screenshot, a document, an export — anywhere the studio can open it. Never passwords or API secrets.">
          { ( id ) => <TextInput id={ id } type="url" value={ url } onChange={ ( e ) => setUrl( e.target.value ) } placeholder="https://" /> }
        </Field>
      ) }
      { problem && (
        <p className="fc-problem" role="alert">
          { problem }
        </p>
      ) }
      <div className="fk-actions-end">
        <Button type="submit" disabled={ sending || ! body.trim() }>
          { sending ? 'Sending…' : submit }
        </Button>
      </div>
    </form>
  );
}

function ItemRecord( { id, onClose }: { id: string; onClose: () => void } ) {
  const [ view, setView ] = useState< DiscussionView | null >( null );
  const [ failed, setFailed ] = useState( false );

  const read = async () => {
    try {
      setView( await api.discussion( id ) );
      setFailed( false );
    } catch {
      setFailed( true );
    }
  };

  useEffect( () => {
    // The read is the effect, the same as every other screen's.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void read();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ id ] );

  const item = view && view.ok && 'id' in view.item ? ( view.item as WorkItem ) : null;
  const may = view?.may ?? {};
  const title = item?.title ?? 'Work';
  const description = item ? [ item.id, kind( item ), item.planned_due ? `due ${ day( item.planned_due ) }` : '' ].filter( Boolean ).join( ' · ' ) : id;

  return (
    <Modal title={ title } description={ description } width={ 640 } onClose={ onClose }>
      <div data-testid="bwx-item-record" data-item={ id }>
        { ! view && ! failed && (
          <p className="fc-muted" role="status" aria-busy="true">
            Reading this item…
          </p>
        ) }

        { ( failed || ( view && ! view.ok ) ) && (
          <EmptyState icon={ CloudOff } hue="amber" dense title="This item could not be read" body="The studio did not answer. Try again in a moment." />
        ) }

        { view?.ok && ! item && (
          <EmptyState icon={ Inbox } dense title="There is no such work here" body="The studio answered, and this is not work this site can see." />
        ) }

        { item && (
          <div className="fc-stack">
            <div className="fc-tags">
              <StageChip stage={ item.stage } />
              <Standing item={ item } />
              <Tag>Read-only for workflow movement</Tag>
            </div>

            <p className="fc-note" data-testid="bwx-no-moves">
              You cannot change the stage of this item. You can comment, attach evidence and answer questions — all of
              which the studio team sees immediately.
            </p>

            { view && view.outstanding.length > 0 && (
              <section className="fc-questions" data-testid="bwx-questions">
                <h3>The studio has asked you something</h3>
                { view.outstanding.map( ( question ) => (
                  <div key={ question.id } className="fc-question" data-testid="bwx-question">
                    <p>{ question.body }</p>
                    <span className="fc-thread-by fk-mono">{ saidBy( question ) }</span>
                    { may.answer ? (
                      <SayForm item={ id } answers={ question.id } label="Your answer" submit="Send this answer" evidence={ false } testId="bwx-answer-form" onSaid={ read } />
                    ) : (
                      <p className="fc-muted" data-testid="bwx-question-denied">
                        Answering this is your administrator&rsquo;s to do.
                      </p>
                    ) }
                  </div>
                ) ) }
              </section>
            ) }

            <section>
              <h3 className="fc-h3">Conversation</h3>
              <Thread comments={ view?.comments ?? [] } />
            </section>

            { may.comment || may.evidence ? (
              <section>
                <h3 className="fc-h3">Say something about this</h3>
                <SayForm item={ id } label="Your comment" submit="Send to the studio" evidence={ Boolean( may.evidence ) } testId="bwx-say-form" onSaid={ read } />
              </section>
            ) : (
              <p className="fc-muted" data-testid="bwx-say-denied">
                You can read this work but not add to it. Somebody with an administrator account on this site can.
              </p>
            ) }
          </div>
        ) }
      </div>
    </Modal>
  );
}

export function Board( { item }: { item: string } ) {
  const board = useView( api.board );
  const [ mode, setMode ] = useState< Mode >( 'kanban' );
  const [ denied, setDenied ] = useState( false );
  const name = clientData()?.client.name ?? '';

  const close = () => {
    window.location.hash = '#board';
  };

  return (
    <div className="fc-stack" data-testid="bwx-board-screen">
      <div className="fc-toolbar">
        { name && <Tag tone="brand">{ name } only</Tag> }
        <Tag>Read-only for workflow movement</Tag>
        <span className="fc-toolbar-right" role="group" aria-label="View">
          <Button size="sm" variant={ 'kanban' === mode ? 'secondary' : 'ghost' } aria-pressed={ 'kanban' === mode } onClick={ () => setMode( 'kanban' ) }>
            Kanban
          </Button>
          <Button size="sm" variant={ 'dates' === mode ? 'secondary' : 'ghost' } aria-pressed={ 'dates' === mode } onClick={ () => setMode( 'dates' ) }>
            Key dates
          </Button>
        </span>
      </div>

      { denied && <Refusal onDismiss={ () => setDenied( false ) } /> }

      { board.loading ? (
        <Card>
          <p className="fc-muted" role="status" aria-busy="true">
            Reading your work…
          </p>
        </Card>
      ) : board.view?.ok ? (
        'kanban' === mode ? (
          <Kanban board={ board.view } onDrag={ () => setDenied( true ) } />
        ) : (
          <KeyDates board={ board.view } />
        )
      ) : (
        <Card>
          <EmptyState
            icon={ CloudOff }
            hue="amber"
            dense
            title="Your work could not be read"
            body="The studio did not answer. What you last saw is still on your WordPress dashboard."
            action={
              <Button variant="secondary" size="sm" onClick={ () => board.reload( true ) }>
                Try again
              </Button>
            }
          />
        </Card>
      ) }

      <p className="fc-muted">Try dragging a card — it will not move. Open one to leave a comment or attach evidence.</p>

      { item && <ItemRecord id={ item } onClose={ close } /> }
    </div>
  );
}
