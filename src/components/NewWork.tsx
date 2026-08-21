import { useState } from 'react';
import { api, ApiError } from '../api';

const LEVELS = [
  { id: 'project', label: 'Project' },
  { id: 'milestone', label: 'Milestone' },
  { id: 'feature', label: 'Feature' },
  { id: 'sub-feature', label: 'Sub-feature' },
];

const TYPES = [
  { id: 'feature', label: 'Feature' },
  { id: 'bug', label: 'Bug' },
  { id: 'feedback', label: 'Feedback' },
  { id: 'task', label: 'Task' },
];

/**
 * Adding work.
 *
 * Four fields, because everything else has a stage it becomes required at and
 * asking for it now would be asking before anybody knows. New work always
 * starts in Future Idea — that is the state machine's rule, not this form's, so
 * there is nothing here to choose.
 */
export function NewWork( {
  clientSiteId,
  onClose,
  onCreated,
}: {
  clientSiteId: string;
  onClose: () => void;
  onCreated: () => void;
} ) {
  const [ title, setTitle ] = useState( '' );
  const [ problem, setProblem ] = useState( '' );
  const [ level, setLevel ] = useState( 'feature' );
  const [ workType, setWorkType ] = useState( 'feature' );
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function create() {
    setBusy( true );
    setNotice( '' );

    try {
      await api( '/work-items', {
        method: 'POST',
        body: {
          client_site_id: clientSiteId,
          title,
          problem,
          level,
          work_type: workType,
        },
      } );
      onCreated();
      onClose();
    } catch ( error ) {
      setNotice( error instanceof ApiError ? error.message : 'That work could not be added.' );
    } finally {
      setBusy( false );
    }
  }

  return (
    <div
      className="bwx-panel-scrim"
      onClick={ ( event ) => event.target === event.currentTarget && onClose() }
    >
      <aside
        className="bwx-panel"
        role="dialog"
        aria-modal="true"
        aria-label="Add work"
        data-testid="bwx-new-work"
        onKeyDown={ ( event ) => 'Escape' === event.key && onClose() }
      >
        <header className="bwx-panel-head">
          <h2 style={ { flex: 1, margin: 0, fontSize: 'var(--text-subheading)', fontWeight: 500 } }>
            Add work
          </h2>
          <button type="button" className="bwx-icon-button" onClick={ onClose } aria-label="Close">
            ✕
          </button>
        </header>

        { '' !== notice && (
          <p className="bwx-notice" data-testid="bwx-new-notice" role="status">
            { notice }
          </p>
        ) }

        <div className="bwx-field">
          <label htmlFor="bwx-new-title">Title</label>
          <input
            id="bwx-new-title"
            className="bwx-input"
            autoFocus
            value={ title }
            onChange={ ( event ) => setTitle( event.target.value ) }
          />
        </div>

        <div className="bwx-field">
          <label htmlFor="bwx-new-problem">Problem it solves</label>
          <textarea
            id="bwx-new-problem"
            className="bwx-textarea"
            value={ problem }
            onChange={ ( event ) => setProblem( event.target.value ) }
          />
        </div>

        <div className="bwx-field">
          <label htmlFor="bwx-new-level">Level</label>
          <select
            id="bwx-new-level"
            className="bwx-select"
            value={ level }
            onChange={ ( event ) => setLevel( event.target.value ) }
          >
            { LEVELS.map( ( option ) => (
              <option key={ option.id } value={ option.id }>
                { option.label }
              </option>
            ) ) }
          </select>
        </div>

        <div className="bwx-field">
          <label htmlFor="bwx-new-type">Type</label>
          <select
            id="bwx-new-type"
            className="bwx-select"
            value={ workType }
            onChange={ ( event ) => setWorkType( event.target.value ) }
          >
            { TYPES.map( ( option ) => (
              <option key={ option.id } value={ option.id }>
                { option.label }
              </option>
            ) ) }
          </select>
        </div>

        <div className="bwx-moves">
          <button
            type="button"
            className="bwx-button"
            data-testid="bwx-create"
            disabled={ busy || '' === title.trim() }
            onClick={ () => void create() }
          >
            Add to Future Ideas
          </button>
          <button type="button" className="bwx-button" data-variant="quiet" onClick={ onClose }>
            Cancel
          </button>
        </div>
      </aside>
    </div>
  );
}
