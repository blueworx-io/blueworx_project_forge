import { useCallback, useEffect, useState } from 'react';
import type { StandupCard, StandupList } from '../types';
import { api, isDenied, messageFor } from '../api';
import { SECTIONS, cardDetail, cardTitle, keyOf, ruleTone, ruleWord } from '../standup';
import { Screen } from './States';

/**
 * The working surface for the day (#170).
 *
 * Four sections, in the order somebody reads them: the work that needs doing
 * something about, the things sitting on a person, what a client is waiting on
 * us for, and the studio's own problems. The last is smallest and most easily
 * missed, which is why it is a section rather than the end of a long list.
 *
 * **Dismissing a card cannot hide unresolved work, and this screen is where
 * that promise is kept.** Three things make it true, and all three matter:
 *
 * - A dismissal lives in this browser tab and nowhere else. There is no route
 *   to record one, and no field on any record to write it to — see #169. Reload
 *   the page and every card is back, because the list is worked out from the
 *   records rather than from a memory of what somebody has looked at.
 * - The count in each section header is the real one, always. A section reading
 *   "3 things · 1 hidden" cannot mislead anybody about how much there is.
 * - Nothing is dismissed silently. A section with hidden cards says so and
 *   offers them back.
 *
 * The alternative — remembering dismissals on the server — is the thing that
 * eventually hides a real problem, because a dismissal outlives the reason
 * somebody made it. Somebody clears a card on Monday knowing they will do it
 * that afternoon, does not, and the board never mentions it again.
 */
export function StandupScreen() {
  const [ list, setList ] = useState< StandupList | undefined >();
  const [ hidden, setHidden ] = useState< string[] >( [] );
  const [ notice, setNotice ] = useState( '' );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'error' | 'denied' >( 'loading' );

  const load = useCallback( async () => {
    try {
      const answer = await api< StandupList >( '/standup' );

      if ( answer.denied ) {
        setState( 'denied' );
        return;
      }

      setList( answer );
      setState( 'ready' );
    } catch ( failure ) {
      setNotice( messageFor( failure, 'The day’s list could not be read.' ) );
      setState( isDenied( failure ) ? 'denied' : 'error' );
    }
  }, [] );

  useEffect( () => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [ load ] );

  const cards = list?.cards ?? [];

  function dismiss( card: StandupCard ) {
    setHidden( [ ...hidden, keyOf( card ) ] );
  }

  return (
    <>
      <header className="bwx-header" data-testid="bwx-standup-header">
        <span className="bwx-eyebrow">Today</span>

        <span className="bwx-mono" data-testid="bwx-standup-day">
          { list?.today ?? '' }
        </span>

        <span className="bwx-header-spacer" />

        { 0 < hidden.length && (
          <button
            type="button"
            className="bwx-button"
            data-variant="quiet"
            data-testid="bwx-standup-show-all"
            onClick={ () => setHidden( [] ) }
          >
            Show { hidden.length } hidden
          </button>
        ) }

        <button
          type="button"
          className="bwx-button"
          data-variant="quiet"
          data-testid="bwx-standup-refresh"
          onClick={ () => {
            setState( 'loading' );
            void load();
          } }
        >
          Refresh
        </button>

        <span className="bwx-mono" data-testid="bwx-standup-count">
          { cards.length } { 1 === cards.length ? 'thing' : 'things' }
        </span>
      </header>

      { '' !== notice && 'error' !== state && 'denied' !== state && (
        <p className="bwx-notice" role="status" style={ { margin: '12px 20px 0' } }>
          { notice }
        </p>
      ) }

      { 'loading' === state && <Screen state="loading" detail="Working out what needs attention." /> }

      { 'denied' === state && (
        <Screen
          state="denied"
          testId="bwx-standup-state-screen"
          detail="You are signed in, but not on any client whose work you may read. Ask for a membership, or for cross-client access."
        />
      ) }

      { 'error' === state && (
        <Screen
          state="error"
          testId="bwx-standup-state-screen"
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

      { 'ready' === state && 0 === cards.length && (
        <Screen
          state="empty"
          testId="bwx-standup-state-screen"
          title="Nothing needs attention"
          detail="Nothing is late, blocked, or waiting on anybody. This is worked out fresh each time, so it is genuinely clear rather than cleared."
        />
      ) }

      { 'ready' === state && 0 < cards.length && (
        <div className="bwx-standup" data-testid="bwx-standup">
          { SECTIONS.map( ( section ) => (
            <Section
              key={ section.id }
              id={ section.id }
              title={ section.title }
              blurb={ section.blurb }
              cards={ cards.filter( ( card ) => section.rules.includes( card.rule as never ) ) }
              hidden={ hidden }
              onDismiss={ dismiss }
              onShowAll={ () =>
                setHidden(
                  hidden.filter(
                    ( key ) => ! section.rules.includes( key.split( ':' )[ 0 ] as never )
                  )
                )
              }
            />
          ) ) }
        </div>
      ) }
    </>
  );
}

/** One section: everything true of one kind, and how much of it is hidden. */
function Section( {
  id,
  title,
  blurb,
  cards,
  hidden,
  onDismiss,
  onShowAll,
}: {
  id: string;
  title: string;
  blurb: string;
  cards: StandupCard[];
  hidden: string[];
  onDismiss: ( card: StandupCard ) => void;
  onShowAll: () => void;
} ) {
  if ( 0 === cards.length ) {
    return null;
  }

  const shown = cards.filter( ( card ) => ! hidden.includes( keyOf( card ) ) );
  const away = cards.length - shown.length;

  return (
    <section className="bwx-standup-section" data-testid="bwx-standup-section" data-section={ id }>
      <div className="bwx-standup-section-head">
        <h2 className="bwx-standup-section-title">{ title }</h2>

        { /*
           The real count, always. This is the sentence that makes dismissing a
           card safe: whatever anybody has tidied away, the number here is how
           much there actually is.
         */ }
        <span className="bwx-mono" data-testid="bwx-standup-section-count">
          { cards.length } { 1 === cards.length ? 'thing' : 'things' }
          { 0 < away ? ` · ${ away } hidden` : '' }
        </span>

        { 0 < away && (
          <button
            type="button"
            className="bwx-button"
            data-variant="quiet"
            data-testid="bwx-standup-section-show"
            onClick={ onShowAll }
          >
            Show
          </button>
        ) }
      </div>

      <p className="bwx-standup-blurb">{ blurb }</p>

      <ul className="bwx-standup-cards">
        { shown.map( ( card ) => (
          <Card key={ keyOf( card ) } card={ card } onDismiss={ () => onDismiss( card ) } />
        ) ) }
      </ul>
    </section>
  );
}

/** One thing needing attention. */
function Card( { card, onDismiss }: { card: StandupCard; onDismiss: () => void } ) {
  const detail = cardDetail( card );

  return (
    <li
      className="bwx-standup-card"
      data-testid="bwx-standup-card"
      data-rule={ card.rule }
      data-subject={ card.subject_id }
      data-tone={ ruleTone( card.rule ) }
    >
      <div className="bwx-standup-card-head">
        <span className="bwx-chip" data-rule={ card.rule }>
          { ruleWord( card.rule ) }
        </span>

        <span className="bwx-header-spacer" />

        <button
          type="button"
          className="bwx-standup-dismiss"
          data-testid="bwx-standup-dismiss"
          aria-label={ `Hide ${ cardTitle( card ) } until this page is reloaded` }
          onClick={ onDismiss }
        >
          Hide
        </button>
      </div>

      <strong className="bwx-standup-card-title">{ cardTitle( card ) }</strong>

      { '' !== detail && <span className="bwx-standup-card-detail">{ detail }</span> }
    </li>
  );
}
