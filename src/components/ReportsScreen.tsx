import { useCallback, useEffect, useState } from 'react';
import type { ReportsResponse, ReportSummary } from '../types';
import { api, isDenied, messageFor } from '../api';
import { Screen } from './States';

/**
 * Whether delivery is working (#176).
 *
 * Seven numbers, drawn exactly as the server gives them. Nothing is worked out
 * here — the moment a screen computes its own median it is showing a figure
 * that reconciles to nothing, and the whole argument for these reports is that
 * every one of them is the changelog counted rather than a figure kept
 * somewhere.
 *
 * **A count sits beside every median, and an absence is drawn as one.** A
 * median of four hours over two pieces of work is not a fact about delivery,
 * and a stage nothing has passed through is not a stage that is instant. Both
 * are drawn as "not enough to say" rather than as numbers, because a number on
 * a screen gets quoted in a meeting whatever the sample behind it was.
 */

/** Twelve weeks back: the window the server would default to anyway. */
function defaultRange(): { from: string; to: string } {
  const today = new Date();
  const start = new Date( today );

  start.setDate( today.getDate() - 84 );

  return { from: iso( start ), to: iso( today ) };
}

function iso( date: Date ): string {
  return date.toISOString().slice( 0, 10 );
}

/**
 * A duration, in the units somebody would say it in.
 *
 * Hours below two days, days above. "Ninety-one hours" is a number people
 * convert in their heads and get wrong.
 */
function duration( hours: number | null ): string {
  if ( null === hours ) {
    return '—';
  }

  if ( hours < 48 ) {
    return `${ round( hours ) } ${ 1 === round( hours ) ? 'hour' : 'hours' }`;
  }

  const days = round( hours / 24 );

  return `${ days } ${ 1 === days ? 'day' : 'days' }`;
}

function round( value: number ): number {
  return Math.round( value * 10 ) / 10;
}

/**
 * A number of support hours, as hours (#261).
 *
 * Deliberately not `duration`, which turns anything over two days into days.
 * Support hours are what a client is billed in, and "four days" is not a figure
 * anybody can check against an invoice.
 */
function hours( value: number ): string {
  return `${ round( value ) } ${ 1 === round( value ) ? 'hour' : 'hours' }`;
}

/** A share, as a whole percentage — nobody acts on a decimal place here. */
function share( value: number ): string {
  return `${ Math.round( value * 100 ) }%`;
}

/** The date a week bucket starts, without a year repeated down the column. */
function weekLabel( from: number ): string {
  const date = new Date( from * 1000 );

  return `${ date.getUTCDate() } ${ MONTHS[ date.getUTCMonth() ] ?? '' }`.trim();
}

const MONTHS = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];

/** What each stage is called, in the words the board uses. */
const STAGE_WORD: Record< string, string > = {
  'future-idea': 'Future Idea',
  triage: 'Triage',
  'bug-tracking': 'Bug Tracking',
  'documentation-period': 'Documentation',
  'technical-audit': 'Technical Audit',
  'design-process': 'Design',
  blocked: 'Blocked',
  'up-next': 'Up Next',
  'in-development': 'In Development',
  'in-review': 'In Review',
  completed: 'Completed',
  released: 'Released',
};

/**
 * One headline figure and the count behind it.
 *
 * The count is not decoration. A median over three pieces of work and a median
 * over three hundred are read the same way off a screen and mean very different
 * things, and the one that gets quoted is whichever was on the wall.
 */
function Figure( { label, summary, testId }: { label: string; summary: ReportSummary; testId: string } ) {
  return (
    <div className="bwx-report-figure" data-testid={ testId }>
      <span className="bwx-eyebrow">{ label }</span>
      <strong className="bwx-report-number">{ duration( summary.median_hours ) }</strong>
      <span className="bwx-report-count">
        { 0 === summary.count
          ? 'not enough to say'
          : `median of ${ summary.count } ${ 1 === summary.count ? 'item' : 'items' }` }
      </span>
    </div>
  );
}

export function ReportsScreen() {
  const [ range, setRange ] = useState( defaultRange );
  const [ data, setData ] = useState< ReportsResponse | undefined >();
  const [ notice, setNotice ] = useState( '' );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'error' | 'denied' >( 'loading' );

  const load = useCallback( async () => {
    try {
      setData( await api< ReportsResponse >( `/reports?from=${ range.from }&to=${ range.to }` ) );
      setState( 'ready' );
    } catch ( failure ) {
      setNotice( messageFor( failure, 'The delivery numbers could not be read.' ) );
      setState( isDenied( failure ) ? 'denied' : 'error' );
    }
  }, [ range.from, range.to ] );

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [ load ] );

  function ask( next: { from: string; to: string } ) {
    setState( 'loading' );
    setRange( next );
  }

  const reports = data?.reports;
  const busiest = Math.max(
    1,
    ...Object.values( reports?.stage_distribution ?? { none: 0 } )
  );

  return (
    <>
      <header className="bwx-header" data-testid="bwx-reports-header">
        <span className="bwx-eyebrow">Reports</span>

        <label className="bwx-field-inline">
          <span>From</span>
          <input
            type="date"
            className="bwx-input"
            data-testid="bwx-reports-from"
            value={ range.from }
            onChange={ ( event ) => ask( { ...range, from: event.target.value } ) }
          />
        </label>

        <label className="bwx-field-inline">
          <span>To</span>
          <input
            type="date"
            className="bwx-input"
            data-testid="bwx-reports-to"
            value={ range.to }
            onChange={ ( event ) => ask( { ...range, to: event.target.value } ) }
          />
        </label>
      </header>

      { '' !== notice && 'error' !== state && 'denied' !== state && (
        <p className="bwx-notice" role="status" style={ { margin: '12px 20px 0' } }>
          { notice }
        </p>
      ) }

      { 'loading' === state && <Screen state="loading" detail="Counting up the work." /> }

      { 'denied' === state && (
        <Screen
          state="denied"
          testId="bwx-reports-state-screen"
          detail="You are signed in, but not allowed to see the delivery numbers. Ask for that on one of your memberships."
        />
      ) }

      { 'error' === state && (
        <Screen
          state="error"
          testId="bwx-reports-state-screen"
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

      { /*
         Said once, plainly, rather than drawn as seven sections of zeroes. A
         window with no work in it and a window where delivery stopped look
         identical as charts, and only one of them is worth acting on.
       */ }
      { 'ready' === state && true === reports?.empty && (
        <Screen
          state="empty"
          testId="bwx-reports-state-screen"
          title="Nothing happened in this window"
          detail="No work moved between these dates. Widen the window, or check you hold the clients you expect to."
        />
      ) }

      { 'ready' === state && undefined !== reports && ! reports.empty && (
        <div className="bwx-reports" data-testid="bwx-reports">
          <section className="bwx-report" aria-labelledby="bwx-report-headlines">
            <h2 id="bwx-report-headlines" className="bwx-report-title">
              How long things take
            </h2>

            <div className="bwx-report-figures">
              <Figure
                label="Start to release"
                summary={ reports.cycle_time }
                testId="bwx-report-cycle-time"
              />
              <Figure
                label="In review"
                summary={ reports.review_turnaround }
                testId="bwx-report-review-turnaround"
              />
              <Figure
                label="Blocked"
                summary={ reports.blocked_time }
                testId="bwx-report-blocked-time"
              />
            </div>
          </section>

          <section className="bwx-report" aria-labelledby="bwx-report-where">
            <h2 id="bwx-report-where" className="bwx-report-title">
              Where work is sitting
            </h2>

            <table className="bwx-report-table" data-testid="bwx-report-stage-distribution">
              <caption className="bwx-visually-hidden">
                How much open work is in each stage right now
              </caption>
              <thead>
                <tr>
                  <th scope="col">Stage</th>
                  <th scope="col">Work</th>
                </tr>
              </thead>
              <tbody>
                { Object.entries( reports.stage_distribution ).map( ( [ stage, count ] ) => (
                  <tr key={ stage } data-bwx-stage={ stage }>
                    <th scope="row">{ STAGE_WORD[ stage ] ?? stage }</th>
                    <td>
                      <span
                        className="bwx-report-bar"
                        style={ { inlineSize: `${ Math.round( ( count / busiest ) * 100 ) }%` } }
                      />
                      <span className="bwx-mono">{ count }</span>
                    </td>
                  </tr>
                ) ) }
              </tbody>
            </table>
          </section>

          <section className="bwx-report" aria-labelledby="bwx-report-time-in-stage">
            <h2 id="bwx-report-time-in-stage" className="bwx-report-title">
              How long each stage takes
            </h2>

            <table className="bwx-report-table" data-testid="bwx-report-time-in-stage">
              <caption className="bwx-visually-hidden">
                Median time work spent in each stage before moving on
              </caption>
              <thead>
                <tr>
                  <th scope="col">Stage</th>
                  <th scope="col">Median</th>
                  <th scope="col">Out of</th>
                </tr>
              </thead>
              <tbody>
                { Object.entries( reports.time_in_stage ).map( ( [ stage, summary ] ) => (
                  <tr key={ stage } data-bwx-stage={ stage }>
                    <th scope="row">{ STAGE_WORD[ stage ] ?? stage }</th>
                    <td>{ duration( summary.median_hours ) }</td>
                    <td className="bwx-report-count">
                      { 0 === summary.count ? 'nothing yet' : `${ summary.count }` }
                    </td>
                  </tr>
                ) ) }
              </tbody>
            </table>
          </section>

          <section className="bwx-report" aria-labelledby="bwx-report-promises">
            <h2 id="bwx-report-promises" className="bwx-report-title">
              Promises kept
            </h2>

            { 0 === reports.planned_vs_actual.count ? (
              <p className="bwx-report-count" data-testid="bwx-report-planned-vs-actual">
                Nothing released in this window had a date promised for it, so there is nothing to
                compare.
              </p>
            ) : (
              <p data-testid="bwx-report-planned-vs-actual">
                <strong className="bwx-report-number">
                  { reports.planned_vs_actual.on_time } of { reports.planned_vs_actual.count }
                </strong>{ ' ' }
                released on or before the date they were promised for.
                { null !== reports.planned_vs_actual.median_days_late &&
                  ` The ones that were late ran over by ${ duration(
                    reports.planned_vs_actual.median_days_late * 24
                  ) } in the middle of the range.` }
              </p>
            ) }
          </section>

          <section className="bwx-report" aria-labelledby="bwx-report-throughput">
            <h2 id="bwx-report-throughput" className="bwx-report-title">
              What shipped
            </h2>

            <table className="bwx-report-table" data-testid="bwx-report-throughput">
              <caption className="bwx-visually-hidden">Work released each week</caption>
              <thead>
                <tr>
                  <th scope="col">Week beginning</th>
                  <th scope="col">Released</th>
                </tr>
              </thead>
              <tbody>
                { reports.throughput.weeks.map( ( week ) => (
                  <tr key={ week.from }>
                    <th scope="row">{ weekLabel( week.from ) }</th>
                    <td className="bwx-mono">{ week.released }</td>
                  </tr>
                ) ) }
              </tbody>
            </table>
          </section>

          <section className="bwx-report" aria-labelledby="bwx-report-capacity">
            <h2 id="bwx-report-capacity" className="bwx-report-title">
              How full people are
            </h2>

            <p data-testid="bwx-report-capacity">
              { null === reports.capacity_utilisation.share ? (
                'Nobody has their working hours set up yet, so there is nothing to be full of.'
              ) : (
                <>
                  <strong className="bwx-report-number">
                    { share( reports.capacity_utilisation.share ) }
                  </strong>{ ' ' }
                  of the studio&rsquo;s time is spoken for —{ ' ' }
                  { hours( reports.capacity_utilisation.committed ) } of{ ' ' }
                  { hours( reports.capacity_utilisation.available ) } across{ ' ' }
                  { reports.capacity_utilisation.people } people.
                  { 0 < reports.capacity_utilisation.over &&
                    ` ${ reports.capacity_utilisation.over } of them are over their hours.` }
                </>
              ) }
            </p>
          </section>

          <section className="bwx-report" aria-labelledby="bwx-report-overrides">
            <h2 id="bwx-report-overrides" className="bwx-report-title">
              When we went past our own rules
            </h2>

            <p data-testid="bwx-report-overrides">
              <strong className="bwx-report-number">{ reports.overrides.occasions }</strong>{ ' ' }
              { 1 === reports.overrides.occasions ? 'decision' : 'decisions' } to go ahead with an
              over-booked week, across { reports.overrides.capacity }{ ' ' }
              { 1 === reports.overrides.capacity ? 'job' : 'jobs' }.{ ' ' }
              { reports.overrides.workflow } went round a gate.
            </p>
          </section>

          <section className="bwx-report" aria-labelledby="bwx-report-hours">
            <h2 id="bwx-report-hours" className="bwx-report-title">
              Where clients&rsquo; hours went
            </h2>

            <table className="bwx-report-table" data-testid="bwx-report-hours">
              <caption className="bwx-visually-hidden">Support hours granted, spent and held</caption>
              <tbody>
                <tr>
                  <th scope="row">Bought</th>
                  <td className="bwx-mono">{ hours( reports.hours.granted ) }</td>
                </tr>
                <tr>
                  <th scope="row">Spent on work</th>
                  <td className="bwx-mono">{ hours( reports.hours.work_used ) }</td>
                </tr>
                <tr>
                  <th scope="row">Spent in meetings</th>
                  <td className="bwx-mono">{ hours( reports.hours.meeting_used ) }</td>
                </tr>
                <tr>
                  <th scope="row">Set aside, not yet spent</th>
                  <td className="bwx-mono">{ hours( reports.hours.held ) }</td>
                </tr>
                <tr>
                  <th scope="row">Corrected by hand</th>
                  <td className="bwx-mono">{ hours( reports.hours.adjusted ) }</td>
                </tr>
              </tbody>
            </table>
          </section>

          <section className="bwx-report" aria-labelledby="bwx-report-onboarding">
            <h2 id="bwx-report-onboarding" className="bwx-report-title">
              Sites still getting live
            </h2>

            <p data-testid="bwx-report-onboarding">
              { 0 === reports.onboarding_readiness.sites ? (
                'No site has a checklist yet.'
              ) : (
                <>
                  <strong className="bwx-report-number">
                    { reports.onboarding_readiness.not_ready }
                  </strong>{ ' ' }
                  of { reports.onboarding_readiness.sites }{ ' ' }
                  { 1 === reports.onboarding_readiness.sites ? 'site is' : 'sites are' } not ready to
                  go live.
                  { null !== reports.onboarding_readiness.median &&
                    ` The middle one is ${ share(
                      reports.onboarding_readiness.median
                    ) } of the way through.` }
                </>
              ) }
            </p>
          </section>

          <section className="bwx-report" aria-labelledby="bwx-report-funnel">
            <h2 id="bwx-report-funnel" className="bwx-report-title">
              What clients asked for
            </h2>

            <table className="bwx-report-table" data-testid="bwx-report-funnel">
              <caption className="bwx-visually-hidden">Requests by what became of them</caption>
              <thead>
                <tr>
                  <th scope="col">State</th>
                  <th scope="col">Requests</th>
                </tr>
              </thead>
              <tbody>
                { Object.entries( reports.request_funnel.states ).map( ( [ state, count ] ) => (
                  <tr key={ state }>
                    <th scope="row">{ state }</th>
                    <td className="bwx-mono">{ count }</td>
                  </tr>
                ) ) }
              </tbody>
            </table>
          </section>

          <section className="bwx-report" aria-labelledby="bwx-report-email">
            <h2 id="bwx-report-email" className="bwx-report-title">
              Whether our email arrived
            </h2>

            <p data-testid="bwx-report-email">
              { null === reports.email_delivery.share ? (
                'Nothing was sent in this window, so there is nothing to report.'
              ) : (
                <>
                  <strong className="bwx-report-number">
                    { share( reports.email_delivery.share ) }
                  </strong>{ ' ' }
                  of { reports.email_delivery.total } arrived.
                  { 0 < reports.email_delivery.failed &&
                    ` ${ reports.email_delivery.failed } failed.` }
                </>
              ) }
            </p>
          </section>
        </div>
      ) }
    </>
  );
}
