import { useCallback, useEffect, useState } from 'react';
import type { Requirement, Stage, StandupCard, StandupList } from '../types';
import { api, isDenied, messageFor } from '../api';
import { SECTIONS, cardDetail, cardTitle, keyOf, ruleTone, ruleWord } from '../standup';
import { GateList, ItemPanel } from './ItemPanel';
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
 *
 * **You can fix the thing from here (#171), and there is no second way of doing
 * it.** Opening a card opens the same panel the board opens; recording an
 * outstanding requirement draws the same list and posts to the same route as
 * that panel does. That is the whole design: Standup adds no route and no
 * permission check of its own, so an action somebody may not take is refused
 * here by exactly the code that refuses it everywhere else, in exactly the same
 * words. A second, friendlier path would be a second answer to "may I", and the
 * two would disagree the first time either of them changed.
 */
/** Marking one outstanding requirement done, from wherever it was named. */
type Complete = (
  itemId: string,
  requirement: Requirement,
  value: string,
  evidence: string
) => Promise< void >;

export function StandupScreen() {
  const [ list, setList ] = useState< StandupList | undefined >();
  const [ hidden, setHidden ] = useState< string[] >( [] );
  const [ notice, setNotice ] = useState( '' );
  const [ state, setState ] = useState< 'loading' | 'ready' | 'error' | 'denied' >( 'loading' );
  const [ stages, setStages ] = useState< Stage[] >( [] );
  const [ opened, setOpened ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

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

  /*
   * The stage list, for the panel this screen opens. Fetched on its own and
   * allowed to fail quietly: the day's list is worth reading whether or not a
   * panel can be opened over it, and losing the whole board because a
   * supporting call failed is the worse trade.
   */
  useEffect( () => {
    void api< { stages: Stage[] } >( '/stages' )
      // eslint-disable-next-line react-hooks/set-state-in-effect
      .then( ( answer ) => setStages( answer.stages ?? [] ) )
      .catch( () => undefined );
  }, [] );

  /**
   * Marks one outstanding requirement done, from the card that named it.
   *
   * The same route the panel uses, so the same capability decides it, and a
   * refusal is shown as it arrives rather than reworded. A kinder sentence here
   * would be this screen holding an opinion about permission, which is the one
   * thing it must not hold.
   */
  async function complete( itemId: string, requirement: Requirement, value: string, evidence: string ) {
    setBusy( true );
    setNotice( '' );

    try {
      await api( '/work-items/' + itemId + '/gate', {
        method: 'POST',
        body: { requirement: requirement.id, value, evidence },
      } );

      // Reloaded rather than crossed off here. Whether that was the last thing
      // holding the work up is the server's answer, not this screen's.
      await load();
      setNotice( 'Recorded.' );
    } catch ( failure ) {
      setNotice( messageFor( failure, 'That could not be recorded.' ) );
    } finally {
      setBusy( false );
    }
  }

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
        <p
          className="bwx-notice"
          role="status"
          data-testid="bwx-standup-notice"
          style={ { margin: '12px 20px 0' } }
        >
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
              busy={ busy }
              onDismiss={ dismiss }
              onOpen={ setOpened }
              onComplete={ complete }
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

      { '' !== opened && (
        <ItemPanel
          itemId={ opened }
          stages={ stages }
          onClose={ () => setOpened( '' ) }
          onChanged={ () => void load() }
        />
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
  busy,
  onDismiss,
  onOpen,
  onComplete,
  onShowAll,
}: {
  id: string;
  title: string;
  blurb: string;
  cards: StandupCard[];
  hidden: string[];
  busy: boolean;
  onDismiss: ( card: StandupCard ) => void;
  onOpen: ( itemId: string ) => void;
  onComplete: Complete;
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
          <Card
            key={ keyOf( card ) }
            card={ card }
            busy={ busy }
            onDismiss={ () => onDismiss( card ) }
            onOpen={ onOpen }
            onComplete={ onComplete }
          />
        ) ) }
      </ul>
    </section>
  );
}

/**
 * One thing needing attention, and what can be done about it from here (#171).
 *
 * Two actions, and each is only offered where it means something. A card about
 * a piece of work opens that work. A card about an outstanding requirement also
 * lists the requirements, because the shortest useful distance between finding
 * out something is missing and it not being missing any more is no distance at
 * all — and the alternative, going and finding the item on the board, is how a
 * list of things to do turns into a list of things to look at.
 *
 * Neither action is hidden from somebody who may not take it. Whether a person
 * may record a completion is a question with one answer and the server holds
 * it; this screen guessing would put a second answer next to it, and the two
 * would disagree the day either changed. So the control is drawn, the route
 * refuses whoever it refuses, and the refusal is shown in its own words.
 */
function Card( {
  card,
  busy,
  onDismiss,
  onOpen,
  onComplete,
}: {
  card: StandupCard;
  busy: boolean;
  onDismiss: () => void;
  onOpen: ( itemId: string ) => void;
  onComplete: Complete;
} ) {
  const detail = cardDetail( card );
  const isWork = 'work_item' === card.subject_type;
  const unmet = Array.isArray( card.detail?.unmet ) ? ( card.detail.unmet as Requirement[] ) : [];

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

      { /*
         The same list the panel draws, from the same component, so a
         requirement reads and behaves identically wherever somebody meets it.
         Field requirements come out as instructions rather than controls in
         there, which is what sends somebody to Open — exactly as it should.
       */ }
      { 0 < unmet.length && (
        <GateList
          heading="Outstanding"
          readiness={ { unmet, checks: [] } }
          records={ {} }
          busy={ busy }
          onComplete={ ( requirement, value, evidence ) =>
            onComplete( card.subject_id, requirement, value, evidence )
          }
        />
      ) }

      { isWork && (
        <div className="bwx-standup-card-actions">
          <button
            type="button"
            className="bwx-button"
            data-variant="quiet"
            data-testid="bwx-standup-open"
            aria-label={ `Open ${ cardTitle( card ) }` }
            onClick={ () => onOpen( card.subject_id ) }
          >
            Open
          </button>
        </div>
      ) }
    </li>
  );
}
