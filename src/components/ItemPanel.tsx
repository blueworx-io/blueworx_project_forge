import { useEffect, useRef, useState } from 'react';
import type { Stage, WorkEvent, WorkItem } from '../types';
import { api, ApiError } from '../api';
import { phaseOf } from '../phases';

interface Detail {
  item: WorkItem;
  history: WorkEvent[];
  available: string[];
}

const EDITABLE = [
  { field: 'title', label: 'Title', lines: 1 },
  { field: 'problem', label: 'Problem it solves', lines: 3 },
  { field: 'scope', label: 'Scope', lines: 3 },
  { field: 'requirements', label: 'Requirements', lines: 3 },
  { field: 'acceptance_criteria', label: 'Done when', lines: 3 },
] as const;

function when( seconds: number ): string {
  return new Date( seconds * 1000 ).toLocaleString();
}

/**
 * One item, opened.
 *
 * This panel carries the moves as buttons as well as the fields. Drag is the
 * quick way to move work; this is the way that always works — with a keyboard,
 * on a phone, and when the next stage is off the side of a twelve-column board.
 */
export function ItemPanel( {
  itemId,
  stages,
  onClose,
  onChanged,
}: {
  itemId: string;
  stages: Stage[];
  onClose: () => void;
  onChanged: () => void;
} ) {
  const [ detail, setDetail ] = useState< Detail | null >( null );
  const [ draft, setDraft ] = useState< Record< string, string > >( {} );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );
  const closer = useRef< HTMLButtonElement >( null );

  const label = ( id: string ) => stages.find( ( stage ) => stage.id === id )?.label ?? id;

  async function load() {
    try {
      const loaded = await api< Detail >( `/work-items/${ itemId }` );
      setDetail( loaded );
      setDraft(
        Object.fromEntries(
          EDITABLE.map( ( { field } ) => [ field, String( loaded.item[ field ] ?? '' ) ] )
        )
      );
    } catch ( error ) {
      setNotice( error instanceof ApiError ? error.message : 'That item could not be loaded.' );
    }
  }

  useEffect( () => {
    void load();
    // Focus lands in the panel when it opens, so a keyboard user is not left
    // behind on the board underneath it.
    closer.current?.focus();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ itemId ] );

  async function move( to: string ) {
    if ( ! detail ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    try {
      await api( `/work-items/${ itemId }/transition`, {
        method: 'POST',
        body: { to, record_version: detail.item.record_version },
      } );
      await load();
      onChanged();
    } catch ( error ) {
      setNotice( error instanceof ApiError ? error.message : 'That move did not work.' );
    } finally {
      setBusy( false );
    }
  }

  async function save() {
    if ( ! detail ) {
      return;
    }

    setBusy( true );
    setNotice( '' );

    try {
      await api( `/work-items/${ itemId }`, {
        method: 'PATCH',
        body: { ...draft, record_version: detail.item.record_version },
      } );
      await load();
      onChanged();
      setNotice( 'Saved.' );
    } catch ( error ) {
      setNotice( error instanceof ApiError ? error.message : 'That change could not be saved.' );
    } finally {
      setBusy( false );
    }
  }

  return (
    <div
      className="bwx-panel-scrim"
      onClick={ ( event ) => {
        if ( event.target === event.currentTarget ) {
          onClose();
        }
      } }
    >
      <aside
        className="bwx-panel"
        role="dialog"
        aria-modal="true"
        aria-label="Work item"
        data-testid="bwx-panel"
        onKeyDown={ ( event ) => 'Escape' === event.key && onClose() }
      >
        <header className="bwx-panel-head">
          <div style={ { flex: 1 } }>
            <p className="bwx-eyebrow" data-testid="bwx-panel-stage">
              { detail ? label( detail.item.stage ) : 'Loading' }
            </p>
            <h2 style={ { margin: '4px 0 0', fontSize: 'var(--text-subheading)', fontWeight: 500 } }>
              { detail?.item.title ?? '' }
            </h2>
          </div>
          <button
            type="button"
            className="bwx-icon-button"
            ref={ closer }
            onClick={ onClose }
            aria-label="Close"
          >
            ✕
          </button>
        </header>

        { '' !== notice && (
          <p
            className="bwx-notice"
            data-tone={ 'Saved.' === notice ? 'quiet' : undefined }
            data-testid="bwx-panel-notice"
            role="status"
          >
            { notice }
          </p>
        ) }

        { detail && (
          <>
            <div>
              <p className="bwx-eyebrow">Move to</p>
              <div className="bwx-moves">
                { detail.available.map( ( to ) => (
                  <button
                    key={ to }
                    type="button"
                    className="bwx-button"
                    data-testid="bwx-move"
                    data-to={ to }
                    disabled={ busy }
                    style={
                      { borderColor: `var(--phase-${ phaseOf( to ) })` } as React.CSSProperties
                    }
                    onClick={ () => void move( to ) }
                  >
                    { label( to ) }
                  </button>
                ) ) }
                { 0 === detail.available.length && (
                  <p className="bwx-empty">This is the end of the road.</p>
                ) }
              </div>
            </div>

            { EDITABLE.map( ( { field, label: name, lines } ) => (
              <div className="bwx-field" key={ field }>
                <label htmlFor={ `bwx-${ field }` }>{ name }</label>
                { 1 === lines ? (
                  <input
                    id={ `bwx-${ field }` }
                    className="bwx-input"
                    value={ draft[ field ] ?? '' }
                    onChange={ ( event ) => setDraft( { ...draft, [ field ]: event.target.value } ) }
                  />
                ) : (
                  <textarea
                    id={ `bwx-${ field }` }
                    className="bwx-textarea"
                    value={ draft[ field ] ?? '' }
                    onChange={ ( event ) => setDraft( { ...draft, [ field ]: event.target.value } ) }
                  />
                ) }
              </div>
            ) ) }

            <div className="bwx-moves">
              <button
                type="button"
                className="bwx-button"
                data-testid="bwx-save"
                disabled={ busy }
                onClick={ () => void save() }
              >
                Save changes
              </button>
            </div>

            <div>
              <p className="bwx-eyebrow">History</p>
              <ul className="bwx-history" data-testid="bwx-history">
                { detail.history.map( ( event ) => (
                  <li key={ event.id }>
                    { 'created' === event.action
                      ? `Created in ${ label( event.to_stage ) }`
                      : `${ label( event.from_stage ) } → ${ label( event.to_stage ) }` }
                    <span className="bwx-mono"> { when( event.occurred_at ) }</span>
                  </li>
                ) ) }
              </ul>
            </div>
          </>
        ) }
      </aside>
    </div>
  );
}
