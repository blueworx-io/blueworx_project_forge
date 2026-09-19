import { useEffect, useRef, useState } from 'react';
import { LifeBuoy, Receipt } from 'lucide-react';
import type { LedgerEntry, SupportAnswer, SupportOffer, SupportPeriod, SupportPreview, SupportState } from '../types';
import { api, isDenied, messageFor } from '../api';
import { useLiveReload } from '../live';
import { Button, DataView, EmptyState, Field, Modal, Panel, Select, Stat, Tag, TextArea, TextInput } from '../kit';
import type { Column } from '../kit';
import { hoursLabel, priceLabel } from './PackagesScreen';
import { SitePicker } from './SitePicker';
import { failed, NOTHING_SAID, Notice, ok, Screen } from './States';
import type { Said } from './States';

/**
 * A site's support (PR 5 of spec 2026-09-16), in the app: what it is on,
 * every period it has been in, every hour it has, and the six things that
 * can be done to it. Reads and writes `/client-sites/<id>/support` and
 * nothing else; the admin page stays beside it until every screen has moved.
 *
 * Two rules from the admin page are kept whole. COMM-2: the sum somebody
 * agrees to is the sum the ledger receives, so the assign form shows the
 * server's own preview and cannot be saved until it has. COMM-4: nothing
 * here voids hours — suspending and cancelling leave the balance alone, and
 * taking hours away is an adjustment with a reason on it.
 */

/** What a period's state means, toned as the admin page tones it: nothing is danger. */
function stateTag( state: SupportState ) {
  return <Tag tone={ 'active' === state ? 'ok' : 'neutral' }>{ STATE_LABELS[ state ] ?? state }</Tag>;
}

/** `Commerce\Support::label()`, for a period's own state; the position carries its label from the server. */
const STATE_LABELS: Record< SupportState, string > = {
  none: 'No support package',
  scheduled: 'Starts later',
  active: 'On support',
  suspended: 'Suspended',
  lapsed: 'Lapsed — hours frozen, pending renewal',
};

/** What each kind of ledger entry is, in words. The raw kind stands in for one not named here. */
const ENTRY_LABELS: Record< string, string > = {
  'allocation': 'Package hours',
  'top-up': 'Bought hours',
  'work-reservation': 'Work reserved',
  'work-usage': 'Work done',
  'work-release': 'Work released',
  'meeting-reservation': 'Meeting reserved',
  'meeting-usage': 'Meeting held',
  'meeting-release': 'Meeting released',
  'adjustment': 'Adjustment',
  'expiry': 'Expired',
  'rollover': 'Carried over',
};

/** +5 → "+5h", −2 → "-2h": the sign is the point of a ledger line. */
function signedHours( value: number ): string {
  return `${ 0 <= value ? '+' : '' }${ hoursLabel( value ) }`;
}

/** The number itself, for a test to read without parsing the label. */
function plain( value: number ): string {
  return String( Number( value.toFixed( 2 ) ) );
}

function today(): string {
  return new Date().toISOString().slice( 0, 10 );
}

export function SupportScreen( { site }: { site: string } ) {
  const [ siteId, setSiteId ] = useState( site );
  const [ answer, setAnswer ] = useState< SupportAnswer | null >( null );
  const [ state, setState ] = useState< 'idle' | 'loading' | 'ready' | 'denied' | 'error' >( site ? 'loading' : 'idle' );
  const [ notice, setNotice ] = useState< Said >( NOTHING_SAID );
  const [ panel, setPanel ] = useState< 'assign' | 'topup' | 'adjust' | 'suspend' | null >( null );
  const [ busy, setBusy ] = useState( false );

  /** A write's answer is the whole picture, so it is shown rather than re-read. */
  function landed( fresh: SupportAnswer, said = '' ) {
    setAnswer( fresh );
    setPanel( null );
    setNotice( '' === said ? NOTHING_SAID : ok( said ) );
  }

  async function load( id: string = siteId ) {
    setNotice( NOTHING_SAID );

    if ( '' === id ) {
      setAnswer( null );
      setState( 'idle' );

      return;
    }

    try {
      const fresh = await api< SupportAnswer >( `/client-sites/${ id }/support` );

      setAnswer( fresh );
      setState( 'ready' );
    } catch ( error ) {
      setState( isDenied( error ) ? 'denied' : 'error' );
      setNotice( failed( messageFor( error, 'The site\'s support could not be read.' ) ) );
    }
  }

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load( site );
    // The site prop is a landing, read once; picking is the picker's job.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [] );

  useLiveReload( () => load() );

  function pick( id: string ) {
    if ( id === siteId ) {
      return;
    }

    setSiteId( id );
    setPanel( null );
    setState( id ? 'loading' : 'idle' );
    void load( id );
  }

  /** One dated action from today, after a question: resume and cancel need no form. */
  async function act( path: 'resume' | 'cancel', question: string, said: string, fallback: string ) {
    if ( ! window.confirm( question ) ) {
      return;
    }

    setBusy( true );
    setNotice( NOTHING_SAID );

    try {
      landed( await api< SupportAnswer >( `/client-sites/${ siteId }/support/${ path }`, { method: 'POST', body: { from: today() } } ), said );
    } catch ( error ) {
      setNotice( failed( messageFor( error, fallback ) ) );
    } finally {
      setBusy( false );
    }
  }

  const periodColumns: Column< SupportPeriod >[] = [
    { key: 'from', label: 'From', mono: true, width: 120, render: ( p ) => p.starts_on },
    { key: 'to', label: 'To', mono: true, width: 120, render: ( p ) => p.ends_on || '—' },
    { key: 'state', label: 'Position', width: 140, render: ( p ) => stateTag( p.state ) },
    { key: 'package', label: 'Package', wrap: true, render: ( p ) => ( p.package_name ? `${ p.package_name } v${ p.package_version }` : '—' ) },
    { key: 'hours', label: 'Hours granted', mono: true, align: 'right', width: 120, render: ( p ) => hoursLabel( p.hours_granted ) },
    { key: 'ended', label: 'Why it ended', wrap: true, render: ( p ) => p.ended_because || '—' },
  ];

  const ledgerColumns: Column< LedgerEntry >[] = [
    { key: 'when', label: 'When', mono: true, width: 120, render: ( e ) => e.when },
    { key: 'what', label: 'What', width: 160, render: ( e ) => ENTRY_LABELS[ e.event_type ] ?? e.event_type },
    { key: 'hours', label: 'Hours', mono: true, align: 'right', width: 90, render: ( e ) => signedHours( e.hours ) },
    { key: 'why', label: 'Why', wrap: true, render: ( e ) => e.reason || '—' },
  ];

  const position = answer?.position ?? null;
  const covered = position && 'none' !== position.state && 'lapsed' !== position.state;

  return (
    <div className="bwx-support" data-testid="bwx-support">
      <SitePicker value={ siteId } onChange={ pick } testId="bwx-support-site" />

      { 'idle' === state && <EmptyState icon={ LifeBuoy } title="No site chosen" body="Choose a site to see what it is on." /> }
      { 'loading' === state && <Screen state="loading" testId="bwx-support-state-screen" /> }
      { 'denied' === state && <Screen state="denied" testId="bwx-support-state-screen" detail="A site's support is configuration, and configuration is the administrator's." /> }
      { 'error' === state && <Screen state="error" testId="bwx-support-state-screen" detail={ notice.text } /> }

      { 'ready' === state && answer && position && (
        <>
          <Notice said={ notice } testId="bwx-support-notice" />

          <Panel
            title="Position"
            right={
              <div className="bwx-moves">
                { /*
                   * In every state, as the admin page offers it: a covered
                   * site can change package mid-term, and what that means
                   * for the running period is the domain's decision.
                   */ }
                <Button size="sm" data-testid="bwx-support-assign" disabled={ busy } onClick={ () => setPanel( 'assign' ) }>
                  { covered ? 'Change package' : 'Assign a package' }
                </Button>
                { covered && 'suspended' === position.state && (
                  <Button size="sm" variant="secondary" data-testid="bwx-support-resume" disabled={ busy } onClick={ () => void act( 'resume', `Put ${ answer.site.name } back on support from today?`, 'Back on support.', 'That site could not be resumed.' ) }>
                    Resume
                  </Button>
                ) }
                { covered && 'suspended' !== position.state && (
                  <Button size="sm" variant="secondary" data-testid="bwx-support-suspend" disabled={ busy } onClick={ () => setPanel( 'suspend' ) }>
                    Suspend
                  </Button>
                ) }
                { covered && (
                  <Button size="sm" variant="danger" data-testid="bwx-support-cancel" disabled={ busy } onClick={ () => void act( 'cancel', `Cancel ${ answer.site.name }'s support from today? The hours it has stay on the ledger.`, 'Cancelled. The remaining hours are untouched — write them off with an adjustment if that is what was agreed.', 'That site\'s support could not be cancelled.' ) }>
                    Cancel
                  </Button>
                ) }
                <Button size="sm" variant="secondary" data-testid="bwx-support-top-up" disabled={ busy } onClick={ () => setPanel( 'topup' ) }>
                  Sell hours
                </Button>
                <Button size="sm" variant="secondary" data-testid="bwx-support-adjust" disabled={ busy } onClick={ () => setPanel( 'adjust' ) }>
                  Adjust
                </Button>
              </div>
            }
          >
            <div className="bwx-support-position">
              <Stat
                label="Position"
                tone={ position.may_use_hours ? 'ok' : 'neutral' }
                value={
                  <span data-testid="bwx-support-state" data-state={ position.state }>
                    { position.label }
                  </span>
                }
                sub={ position.may_use_hours ? 'Hours can be spent.' : 'Hours cannot be spent.' }
              />
              <Stat
                label="Hours left"
                value={
                  <span className="fk-mono" data-testid="bwx-support-balance" data-balance={ plain( position.balance ) }>
                    { hoursLabel( position.balance ) }
                  </span>
                }
                sub={ position.covered_until ? `Covered until ${ position.covered_until }.` : 'No end date on the record.' }
              />
            </div>
          </Panel>

          <Panel title="Every period" flush>
            <DataView< SupportPeriod >
              bare
              fixed
              columns={ periodColumns }
              rows={ answer.periods }
              sortable={ false }
              empty={ <EmptyState icon={ LifeBuoy } dense title="Never on a package" body="This site has never been on a package." /> }
              footer={ `${ answer.periods.length } ${ 1 === answer.periods.length ? 'period' : 'periods' }, oldest first` }
              testId="bwx-support-periods"
            />
          </Panel>

          <Panel title="Every hour" flush>
            <DataView< LedgerEntry >
              bare
              fixed
              columns={ ledgerColumns }
              rows={ answer.ledger }
              sortable={ false }
              empty={ <EmptyState icon={ Receipt } dense title="Nothing on the ledger" body="Nothing has happened to this site's hours yet." /> }
              footer={ `Left: ${ hoursLabel( position.balance ) }` }
              testId="bwx-support-ledger"
            />
          </Panel>

          { 'assign' === panel && <AssignForm siteId={ siteId } packages={ answer.packages } changing={ Boolean( covered ) } onClose={ () => setPanel( null ) } onSaved={ landed } /> }
          { 'topup' === panel && <TopUpForm siteId={ siteId } onClose={ () => setPanel( null ) } onSaved={ landed } /> }
          { 'adjust' === panel && <AdjustForm siteId={ siteId } onClose={ () => setPanel( null ) } onSaved={ landed } /> }
          { 'suspend' === panel && <SuspendForm siteId={ siteId } onClose={ () => setPanel( null ) } onSaved={ landed } /> }
        </>
      ) }
    </div>
  );
}

type Saved = ( answer: SupportAnswer, said?: string ) => void;

/**
 * Putting the site on a package. The sum is the server's, asked for on every
 * change and shown before Save is possible, so what is agreed to and what
 * the ledger receives are one figure (COMM-2).
 */
function AssignForm( { siteId, packages, changing, onClose, onSaved }: { siteId: string; packages: SupportOffer[]; changing: boolean; onClose: () => void; onSaved: Saved } ) {
  const [ version, setVersion ] = useState( '' );
  const [ from, setFrom ] = useState( today() );
  const [ until, setUntil ] = useState( '' );
  const [ note, setNote ] = useState( '' );
  const [ preview, setPreview ] = useState< SupportPreview | null >( null );
  const [ previewing, setPreviewing ] = useState( '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );
  // Answers can land out of order; only the one for the latest ask is shown.
  const asked = useRef( 0 );

  useEffect( () => {
    const sequence = asked.current + 1;
    asked.current = sequence;

    // eslint-disable-next-line react-hooks/set-state-in-effect
    setPreview( null );

    if ( '' === version ) {
      setPreviewing( '' );

      return;
    }

    setPreviewing( 'Working out what this would grant…' );

    const query = new URLSearchParams( { package_version: version, from, until } );

    api< SupportPreview >( `/client-sites/${ siteId }/support/preview?${ query.toString() }` )
      .then( ( sum ) => {
        if ( sequence === asked.current ) {
          setPreview( sum );
          setPreviewing( '' );
        }
      } )
      .catch( ( error: unknown ) => {
        if ( sequence === asked.current ) {
          setPreviewing( messageFor( error, 'That could not be worked out.' ) );
        }
      } );
  }, [ siteId, version, from, until ] );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      onSaved(
        await api< SupportAnswer >( `/client-sites/${ siteId }/support`, { method: 'POST', body: { package_version: version, starts_on: from, ends_on: until, note } } ),
        'Assigned. The hours are on the ledger below.'
      );
    } catch ( error ) {
      setNotice( messageFor( error, 'That site could not be put on the package.' ) );
    } finally {
      setBusy( false );
    }
  }

  const line = preview
    ? `${ hoursLabel( preview.hours ) } for ${ priceLabel( preview.price, preview.currency ) }, until ${ preview.ends_on }${ preview.prorated ? ', pro-rated to that date' : '' }`
    : previewing;

  return (
    <Modal
      title={ changing ? 'Change package' : 'Assign a package' }
      width={ 560 }
      testId="bwx-support-assign-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-support-assign-save" disabled={ busy || null === preview } onClick={ () => void save() }>
            { preview ? `Assign ${ hoursLabel( preview.hours ) }` : 'Assign' }
          </Button>
          <Button variant="ghost" data-testid="bwx-support-assign-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-support-assign-notice" role="status">
          { notice }
        </p>
      ) }

      { 0 === packages.length ? (
        <EmptyState icon={ Receipt } dense title="Nothing to put it on" body="There are no packages on offer. Add one on the Packages screen first." />
      ) : (
        <>
          <Field label="Package" required>
            { ( id ) => (
              <Select
                id={ id }
                data-testid="bwx-support-assign-package"
                value={ version }
                onChange={ ( event ) => setVersion( event.target.value ) }
                options={ [
                  { value: '', label: 'Choose a package' },
                  ...packages.map( ( one ) => ( {
                    value: one.current.id,
                    label: `${ one.name } — ${ hoursLabel( one.current.hours ) } for ${ one.current.validity_months } months, ${ priceLabel( one.current.price, one.current.currency ) }`,
                  } ) ),
                ] }
              />
            ) }
          </Field>
          <Field label="From" required>
            { ( id ) => <TextInput id={ id } type="date" data-testid="bwx-support-assign-from" value={ from } onChange={ ( event ) => setFrom( event.target.value ) } /> }
          </Field>
          <Field label="Aligned to a renewal date" help="Leave empty for a full term. Set a date to align this client with a shared renewal, and the hours and price are pro-rated to it.">
            { ( id ) => <TextInput id={ id } type="date" data-testid="bwx-support-assign-until" value={ until } onChange={ ( event ) => setUntil( event.target.value ) } /> }
          </Field>
          <Field label="Note">
            { ( id ) => <TextArea id={ id } rows={ 3 } maxLength={ 2000 } data-testid="bwx-support-assign-note" value={ note } onChange={ ( event ) => setNote( event.target.value ) } /> }
          </Field>
          <p className="bwx-hint" data-testid="bwx-support-assign-preview" role="status" aria-live="polite">
            { '' === line ? 'Choose a package to see what it would grant.' : line }
          </p>
        </>
      ) }
    </Modal>
  );
}

/** Selling hours on top of the package. They last a year and are spent last (COMM-4). */
function TopUpForm( { siteId, onClose, onSaved }: { siteId: string; onClose: () => void; onSaved: Saved } ) {
  const [ hours, setHours ] = useState( '' );
  const [ reason, setReason ] = useState( '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      onSaved(
        await api< SupportAnswer >( `/client-sites/${ siteId }/support/top-up`, { method: 'POST', body: { hours: Number( hours ) || 0, reason } } ),
        'Hours added. They last twelve months.'
      );
    } catch ( error ) {
      setNotice( messageFor( error, 'Those hours could not be added.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title="Sell more hours"
      testId="bwx-support-topup-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-support-topup-save" disabled={ busy } onClick={ () => void save() }>
            Add hours
          </Button>
          <Button variant="ghost" data-testid="bwx-support-topup-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-support-topup-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="Hours" required help="Bought hours last twelve months from today, and are used after the package's own (COMM-4).">
        { ( id ) => <TextInput id={ id } type="number" min="0.25" step="0.25" inputMode="decimal" data-testid="bwx-support-topup-hours" value={ hours } onChange={ ( event ) => setHours( event.target.value ) } /> }
      </Field>
      <Field label="What was bought">
        { ( id ) => <TextInput id={ id } maxLength={ 500 } data-testid="bwx-support-topup-reason" value={ reason } onChange={ ( event ) => setReason( event.target.value ) } /> }
      </Field>
    </Modal>
  );
}

/** Correcting the record, in either direction, with the reason the client will see (CAP-3). */
function AdjustForm( { siteId, onClose, onSaved }: { siteId: string; onClose: () => void; onSaved: Saved } ) {
  const [ hours, setHours ] = useState( '' );
  const [ reason, setReason ] = useState( '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      onSaved(
        await api< SupportAnswer >( `/client-sites/${ siteId }/support/adjust`, { method: 'POST', body: { hours: Number( hours ) || 0, reason } } ),
        'Adjusted. The reason is on the entry.'
      );
    } catch ( error ) {
      setNotice( messageFor( error, 'That adjustment could not be made.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title="Correct the record"
      testId="bwx-support-adjust-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-support-adjust-save" disabled={ busy } onClick={ () => void save() }>
            Adjust
          </Button>
          <Button variant="ghost" data-testid="bwx-support-adjust-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-support-adjust-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="Hours, negative to take away" required>
        { ( id ) => <TextInput id={ id } type="number" step="0.25" inputMode="decimal" data-testid="bwx-support-adjust-hours" value={ hours } onChange={ ( event ) => setHours( event.target.value ) } /> }
      </Field>
      <Field label="Reason" required help="The reason is not optional: it is what the client is shown, and what anybody asking six months later has to go on.">
        { ( id ) => <TextInput id={ id } maxLength={ 500 } data-testid="bwx-support-adjust-reason" value={ reason } onChange={ ( event ) => setReason( event.target.value ) } /> }
      </Field>
    </Modal>
  );
}

/** Stopping the cover from a date. The hours are left where they are. */
function SuspendForm( { siteId, onClose, onSaved }: { siteId: string; onClose: () => void; onSaved: Saved } ) {
  const [ from, setFrom ] = useState( today() );
  const [ note, setNote ] = useState( '' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function save() {
    setBusy( true );
    setNotice( '' );

    try {
      onSaved(
        await api< SupportAnswer >( `/client-sites/${ siteId }/support/suspend`, { method: 'POST', body: { from, note } } ),
        'Suspended. The remaining hours are untouched.'
      );
    } catch ( error ) {
      setNotice( messageFor( error, 'That site could not be suspended.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Modal
      title="Suspend support"
      description="The remaining hours stay on the ledger; nothing can be spent from them until the site is resumed."
      testId="bwx-support-suspend-form"
      onClose={ onClose }
      footer={
        <div className="bwx-moves">
          <Button data-testid="bwx-support-suspend-save" disabled={ busy } onClick={ () => void save() }>
            Suspend
          </Button>
          <Button variant="ghost" data-testid="bwx-support-suspend-cancel" onClick={ onClose }>
            Cancel
          </Button>
        </div>
      }
    >
      { '' !== notice && (
        <p className="bwx-notice" data-testid="bwx-support-suspend-notice" role="status">
          { notice }
        </p>
      ) }

      <Field label="From" required>
        { ( id ) => <TextInput id={ id } type="date" data-testid="bwx-support-suspend-from" value={ from } onChange={ ( event ) => setFrom( event.target.value ) } /> }
      </Field>
      <Field label="Note">
        { ( id ) => <TextArea id={ id } rows={ 3 } maxLength={ 2000 } data-testid="bwx-support-suspend-note" value={ note } onChange={ ( event ) => setNote( event.target.value ) } /> }
      </Field>
    </Modal>
  );
}
