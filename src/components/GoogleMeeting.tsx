import { useId, useMemo, useState } from 'react';
import { api, messageFor } from '../api';
import { Aside, Button, Select } from '../kit';
import { useClientChoice } from '../ClientChoice';
import { siteLabel } from '../sites';
import type { DiaryEntry } from '../types';

/**
 * #606. Settling a meeting that came from Google Calendar: which client it
 * was for — a client with one site is that site; "No client" is the studio's
 * own time — and whether its time comes off that client's support hours.
 * The same line can say what became of it, as a standing meeting's does.
 *
 * Its day and time are Google's, and are never edited here.
 */

/** What a meeting can be marked as, as on the standup. */
const SETTLE_AS = [
  { status: 'held', label: 'Held' },
  { status: 'cancelled', label: 'Cancelled' },
  { status: 'no-show', label: 'Nobody came' },
];

/** The value the picker holds before anything is chosen. */
const UNPICKED = '-';

type Settling = Pick< DiaryEntry, 'title' | 'site_id' > & { google_id?: string; off_support?: boolean };

export function GoogleSettle( {
  entry,
  statuses,
  busy,
  onSettle,
}: {
  entry: Settling;
  /** Offer held / cancelled / nobody came, not just saving the client. */
  statuses: boolean;
  busy: boolean;
  onSettle: ( body: { client_site_id: string; off_support: boolean; status?: string } ) => void;
} ) {
  const { sites } = useClientChoice();
  const studio = sites.find( ( one ) => one.studio );
  const [ site, setSite ] = useState( '' === ( entry.site_id ?? '' ) ? UNPICKED : studio?.id === entry.site_id ? '' : entry.site_id );
  const [ offSupport, setOffSupport ] = useState( entry.off_support ?? false );
  const supportId = useId();

  const options = useMemo(
    () => [
      { value: UNPICKED, label: 'Pick a client…' },
      { value: '', label: 'No client (studio time)' },
      ...sites
        .filter( ( one ) => ! one.studio )
        .map( ( one ) => ( { value: one.id, label: siteLabel( one, sites ) } ) )
        .sort( ( a, b ) => a.label.localeCompare( b.label ) ),
    ],
    [ sites ]
  );

  const picked = UNPICKED !== site;
  const body = ( status?: string ) => ( { client_site_id: site, off_support: '' !== site && offSupport, ...( status ? { status } : {} ) } );

  return (
    <span className="bwx-google-settle" data-testid="bwx-google-settle">
      <Select
        aria-label={ `Client for ${ entry.title }` }
        data-testid="bwx-google-settle-client"
        options={ options }
        value={ site }
        disabled={ busy }
        onChange={ ( event ) => setSite( event.target.value ) }
      />
      <label className="bwx-google-settle-support" htmlFor={ supportId }>
        <input
          id={ supportId }
          type="checkbox"
          data-testid="bwx-google-settle-support"
          checked={ '' !== site && UNPICKED !== site && offSupport }
          disabled={ busy || ! picked || '' === site }
          onChange={ ( event ) => setOffSupport( event.target.checked ) }
        />
        <span>Comes off support hours</span>
      </label>
      { statuses
        ? SETTLE_AS.map( ( one ) => (
          <button
            key={ one.status }
            type="button"
            className="bwx-button"
            data-size="sm"
            data-testid={ `bwx-google-settle-${ one.status }` }
            disabled={ busy || ! picked }
            onClick={ () => onSettle( body( one.status ) ) }
          >
            { one.label }
          </button>
        ) )
        : (
          <Button size="sm" data-testid="bwx-google-settle-save" disabled={ busy || ! picked } onClick={ () => onSettle( body() ) }>
            Save
          </Button>
        ) }
    </span>
  );
}

/**
 * Picking a synced meeting's client ahead of the day, from the calendar
 * (#606). Its hours are then held as a standing meeting's are.
 */
export function GoogleMeetingPanel( { entry, onClose, onSaved }: { entry: DiaryEntry; onClose: () => void; onSaved: () => void } ) {
  const [ busy, setBusy ] = useState( false );
  const [ notice, setNotice ] = useState( '' );
  const today = new Date().toISOString().slice( 0, 10 );

  async function settle( body: { client_site_id: string; off_support: boolean; status?: string } ) {
    setBusy( true );
    setNotice( '' );

    try {
      await api( `/google-meetings/${ entry.google_id }/settle`, { method: 'POST', body } );
      onSaved();
      onClose();
    } catch ( failure ) {
      setNotice( messageFor( failure, 'That meeting could not be settled.' ) );
    } finally {
      setBusy( false );
    }
  }

  return (
    <Aside label={ entry.title } testId="bwx-google-meeting" onClose={ onClose }>
      <p className="bwx-hint">{ `${ entry.date } · ${ entry.detail }` }</p>
      <p className="bwx-hint">This meeting comes from Google Calendar, so its time is changed there.</p>
      { entry.can_settle ? (
        <div className="bwx-google-meeting-settle">
          <p className="bwx-eyebrow">Who it was for</p>
          <GoogleSettle entry={ entry } statuses={ entry.date <= today } busy={ busy } onSettle={ ( body ) => void settle( body ) } />
          <p className="bwx-hint">Picking a client ahead holds their hours, as a standing meeting does. They are only used once it is marked held.</p>
        </div>
      ) : (
        <p className="bwx-hint">Only an administrator or somebody on this meeting can settle it.</p>
      ) }
      { '' !== notice && (
        <p className="bwx-notice" role="alert" data-testid="bwx-google-meeting-notice">
          { notice }
        </p>
      ) }
    </Aside>
  );
}

/** Whether a diary entry came from Google Calendar. */
export function fromGoogle( entry: DiaryEntry ): boolean {
  return 'google' === entry.source && '' !== ( entry.google_id ?? '' );
}
