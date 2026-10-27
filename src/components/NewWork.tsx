import { useState } from 'react';
import { api, ApiError } from '../api';
import { siteLabel, type SiteOption } from '../sites';
import { Aside, RichText } from '../kit';

const LEVELS = [
  { id: 'project', label: 'Project' },
  { id: 'milestone', label: 'Release' },
  { id: 'feature', label: 'Feature' },
  { id: 'sub-feature', label: 'Sub-feature' },
];

const TYPES = [
  // #562. Only ever preset, for projects, releases and features: the free
  // choice below stays bugs, feedback and tasks (2026-09-18).
  { id: 'feature', label: 'Feature' },
  { id: 'bug', label: 'Bug' },
  { id: 'feedback', label: 'Feedback' },
  { id: 'task', label: 'Task' },
];

/** What the free choice of type offers. */
const CHOOSABLE = TYPES.filter( ( option ) => 'feature' !== option.id );

/**
 * #562. Work added from inside something: its level and, when given, its type
 * are fixed, and it is put under its parent. `reviewOf` names the release
 * whose review raised it (#564).
 */
export interface NewWorkPreset {
  level?: string;
  parentId?: string;
  workType?: string;
  reviewOf?: string;
}

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
  sites = [],
  onClose,
  onCreated,
  preset,
}: {
  clientSiteId: string;
  /** Every site there is, so the form can ask which client this is for. */
  sites?: SiteOption[];
  onClose: () => void;
  onCreated: () => void;
  preset?: NewWorkPreset;
} ) {
  // The board's site when it is on one; otherwise the studio's own, then whatever is first.
  const [ siteId, setSiteId ] = useState( () =>
    sites.some( ( one ) => one.id === clientSiteId ) ? clientSiteId : sites.find( ( one ) => one.studio )?.id ?? sites[ 0 ]?.id ?? clientSiteId
  );
  const [ title, setTitle ] = useState( '' );
  const [ problem, setProblem ] = useState( '' );
  const [ level, setLevel ] = useState( preset?.level ?? 'feature' );
  const [ workType, setWorkType ] = useState( preset?.workType ?? 'task' );
  // #665. A project or release always holds work, so it is always a Feature.
  const container = [ 'project', 'milestone' ].includes( level );
  const type = container ? 'feature' : workType;
  // A release has dates of its own (#562); its weeks are read from them.
  const dated = 'milestone' === preset?.level;
  const [ start, setStart ] = useState( '' );
  const [ due, setDue ] = useState( '' );
  const fixed = ( list: Array< { id: string; label: string } >, id: string ) => list.find( ( option ) => option.id === id )?.label ?? id;
  const [ notice, setNotice ] = useState( '' );
  const [ busy, setBusy ] = useState( false );

  async function create() {
    setBusy( true );
    setNotice( '' );

    try {
      await api( '/work-items', {
        method: 'POST',
        body: {
          client_site_id: siteId,
          title,
          problem,
          level,
          work_type: type,
          ...( preset?.parentId ? { parent_id: preset.parentId } : {} ),
          ...( preset?.reviewOf ? { review_of: preset.reviewOf } : {} ),
          ...( dated && '' !== start ? { planned_start: start } : {} ),
          ...( dated && '' !== due ? { planned_due: due } : {} ),
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
    <Aside
      label="Add work"
      testId="bwx-new-work"
      width={ 520 }
      onClose={ onClose }
      footer={
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
      }
    >

        { '' !== notice && (
          <p className="bwx-notice" data-testid="bwx-new-notice" role="status">
            { notice }
          </p>
        ) }

        { 0 < sites.length && (
          <div className="bwx-field">
            <label htmlFor="bwx-new-site">Client</label>
            <select
              id="bwx-new-site"
              className="bwx-select"
              data-testid="bwx-new-site"
              value={ siteId }
              onChange={ ( event ) => setSiteId( event.target.value ) }
            >
              { sites.map( ( option ) => (
                <option key={ option.id } value={ option.id }>
                  { siteLabel( option, sites ) }
                </option>
              ) ) }
            </select>
          </div>
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
          <label htmlFor="bwx-new-problem">Item description</label>
          <RichText id="bwx-new-problem" testId="bwx-new-problem" label="Item description" value={ problem } onChange={ setProblem } />
        </div>

        { dated && (
          <div className="bwx-pair">
            <div className="bwx-field">
              <label htmlFor="bwx-new-start">Starts</label>
              <input id="bwx-new-start" type="date" className="bwx-input" data-testid="bwx-new-start" value={ start } onChange={ ( event ) => setStart( event.target.value ) } />
            </div>
            <div className="bwx-field">
              <label htmlFor="bwx-new-due">Ends</label>
              <input id="bwx-new-due" type="date" className="bwx-input" data-testid="bwx-new-due" value={ due } min={ start || undefined } onChange={ ( event ) => setDue( event.target.value ) } />
            </div>
          </div>
        ) }

        { preset?.level ? (
          <div className="bwx-field">
            <label htmlFor="bwx-new-level">Level</label>
            <input id="bwx-new-level" className="bwx-input" readOnly data-testid="bwx-new-level-fixed" value={ fixed( LEVELS, level ) } />
          </div>
        ) : (
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
        ) }

        { preset?.workType || container ? (
          <div className="bwx-field">
            <label htmlFor="bwx-new-type">Type</label>
            <input id="bwx-new-type" className="bwx-input" readOnly data-testid="bwx-new-type-fixed" value={ fixed( TYPES, type ) } />
          </div>
        ) : (
        <div className="bwx-field">
          <label htmlFor="bwx-new-type">Type</label>
          <select
            id="bwx-new-type"
            className="bwx-select"
            data-testid="bwx-new-type"
            value={ workType }
            onChange={ ( event ) => setWorkType( event.target.value ) }
          >
            { CHOOSABLE.map( ( option ) => (
              <option key={ option.id } value={ option.id }>
                { option.label }
              </option>
            ) ) }
          </select>
        </div>
        ) }

    </Aside>
  );
}
