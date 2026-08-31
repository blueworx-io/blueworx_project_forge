import { useCallback, useEffect, useState } from 'react';
import type { OnboardingBoard, OnboardingChoice, OnboardingFilters, OnboardingSite } from '../types';
import { api, isDenied, messageFor } from '../api';
import { query, statusWord } from '../onboarding';
import { OnboardingPanel } from './OnboardingPanel';
import { Screen } from './States';

/**
 * Every client's launch readiness in one view (#165).
 *
 * The second studio screen that spans clients, after the request queue — and
 * for a different reason. The queue spans clients because a request has not
 * become work yet and has no site to sit under. This spans clients because
 * onboarding is a thing each client does once and then never again: nobody ever
 * asks "how is this client's onboarding going" without also meaning "and which
 * of the others is about to slip".
 *
 * **A filter narrows what is listed, never what is counted.** Every figure on a
 * row is worked out on the server from that site's whole checklist, by the same
 * code the client's own page uses. Filter to overdue steps and a client at 60%
 * is still at 60%. That is why the filters go to the server rather than being
 * applied here as the queue's are: drawing four steps correctly would otherwise
 * mean shipping every step of every client to the browser to count them.
 *
 * The row opens because a board of numbers is a board people ask questions
 * about and cannot answer. The panel names the steps behind the figures, and it
 * is where a step waiting on us is approved or sent back (#163) — those
 * decisions have had no screen until now.
 */

export function OnboardingScreen() {
  const [ filters, setFilters ] = useState< OnboardingFilters >( {} );
  const [ board, setBoard ] = useState< OnboardingBoard | undefined >();
  const [ openId, setOpenId ] = useState( '' );
  const [ notice, setNotice ] = useState( '' );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'error' | 'denied' >( 'loading' );

  const load = useCallback( async () => {
    try {
      const answer = await api< OnboardingBoard >( `/onboarding/board${ query( filters ) }` );

      if ( answer.denied ) {
        setState( 'denied' );
        return;
      }

      setBoard( answer );
      setState( 'ready' );
    } catch ( failure ) {
      setNotice( messageFor( failure, 'The onboarding board could not be read.' ) );
      setState( isDenied( failure ) ? 'denied' : 'error' );
    }
  }, [ filters ] );

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [ load ] );

  /*
   * Changing a filter is what starts a read, so it is what says the screen is
   * loading — said here rather than in the effect, which would be setting state
   * during a pass the effect is already reacting to.
   */
  function narrow( next: OnboardingFilters ) {
    setState( 'loading' );
    setOpenId( '' );
    setFilters( next );
  }

  /** Sets one filter, or drops it when the empty option is chosen. */
  function set( key: keyof OnboardingFilters, value: string ) {
    const next = { ...filters };

    if ( '' === value ) {
      delete next[ key ];
    } else {
      // Each filter's own values are a closed list on the server, which drops
      // anything it does not recognise. This cast carries a value from a select
      // built out of that same list.
      ( next as Record< string, string > )[ key ] = value;
    }

    narrow( next );
  }

  const sites = board?.sites ?? [];
  const open = sites.find( ( one ) => one.client_site_id === openId );
  const filtered = 0 < Object.keys( filters ).length;

  return (
    <>
      <header className="bwx-header" data-testid="bwx-onboarding-header">
        <span className="bwx-eyebrow">Onboarding</span>

        <Choose
          label="Client"
          testId="bwx-onboarding-client"
          empty="All clients"
          options={ board?.facets.clients ?? [] }
          value={ filters.client_id ?? '' }
          onChange={ ( value ) => set( 'client_id', value ) }
        />

        <Choose
          label="Checklist"
          testId="bwx-onboarding-template"
          empty="Any checklist"
          options={ board?.facets.templates ?? [] }
          value={ filters.template_id ?? '' }
          onChange={ ( value ) => set( 'template_id', value ) }
        />

        <Choose
          label="Point of contact"
          testId="bwx-onboarding-contact"
          empty="Any contact"
          options={ board?.facets.contacts ?? [] }
          value={ filters.contact_id ?? '' }
          onChange={ ( value ) => set( 'contact_id', value ) }
        />

        <Choose
          label="Owner"
          testId="bwx-onboarding-owner"
          empty="Anybody"
          options={ [
            { id: 'side:internal', label: 'Us' },
            { id: 'side:client', label: 'The client' },
            ...( board?.facets.owners ?? [] ).map( ( one ) => ( {
              id: `who:${ one.id }`,
              label: one.label,
            } ) ),
          ] }
          value={ ownerValue( filters ) }
          onChange={ ( value ) => {
            const next = { ...filters };

            delete next.owner_side;
            delete next.owner_id;

            if ( value.startsWith( 'side:' ) ) {
              next.owner_side = value.slice( 5 );
            } else if ( value.startsWith( 'who:' ) ) {
              next.owner_id = value.slice( 4 );
            }

            narrow( next );
          } }
        />

        <Choose
          label="Step status"
          testId="bwx-onboarding-status"
          empty="Any status"
          options={ ( board?.statuses ?? [] ).map( ( slug ) => ( {
            id: slug,
            label: statusWord( slug ),
          } ) ) }
          value={ filters.status ?? '' }
          onChange={ ( value ) => set( 'status', value ) }
        />

        <Choose
          label="Launch"
          testId="bwx-onboarding-launch"
          empty="Ready or not"
          options={ [
            { id: 'ready', label: 'Ready to launch' },
            { id: 'not-ready', label: 'Not ready' },
          ] }
          value={ filters.launch ?? '' }
          onChange={ ( value ) => set( 'launch', value ) }
        />

        <label className="bwx-field-inline">
          <input
            type="checkbox"
            data-testid="bwx-onboarding-overdue"
            checked={ 'yes' === filters.overdue }
            onChange={ ( event ) => set( 'overdue', event.target.checked ? 'yes' : '' ) }
          />
          <span>Overdue</span>
        </label>

        <label className="bwx-field-inline">
          <input
            type="checkbox"
            data-testid="bwx-onboarding-blocked"
            checked={ 'yes' === filters.blocked }
            onChange={ ( event ) => set( 'blocked', event.target.checked ? 'yes' : '' ) }
          />
          <span>Blocked</span>
        </label>

        <span className="bwx-header-spacer" />

        <span className="bwx-mono" data-testid="bwx-onboarding-count">
          { sites.length } { 1 === sites.length ? 'site' : 'sites' }
        </span>
      </header>

      { '' !== notice && 'error' !== state && 'denied' !== state && (
        <p className="bwx-notice" data-testid="bwx-onboarding-notice" role="status" style={ { margin: '12px 20px 0' } }>
          { notice }
        </p>
      ) }

      { 'loading' === state && <Screen state="loading" detail="Reading where every client has got to." /> }

      { 'denied' === state && (
        <Screen
          state="denied"
          testId="bwx-onboarding-state-screen"
          detail="You are signed in, but not on any client whose onboarding you may read. Ask for a membership, or for cross-client access."
        />
      ) }

      { 'error' === state && (
        <Screen
          state="error"
          testId="bwx-onboarding-state-screen"
          detail={ notice }
          action={
            <button
              type="button"
              className="bwx-button"
              onClick={ () => {
                setState( 'loading' );
                void load();
              } }
            >
              Try again
            </button>
          }
        />
      ) }

      { 'ready' === state && 0 === sites.length && ! filtered && (
        <Screen
          state="empty"
          testId="bwx-onboarding-state-screen"
          title="Nobody is onboarding"
          detail="Give a client's site a checklist from the clients screen, and it appears here."
        />
      ) }

      { /*
         Filtered to nothing is not the same as nothing, and saying which is the
         difference between "clear the filters" and "there is no work to do".
       */ }
      { 'ready' === state && 0 === sites.length && filtered && (
        <Screen
          state="empty"
          testId="bwx-onboarding-state-screen"
          title="Nothing matches those filters"
          detail={ `${ board?.total ?? 0 } ${ 1 === board?.total ? 'site is' : 'sites are' } hidden by them.` }
          action={
            <button type="button" className="bwx-button" onClick={ () => narrow( {} ) }>
              Clear filters
            </button>
          }
        />
      ) }

      { 'ready' === state && 0 < sites.length && undefined !== board && (
        <div className="bwx-onboarding">
          <p className="bwx-onboarding-summary" data-testid="bwx-onboarding-summary">
            <strong>{ board.totals.launch_ready }</strong> of { board.totals.sites } ready to launch
            <span aria-hidden="true"> · </span>
            <strong>{ board.totals.awaiting_review }</strong> waiting on us
            <span aria-hidden="true"> · </span>
            <strong>{ board.totals.overdue }</strong> overdue
            <span aria-hidden="true"> · </span>
            <strong>{ board.totals.blocked }</strong> blocked
          </p>

          <table className="bwx-table" data-testid="bwx-onboarding-table">
            <caption className="bwx-visually-hidden">
              Every client site being onboarded, how far through it is, and what is standing in its way
            </caption>
            <thead>
              <tr>
                <th scope="col">Client</th>
                <th scope="col">How far through</th>
                <th scope="col">Launch</th>
                <th scope="col">Needs attention</th>
                <th scope="col">Next due</th>
                <th scope="col">Point of contact</th>
              </tr>
            </thead>
            <tbody>
              { sites.map( ( site ) => (
                <Row key={ site.client_site_id } site={ site } onOpen={ () => setOpenId( site.client_site_id ) } />
              ) ) }
            </tbody>
          </table>
        </div>
      ) }

      { undefined !== open && (
        <OnboardingPanel
          site={ open }
          onClose={ () => setOpenId( '' ) }
          onDecided={ ( message ) => {
            setNotice( message );

            /*
             * Re-read rather than patch the row. A decision moves a step, which
             * moves completion, launch readiness and three counts with it, and
             * working any of those out here would be the second implementation
             * this whole screen exists to avoid.
             *
             * Quietly, though: the panel is open over the table, and blanking
             * what is behind it to say "loading" would make a click somebody
             * just made look like it broke something.
             */
            void load();
          } }
        />
      ) }
    </>
  );
}

/** Which of the two owner filters is set, as the one select's value. */
function ownerValue( filters: OnboardingFilters ): string {
  if ( undefined !== filters.owner_side ) {
    return `side:${ filters.owner_side }`;
  }

  return undefined === filters.owner_id ? '' : `who:${ filters.owner_id }`;
}

/** One labelled select. Nine filters is a lot of markup to write nine times. */
function Choose( {
  label,
  testId,
  empty,
  options,
  value,
  onChange,
}: {
  label: string;
  testId: string;
  empty: string;
  options: OnboardingChoice[];
  value: string;
  onChange: ( value: string ) => void;
} ) {
  return (
    <select
      className="bwx-select"
      data-testid={ testId }
      aria-label={ label }
      value={ value }
      onChange={ ( event ) => onChange( event.target.value ) }
    >
      <option value="">{ empty }</option>
      { options.map( ( one ) => (
        <option key={ one.id } value={ one.id }>
          { one.label }
        </option>
      ) ) }
    </select>
  );
}

/** One site: where it has got to, and what somebody has to do about it. */
function Row( { site, onOpen }: { site: OnboardingSite; onOpen: () => void } ) {
  return (
    <tr
      className="bwx-row"
      data-testid="bwx-onboarding-row"
      data-site={ site.client_site_id }
      data-launch={ site.launch_ready ? 'ready' : 'not-ready' }
      style={
        {
          '--row-rail': site.launch_ready ? 'var( --gate-pass )' : 'var( --color-chalk )',
        } as React.CSSProperties
      }
    >
      <td>
        <button type="button" className="bwx-row-open" onClick={ onOpen }>
          { '' === site.client_name ? site.client_site_id : site.client_name }
        </button>
        <span className="bwx-card-meta">
          <span className="bwx-mono">{ site.site_name }</span>
          <span className="bwx-eyebrow">
            { site.template_name } v{ site.template_version }
          </span>
        </span>
      </td>

      <td>
        <span className="bwx-progress" aria-hidden="true">
          <span className="bwx-progress-fill" style={ { inlineSize: `${ site.completion }%` } } />
        </span>
        <span className="bwx-progress-figures" data-testid="bwx-onboarding-completion">
          { site.approved } / { site.required } steps
          <span className="bwx-progress-share"> · { site.completion }%</span>
        </span>
      </td>

      <td>
        { site.launch_ready ? (
          <span className="bwx-chip" data-launch="ready">
            Ready
          </span>
        ) : (
          <span className="bwx-chip" data-launch="not-ready" title={ site.blocking.map( ( one ) => one.title ).join( ', ' ) }>
            { 0 === site.blocking.length
              ? 'Not ready'
              : `${ site.blocking.length } to go` }
          </span>
        ) }
      </td>

      <td>
        <span className="bwx-card-meta">
          { 0 < site.awaiting_review && (
            <span className="bwx-chip" data-attention="review" data-testid="bwx-onboarding-awaiting">
              { site.awaiting_review } waiting on us
            </span>
          ) }
          { 0 < site.overdue && (
            <span className="bwx-chip" data-attention="overdue">
              { site.overdue } overdue
            </span>
          ) }
          { 0 < site.blocked && (
            <span className="bwx-chip" data-attention="blocked">
              { site.blocked } blocked
            </span>
          ) }
          { 0 === site.awaiting_review + site.overdue + site.blocked && (
            <span className="bwx-mono">Nothing</span>
          ) }
        </span>
      </td>

      <td className="bwx-mono">{ '' === site.next_due ? '—' : site.next_due }</td>

      <td>{ '' === site.contact_name ? <span className="bwx-mono">Nobody named</span> : site.contact_name }</td>
    </tr>
  );
}
