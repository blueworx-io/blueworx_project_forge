import type { SavedView, WorkFilters } from '../types';

/**
 * The filter bar every view sits under (#123).
 *
 * One bar, above the views rather than inside either of them. That is the whole
 * point of the issue: a filter that belongs to a view is a filter that has to
 * be set again when somebody switches, and two filters that drift apart are how
 * two views come to show different totals.
 *
 * Deliberately small. The full filter set is wide — every field on a work item
 * can be filtered through the API — and putting all of it on screen would make
 * the bar the tallest thing on the page. What is here is what somebody reaches
 * for daily: what kind of work, what stage, and a search box. Everything else
 * arrives through a saved view, which is what saved views are for.
 */
export function Filters( {
  filters,
  views,
  onChange,
  onSave,
  onOpenView,
  onRemoveView,
}: {
  filters: WorkFilters;
  views: SavedView[];
  onChange: ( filters: WorkFilters ) => void;
  onSave: ( name: string ) => void;
  onOpenView: ( view: SavedView ) => void;
  onRemoveView: ( view: SavedView ) => void;
} ) {
  const only = ( key: 'work_type' | 'stage' ) => ( filters[ key ] ?? [] )[ 0 ] ?? '';

  /** Sets one filter, or clears it when the empty option is chosen. */
  const set = ( key: 'work_type' | 'stage', value: string ) => {
    const next = { ...filters };

    if ( '' === value ) {
      delete next[ key ];
    } else {
      next[ key ] = [ value ];
    }

    onChange( next );
  };

  return (
    <div className="bwx-filters" data-testid="bwx-filters">
      <input
        type="search"
        className="bwx-input"
        data-testid="bwx-search"
        aria-label="Search work"
        placeholder="Search titles and problems"
        value={ filters.search ?? '' }
        onChange={ ( event ) => {
          const next = { ...filters };

          if ( '' === event.target.value ) {
            delete next.search;
          } else {
            next.search = event.target.value;
          }

          onChange( next );
        } }
      />

      <select
        className="bwx-select"
        data-testid="bwx-filter-type"
        aria-label="Work type"
        value={ only( 'work_type' ) }
        onChange={ ( event ) => set( 'work_type', event.target.value ) }
      >
        <option value="">Any kind</option>
        <option value="feature">Features</option>
        <option value="bug">Bugs</option>
        <option value="feedback">Feedback</option>
        <option value="task">Tasks</option>
      </select>

      <span className="bwx-header-spacer" />

      { 0 < views.length && (
        <select
          className="bwx-select"
          data-testid="bwx-saved-views"
          aria-label="Saved views"
          value=""
          onChange={ ( event ) => {
            const chosen = views.find( ( view ) => view.id === event.target.value );

            if ( chosen ) {
              onOpenView( chosen );
            }
          } }
        >
          <option value="">Saved views…</option>
          { views.map( ( view ) => (
            <option key={ view.id } value={ view.id }>
              { view.name }
            </option>
          ) ) }
        </select>
      ) }

      <button
        type="button"
        className="bwx-button"
        data-variant="quiet"
        data-testid="bwx-save-view"
        onClick={ () => {
          // A prompt rather than a dialog of its own: naming a view is one
          // short answer, and a modal for it would be more chrome than the
          // thing it names.
          const name = window.prompt( 'Name this view' );

          if ( null !== name && '' !== name.trim() ) {
            onSave( name.trim() );
          }
        } }
      >
        Save this view
      </button>

      { 0 < views.length && (
        <button
          type="button"
          className="bwx-button"
          data-variant="quiet"
          data-testid="bwx-remove-view"
          onClick={ () => {
            const name = window.prompt( 'Which saved view should go?' );
            const chosen = views.find( ( view ) => view.name === name?.trim() );

            if ( chosen ) {
              onRemoveView( chosen );
            }
          } }
        >
          Remove a view
        </button>
      ) }
    </div>
  );
}
