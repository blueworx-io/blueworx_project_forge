import { CircleCheck, CloudOff, ClipboardList, UserRound } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button, Card, DataView, EmptyState, Eyebrow, Meter, StageChip, Tag } from '../../kit';
import { api, day, hours } from '../api';
import type { BoardView, ChecklistView, SalesView, Step, WorkItem, WorkspaceView } from '../api';
import { useView } from '../useView';

/*
 * The client's home (#299): what needs them, where they stand on hours, who
 * to talk to, what is late and what is coming, and how launch is going.
 * Every figure is the plugin's — the same reads the wp-admin overview makes.
 *
 * Delivery work is read-only here. The one block a client can act on is the
 * checklist steps that are theirs; everything else is with the studio.
 */

const SECTION: Record< string, string > = {
  foundations: 'Foundations',
  'build-reviews': 'Build reviews',
  launch: 'Launch',
};

function Loading( { what }: { what: string } ) {
  return (
    <Card>
      <p className="fc-muted" role="status" aria-busy="true">
        Reading { what }…
      </p>
    </Card>
  );
}

function Unreachable( { what, onRetry }: { what: string; onRetry: () => void } ) {
  return (
    <Card>
      <EmptyState
        icon={ CloudOff }
        hue="amber"
        dense
        title={ `${ what } could not be read` }
        body="The studio did not answer. What you last saw is still on your WordPress dashboard."
        action={
          <Button variant="secondary" size="sm" onClick={ onRetry }>
            Try again
          </Button>
        }
      />
    </Card>
  );
}

function CardHead( { title, children, right }: { title: string; children?: ReactNode; right?: ReactNode } ) {
  return (
    <div className="fc-card-head">
      <h3>{ title }</h3>
      { children }
      { right && <span className="fc-card-head-right">{ right }</span> }
    </div>
  );
}

/** The steps only the client can clear. */
function NeedsYou( { checklist }: { checklist: ChecklistView } ) {
  const mine = checklist.yours;
  const late = mine.filter( ( s ) => s.overdue );
  const critical = mine.filter( ( s ) => 1 === Number( s.launch_critical ) );

  return (
    <Card pad={ 0 } className="fc-needs-you" testId="bwx-needs-you">
      <div className="fc-needs-you-head">
        <h3>Needs your review or confirmation</h3>
        <Tag tone={ mine.length ? 'warn' : 'neutral' }>{ mine.length }</Tag>
        { late.length > 0 && <Tag tone="danger">{ late.length } overdue</Tag> }
        { critical.length > 0 && <Tag tone="brand">{ critical.length } launch-critical</Tag> }
        <span className="fc-muted">These are the only items you can clear yourself. Everything else is with the studio team.</span>
      </div>
      { 0 === mine.length ? (
        <EmptyState icon={ CircleCheck } hue="emerald" dense title="Nothing waiting on you" body="Every step that is yours has been sent to us." />
      ) : (
        <ul className="fc-needs-you-list">
          { mine.map( ( step: Step ) => (
            <li key={ step.id } data-testid="bwx-needs-you-step">
              <span className="fc-needs-you-kind">
                <span className="fk-mono">{ SECTION[ step.section ] ?? step.section }</span>
                <span className="fc-muted">Onboarding step</span>
              </span>
              <span className="fc-needs-you-what">
                <span>
                  { step.title }
                  { 1 === Number( step.launch_critical ) && <Tag tone="brand">Launch-critical</Tag> }
                </span>
                { step.feedback && <span className="fc-muted">{ step.feedback }</span> }
              </span>
              <span className="fc-needs-you-when">
                <span className="fk-mono" data-late={ step.overdue ? 'true' : undefined }>
                  { step.due_on ? day( step.due_on ) : 'No date' }
                  { step.overdue ? ' · late' : '' }
                </span>
                <Tag tone={ 'returned' === step.status ? 'warn' : 'neutral' }>{ step.status.replace( /-/g, ' ' ) }</Tag>
              </span>
              <span>
                <a className="fk-btn" data-variant="secondary" data-size="sm" href={ `#onboarding/${ step.id }` }>
                  Open step
                </a>
              </span>
            </li>
          ) ) }
        </ul>
      ) }
      <div className="fc-card-foot">Answering here does not move work between stages — it gives the studio team what they need to move it.</div>
    </Card>
  );
}

function SupportDetails( { sales }: { sales: SalesView } ) {
  const entitlement = Array.isArray( sales.entitlement ) ? null : sales.entitlement;
  const support = Array.isArray( sales.support ) ? null : sales.support;
  const granted = entitlement?.hours_granted ?? 0;
  const balance = sales.balance ?? 0;
  const topUps = sales.purchases.filter( ( p ) => 'top-up' === p.kind ).reduce( ( sum, p ) => sum + ( p.hours ?? 0 ), 0 );
  const active = entitlement?.may_use_hours;

  return (
    <Card testId="bwx-support-details">
      <CardHead
        title="Support details"
        right={
          <a href="#sales" className="fc-link">
            Support &amp; hours
          </a>
        }
      >
        { entitlement && (
          <Tag tone={ active ? 'ok' : 'warn' } dot={ !! active }>
            { entitlement.label }
          </Tag>
        ) }
      </CardHead>
      { entitlement && granted > 0 ? (
        <>
          <Meter
            label={ entitlement.label }
            allocation={ granted }
            used={ Math.max( 0, granted - balance ) }
            note={ entitlement.term_ends_on ? `Term ends ${ day( entitlement.term_ends_on ) }` : undefined }
            warn={ balance <= 0 }
          />
          <div className="fc-facts">
            <div>
              <Eyebrow>Effective</Eyebrow>
              <div>{ entitlement.starts_on ? day( entitlement.starts_on ) : '—' }</div>
            </div>
            <div>
              <Eyebrow>Runs to</Eyebrow>
              <div>{ entitlement.ends_on ? day( entitlement.ends_on ) : '—' }</div>
            </div>
            <div>
              <Eyebrow>Top-ups</Eyebrow>
              <div>{ topUps > 0 ? hours( topUps ) : 'None' }</div>
            </div>
          </div>
          <p className="fc-muted">Balance as the studio has it: { hours( balance ) } left.</p>
        </>
      ) : (
        <p className="fc-muted">{ support?.label ?? 'No support package' }. You can still report anything that is broken and ask for things; new chargeable work waits for a package.</p>
      ) }
      { support?.refused.includes( 'chargeable-work' ) && granted > 0 && (
        <p className="fc-muted" data-testid="bwx-support-refused">
          New chargeable work cannot be scheduled until a support package is in place.
        </p>
      ) }
    </Card>
  );
}

function PointOfContact( { workspace }: { workspace: WorkspaceView } ) {
  const contact = Array.isArray( workspace.contact ) ? null : workspace.contact;
  const name = contact?.display_name ?? '';

  return (
    <Card tone="tint" pad={ 28 } testId="bwx-contact">
      <Eyebrow tone="brand">Your point of contact</Eyebrow>
      { name ? (
        <>
          <div className="fc-contact-name">{ name }</div>
          { contact?.role && <div className="fc-contact-role">{ contact.role }</div> }
          <p className="fc-muted">Anything about your work, your hours or your launch goes to { name.split( ' ' )[ 0 ] } first.</p>
          { contact?.email && (
            <a className="fk-btn" data-variant="secondary" data-size="md" href={ `mailto:${ contact.email }` }>
              Email { name.split( ' ' )[ 0 ] }
            </a>
          ) }
        </>
      ) : (
        <EmptyState icon={ UserRound } hue="blue" dense title="No contact assigned yet" body="The studio is sorting that out; anything urgent can go to whoever set this site up." />
      ) }
    </Card>
  );
}

function daysLate( due: string ): number {
  const today = new Date();
  today.setUTCHours( 0, 0, 0, 0 );
  const then = new Date( `${ due }T00:00:00Z` );
  return Math.round( ( today.getTime() - then.getTime() ) / 86400000 );
}

function WorkBand( {
  title,
  note,
  tone,
  rows,
  empty,
  footer,
  reason,
  testId,
}: {
  title: string;
  note: string;
  tone: 'danger' | 'info';
  rows: Array< { item: WorkItem; reason?: 'blocked' | 'overdue' } >;
  empty: string;
  footer: string;
  reason?: boolean;
  testId: string;
} ) {
  return (
    <Card pad={ 0 } className="fc-band" testId={ testId }>
      <CardHead
        title={ title }
        right={
          <a href="#board" className="fc-link">
            Open board
          </a>
        }
      >
        <Tag tone={ rows.length ? tone : 'neutral' }>{ rows.length }</Tag>
        <span className="fc-muted">{ note }</span>
      </CardHead>
      <DataView
        bare
        columns={ [
          { key: 'title', label: 'What it is', wrap: true, render: ( r ) => <a href={ `#board/${ r.item.id }` }>{ r.item.title }</a> },
          { key: 'kind', label: 'Type', render: ( r ) => r.item.work_type_label },
          { key: 'stage', label: 'Stage', sortable: false, render: ( r ) => <StageChip stage={ r.item.stage } dense /> },
          {
            key: 'date',
            label: 'Date',
            mono: true,
            sortBy: ( r ) => r.item.planned_due,
            render: ( r ) => {
              const due = r.item.planned_due;
              if ( ! due ) return '—';
              const late = daysLate( due );
              return (
                <span data-late={ late > 0 ? 'true' : undefined }>
                  { day( due ) }
                  { late > 0 ? ` (${ late }d late)` : '' }
                </span>
              );
            },
          },
          {
            key: 'flag',
            label: 'Yours or ours',
            sortable: false,
            render: ( r ) =>
              reason && 'blocked' === r.reason ? (
                <Tag tone="danger">Blocked — with us</Tag>
              ) : reason ? (
                <Tag tone="warn">Overdue — with us</Tag>
              ) : (
                <Tag tone="ok" dot>
                  On track — with us
                </Tag>
              ),
          },
        ] }
        rows={ rows.map( ( r ) => ( { ...r, id: r.item.id } ) ) }
        empty={ empty }
        footer={ <span>{ footer }</span> }
      />
    </Card>
  );
}

function Launch( { checklist }: { checklist: ChecklistView } ) {
  const { progress, yours: next } = checklist;

  return (
    <Card testId="bwx-launch-progress">
      <CardHead
        title="Launch readiness"
        right={
          <a href="#onboarding" className="fc-link">
            Open checklist
          </a>
        }
      >
        { next.length > 0 ? <Tag tone="warn">{ next.length } { 1 === next.length ? 'step needs' : 'steps need' } you</Tag> : progress.launch_ready ? <Tag tone="ok" dot>Ready</Tag> : null }
      </CardHead>
      { 0 === progress.required ? (
        <EmptyState icon={ ClipboardList } hue="slate" dense title="No checklist yet" body="Your launch checklist appears here once the studio sets it up." />
      ) : (
        <>
          <div className="fc-progress">
            <div className="fc-progress-track" role="meter" aria-valuemin={ 0 } aria-valuemax={ 100 } aria-valuenow={ progress.completion } aria-label="Launch progress">
              <span style={ { width: `${ progress.completion }%` } } />
            </div>
            <span className="fk-mono">{ progress.completion }%</span>
          </div>
          <p className="fc-muted">
            { progress.approved } of { progress.required } important steps approved. Counted from the steps we have approved; we cannot go live until the important ones are done.
          </p>
        </>
      ) }
    </Card>
  );
}

export function Dashboard() {
  const workspace = useView< WorkspaceView >( api.workspace );
  const board = useView< BoardView >( api.board );
  const sales = useView< SalesView >( api.sales );
  const checklist = useView< ChecklistView >( api.checklist );

  const attention = board.view?.attention ?? [];
  const upcoming = ( board.view?.upcoming ?? [] ).map( ( item ) => ( { item } ) );

  return (
    <div className="fc-stack" data-testid="bwx-dashboard">
      { checklist.loading ? <Loading what="what needs you" /> : checklist.view?.ok ? <NeedsYou checklist={ checklist.view } /> : <Unreachable what="What needs you" onRetry={ () => checklist.reload( true ) } /> }

      <div className="fc-two-up">
        { sales.loading ? <Loading what="your hours" /> : sales.view?.ok ? <SupportDetails sales={ sales.view } /> : <Unreachable what="Your hours" onRetry={ () => sales.reload( true ) } /> }
        { workspace.loading ? <Loading what="your contact" /> : workspace.view?.ok ? <PointOfContact workspace={ workspace.view } /> : <Unreachable what="Your contact" onRetry={ () => workspace.reload( true ) } /> }
      </div>

      { board.loading ? (
        <Loading what="your work" />
      ) : board.view?.ok ? (
        <>
          <WorkBand
            title="Needs attention"
            note="Blocked, or past its date."
            tone="danger"
            rows={ attention }
            reason
            empty="Nothing is blocked or overdue"
            footer="Items stay listed until they are resolved — they are never hidden."
            testId="bwx-attention"
          />
          <WorkBand
            title="Coming up"
            note="Dated and on its way."
            tone="info"
            rows={ upcoming }
            empty="Nothing has a date on it yet. Work appears here once it is scheduled."
            footer="Dates are the studio team's current plan and update as work moves."
            testId="bwx-upcoming"
          />
        </>
      ) : (
        <Unreachable what="Your work" onRetry={ () => board.reload( true ) } />
      ) }

      { checklist.view?.ok && <Launch checklist={ checklist.view } /> }
    </div>
  );
}
