import { useEffect, useId, useRef, useState } from 'react';
import type { Person, SavedView, WorkFilters } from '../types';
import { alphabetical } from '../alphabetical.mjs';
import { everybody } from './ItemPanel';

/**
 * Who the work is narrowed to (#524): all staff, one person, or several.
 *
 * A person is on a task when they hold any seat on it, stand in for one, or
 * are assigned a recurring task — the server decides that, this only says
 * who. Only the studio's own people are offered. It looks like the "Any kind"
 * picker beside it (#614), though it is a button, as a select cannot hold several.
 */
function StaffFilter( { picked, onChange }: { picked: string[]; onChange: ( people: string[] ) => void } ) {
  const [ people, setPeople ] = useState< Person[] >( [] );
  const [ open, setOpen ] = useState( false );
  const wrap = useRef< HTMLSpanElement >( null );
  const menuId = useId();

  useEffect( () => {
    let live = true;

    void everybody().then( ( roster ) => {
      if ( live ) setPeople( alphabetical( roster, 'display_name' ) );
    } );

    return () => {
      live = false;
    };
  }, [] );

  useEffect( () => {
    if ( ! open ) return;
    const away = ( event: MouseEvent ) => {
      if ( wrap.current && ! wrap.current.contains( event.target as Node ) ) setOpen( false );
    };
    const key = ( event: KeyboardEvent ) => {
      if ( 'Escape' === event.key ) setOpen( false );
    };
    document.addEventListener( 'mousedown', away );
    document.addEventListener( 'keydown', key );
    return () => {
      document.removeEventListener( 'mousedown', away );
      document.removeEventListener( 'keydown', key );
    };
  }, [ open ] );

  const names = new Map( people.map( ( person ) => [ person.id, person.display_name ] ) );
  const chosen = picked.filter( ( id ) => names.has( id ) );
  const value = 0 === picked.length ? 'All staff' : 1 === chosen.length ? names.get( chosen[ 0 ] ) ?? '1 person' : `${ picked.length } people`;

  const toggle = ( id: string ) => {
    const next = new Set( picked );

    if ( next.has( id ) ) {
      next.delete( id );
    } else {
      next.add( id );
    }

    onChange( [ ...next ] );
  };

  return (
    <span className="fk-filter" ref={ wrap }>
      <button
        type="button"
        className="bwx-select bwx-staff-pick"
        data-testid="bwx-filter-staff"
        aria-label={ `Staff: ${ value }` }
        aria-haspopup="listbox"
        aria-expanded={ open }
        aria-controls={ menuId }
        onClick={ () => setOpen( ! open ) }
      >
        { value }
      </button>
      { open && (
        <ul className="fk-menu bwx-staff-menu" id={ menuId } role="listbox" aria-label="Staff" aria-multiselectable="true" data-testid="bwx-filter-staff-menu">
          <li role="none">
            <button
              type="button"
              role="option"
              className="fk-menu-item"
              data-testid="bwx-filter-staff-all"
              aria-checked={ 0 === picked.length }
              aria-selected={ 0 === picked.length }
              onClick={ () => {
                onChange( [] );
                setOpen( false );
              } }
            >
              All staff
            </button>
          </li>
          { people.map( ( person ) => (
            <li key={ person.id } role="none">
              <button
                type="button"
                role="option"
                className="fk-menu-item"
                data-testid={ `bwx-filter-staff-${ person.id }` }
                aria-checked={ picked.includes( person.id ) }
                aria-selected={ picked.includes( person.id ) }
                onClick={ () => toggle( person.id ) }
              >
                { person.display_name }
              </button>
            </li>
          ) ) }
        </ul>
      ) }
    </span>
  );
}

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
        <option value="bug">Bugs</option>
        <option value="feature">Features</option>
        <option value="feedback">Feedback</option>
        <option value="task">Tasks</option>
      </select>

      <StaffFilter
        picked={ filters.person ?? [] }
        onChange={ ( people ) => {
          const next = { ...filters };

          if ( 0 === people.length ) {
            delete next.person;
          } else {
            next.person = people;
          }

          onChange( next );
        } }
      />

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
          { alphabetical( views, 'name' ).map( ( view ) => (
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
