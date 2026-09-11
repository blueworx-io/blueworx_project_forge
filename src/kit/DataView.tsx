import { useEffect, useId, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Check as CheckGlyph, ChevronDown, Search } from 'lucide-react';

/*
 * The DataView (#296): every list surface in both interfaces. Saved views
 * with live counts, a search field, value-bearing filter pills, a sortable
 * table, and a footer that states the result. Row selection and the bulk bar
 * exist for the studio; the client never gets them, in the same way a client
 * cannot move work between stages.
 *
 * Every piece of chrome renders only when a screen passes the matching prop,
 * so a bare <DataView columns rows /> inside a Panel still works.
 */

export interface Column< Row > {
  key: string;
  label?: string;
  render?: ( row: Row ) => ReactNode;
  /** What to sort on, when the cell shows something other than a plain value. */
  sortBy?: ( row: Row ) => string | number | null | undefined;
  sortable?: boolean;
  align?: 'left' | 'right' | 'center';
  mono?: boolean;
  wrap?: boolean;
  width?: number | string;
  clamp?: number;
}

export interface SavedView {
  id: string;
  label: string;
  count?: number;
}

export interface Filter {
  id: string;
  label: string;
  value?: string | null;
  options: string[];
}

export interface Sort {
  key: string;
  dir: 'asc' | 'desc';
}

type RowRecord = { id?: string | number } & Record< string, unknown >;

function sortValue< Row extends RowRecord >( row: Row, column: Column< Row > ): string | number {
  const raw = column.sortBy ? column.sortBy( row ) : row[ column.key ];
  if ( 'number' === typeof raw ) return raw;
  const text = null == raw ? '' : String( raw );
  if ( column.mono || 'right' === column.align ) {
    const n = parseFloat( text.replace( /[^0-9.-]/g, '' ) );
    if ( ! isNaN( n ) ) return n;
  }
  return text.toLowerCase();
}

/** An 18px brand checkbox that reports its state to assistive tech. */
export function Check( {
  checked,
  indeterminate,
  label,
  onChange,
}: {
  checked: boolean;
  indeterminate?: boolean;
  label: string;
  onChange: () => void;
} ) {
  return (
    <button
      type="button"
      role="checkbox"
      className="fk-check"
      aria-checked={ indeterminate ? 'mixed' : checked }
      aria-label={ label }
      onClick={ ( event ) => {
        event.stopPropagation();
        onChange();
      } }
    >
      { indeterminate ? (
        <span className="fk-check-bar" aria-hidden="true" />
      ) : checked ? (
        <CheckGlyph size={ 11 } strokeWidth={ 3.5 } aria-hidden="true" />
      ) : null }
    </button>
  );
}

/** A saved view: a label and its live count. The count is what makes it not a tab. */
export function ViewPill( {
  label,
  count,
  active,
  onClick,
  dashed,
}: {
  label: string;
  count?: number;
  active?: boolean;
  onClick?: () => void;
  dashed?: boolean;
} ) {
  return (
    <button type="button" className="fk-pill" aria-pressed={ !! active } data-dashed={ dashed ? 'true' : undefined } onClick={ onClick }>
      { label }
      { null != count && <span className="fk-pill-count">{ count }</span> }
    </button>
  );
}

/** A filter that shows its value: `Client Northgate`, tinted while set. */
export function FilterPill( { filter, onPick }: { filter: Filter; onPick: ( id: string, value: string | null ) => void } ) {
  const [ open, setOpen ] = useState( false );
  const wrap = useRef< HTMLSpanElement >( null );
  const menuId = useId();
  const set = !! filter.value;

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

  return (
    <span className="fk-filter" ref={ wrap }>
      <button
        type="button"
        className="fk-filter-btn"
        data-set={ set ? 'true' : undefined }
        aria-haspopup="listbox"
        aria-expanded={ open }
        aria-controls={ menuId }
        onClick={ () => setOpen( ! open ) }
      >
        <span className="fk-filter-label">{ filter.label }</span>
        { set && <span className="fk-filter-value">{ filter.value }</span> }
        <ChevronDown size={ 12 } strokeWidth={ 2 } aria-hidden="true" />
      </button>
      { open && (
        <ul className="fk-menu" id={ menuId } role="listbox" aria-label={ filter.label }>
          { filter.options.map( ( option ) => {
            const picked = ( filter.value ?? filter.options[ 0 ] ) === option;
            return (
              <li key={ option } role="none">
                <button
                  type="button"
                  role="option"
                  className="fk-menu-item"
                  aria-checked={ picked }
                  aria-selected={ picked }
                  onClick={ () => {
                    onPick( filter.id, option === filter.options[ 0 ] ? null : option );
                    setOpen( false );
                  } }
                >
                  { option }
                </button>
              </li>
            );
          } ) }
        </ul>
      ) }
    </span>
  );
}

export function BulkButton( { children, onClick }: { children: ReactNode; onClick?: () => void } ) {
  return (
    <button type="button" className="fk-bulk-btn" onClick={ onClick }>
      { children }
    </button>
  );
}

export function DataView< Row extends RowRecord >( {
  columns,
  rows,
  empty = 'Nothing to show',
  sortable = true,
  defaultSort,
  maxHeight,
  selectedId,
  onRowClick,
  footer,
  fixed,
  dense = true,
  bare = false,
  title,
  titleRight,
  views,
  activeView,
  onViewChange,
  onSaveView,
  search,
  searchPlaceholder = 'Search',
  onSearch,
  filters,
  onFilter,
  onClearFilters,
  toolbar,
  selectable,
  selection = [],
  onSelectionChange,
  bulkNoun = 'item',
  bulkActions,
  testId,
}: {
  columns: Column< Row >[];
  rows: Row[];
  empty?: ReactNode;
  sortable?: boolean;
  defaultSort?: Sort;
  maxHeight?: number;
  selectedId?: string | number | null;
  onRowClick?: ( row: Row ) => void;
  footer?: ReactNode;
  fixed?: boolean;
  dense?: boolean;
  bare?: boolean;
  title?: ReactNode;
  titleRight?: ReactNode;
  views?: SavedView[];
  activeView?: string;
  onViewChange?: ( id: string ) => void;
  onSaveView?: () => void;
  search?: string;
  searchPlaceholder?: string;
  onSearch?: ( value: string ) => void;
  filters?: Filter[];
  onFilter?: ( id: string, value: string | null ) => void;
  onClearFilters?: () => void;
  toolbar?: ReactNode;
  selectable?: boolean;
  /** Ids of the selected rows. */
  selection?: Array< string | number >;
  onSelectionChange?: ( ids: Array< string | number > ) => void;
  bulkNoun?: string;
  bulkActions?: ReactNode;
  testId?: string;
} ) {
  const [ sort, setSort ] = useState< Sort | null >( defaultSort ?? null );

  const sorted = useMemo( () => {
    if ( ! sort ) return rows;
    const column = columns.find( ( c ) => c.key === sort.key );
    if ( ! column ) return rows;
    const out = rows.slice().sort( ( a, b ) => {
      const av = sortValue( a, column );
      const bv = sortValue( b, column );
      return av < bv ? -1 : av > bv ? 1 : 0;
    } );
    return 'desc' === sort.dir ? out.reverse() : out;
  }, [ rows, sort, columns ] );

  const toggleSort = ( column: Column< Row > ) => {
    if ( ! sortable || false === column.sortable || ! column.label ) return;
    setSort( ( s ) =>
      ! s || s.key !== column.key ? { key: column.key, dir: 'asc' } : 'asc' === s.dir ? { key: column.key, dir: 'desc' } : null
    );
  };

  const rowId = ( row: Row, i: number ) => row.id ?? i;
  const ids = rows.map( rowId );
  const allOn = rows.length > 0 && selection.length === rows.length;
  const someOn = selection.length > 0 && ! allOn;
  const activeFilters = ( filters ?? [] ).filter( ( f ) => !! f.value ).length;
  const pick = ( id: string | number ) => {
    if ( ! onSelectionChange ) return;
    onSelectionChange( selection.includes( id ) ? selection.filter( ( x ) => x !== id ) : selection.concat( id ) );
  };

  return (
    <div className="fk-dataview" data-bare={ bare ? 'true' : undefined } data-testid={ testId }>
      { title && (
        <div className="fk-dataview-title">
          <h3>{ title }</h3>
          { titleRight && <span className="fk-dataview-title-right">{ titleRight }</span> }
        </div>
      ) }

      { views && (
        <div className="fk-dataview-views" role="group" aria-label="Saved views">
          { views.map( ( view ) => (
            <ViewPill key={ view.id } label={ view.label } count={ view.count } active={ view.id === activeView } onClick={ () => onViewChange?.( view.id ) } />
          ) ) }
          { onSaveView && <ViewPill label="Save this view" dashed onClick={ onSaveView } /> }
        </div>
      ) }

      { ( onSearch || filters || toolbar ) && (
        <div className="fk-dataview-tools">
          { onSearch && (
            <span className="fk-search-wrap">
              <Search size={ 16 } strokeWidth={ 1.75 } aria-hidden="true" />
              <input
                type="search"
                className="fk-search"
                value={ search ?? '' }
                placeholder={ searchPlaceholder }
                aria-label={ searchPlaceholder }
                onChange={ ( e ) => onSearch( e.target.value ) }
              />
            </span>
          ) }
          { ( filters ?? [] ).map( ( filter ) => (
            <FilterPill key={ filter.id } filter={ filter } onPick={ ( id, value ) => onFilter?.( id, value ) } />
          ) ) }
          { activeFilters > 0 && onClearFilters && (
            <button type="button" className="fk-clear-filters" onClick={ onClearFilters }>
              Clear { activeFilters } filter{ 1 === activeFilters ? '' : 's' }
            </button>
          ) }
          { toolbar && <span className="fk-dataview-toolbar">{ toolbar }</span> }
        </div>
      ) }

      <div className="fk-dataview-scroll" data-capped={ maxHeight ? 'true' : undefined } style={ { maxHeight } }>
        <table className="fk-table" data-dense={ dense ? 'true' : 'false' } data-fixed={ fixed ? 'true' : undefined }>
          <thead>
            <tr>
              { selectable && (
                <th className="fk-check-cell">
                  <Check
                    checked={ allOn }
                    indeterminate={ someOn }
                    label="Select all rows"
                    onChange={ () => onSelectionChange?.( allOn ? [] : ids ) }
                  />
                </th>
              ) }
              { columns.map( ( column ) => {
                const active = sort?.key === column.key;
                const canSort = sortable && false !== column.sortable && !! column.label;
                return (
                  <th
                    key={ column.key }
                    data-align={ column.align }
                    data-sortable={ canSort ? 'true' : undefined }
                    aria-sort={ active ? ( 'asc' === sort.dir ? 'ascending' : 'descending' ) : undefined }
                    style={ { width: column.width } }
                  >
                    { canSort ? (
                      <button type="button" className="fk-th-inner fk-sort-btn" onClick={ () => toggleSort( column ) }>
                        { column.label }
                        { active && (
                          <span className="fk-sort-glyph" aria-hidden="true">
                            { 'asc' === sort.dir ? '▲' : '▼' }
                          </span>
                        ) }
                      </button>
                    ) : (
                      <span className="fk-th-inner">{ column.label }</span>
                    ) }
                  </th>
                );
              } ) }
            </tr>
          </thead>
          <tbody>
            { 0 === sorted.length && (
              <tr>
                <td className="fk-table-empty" colSpan={ columns.length + ( selectable ? 1 : 0 ) }>
                  { empty }
                </td>
              </tr>
            ) }
            { sorted.map( ( row, i ) => {
              const id = rowId( row, i );
              const selected = ( null != selectedId && row.id === selectedId ) || selection.includes( id );
              const clickable = !! onRowClick;
              return (
                <tr
                  key={ id }
                  className="fk-row"
                  tabIndex={ clickable ? 0 : undefined }
                  aria-selected={ selected ? 'true' : undefined }
                  onClick={ clickable ? () => onRowClick( row ) : undefined }
                  onKeyDown={
                    clickable
                      ? ( event ) => {
                          if ( 'Enter' === event.key || ' ' === event.key ) {
                            event.preventDefault();
                            onRowClick( row );
                          }
                        }
                      : undefined
                  }
                >
                  { selectable && (
                    <td className="fk-check-cell">
                      <Check checked={ selection.includes( id ) } label={ `Select row ${ i + 1 }` } onChange={ () => pick( id ) } />
                    </td>
                  ) }
                  { columns.map( ( column ) => {
                    const cell = column.render ? column.render( row ) : ( row[ column.key ] as ReactNode );
                    return (
                      <td key={ column.key } data-align={ column.align } data-mono={ column.mono ? 'true' : undefined } data-wrap={ column.wrap ? 'true' : undefined }>
                        { column.clamp ? (
                          <span className="fk-clamp" style={ { WebkitLineClamp: column.clamp } }>
                            { cell }
                          </span>
                        ) : (
                          cell
                        ) }
                      </td>
                    );
                  } ) }
                </tr>
              );
            } ) }
          </tbody>
        </table>
      </div>

      { footer && (
        <div className="fk-dataview-footer" data-testid="fk-dataview-footer">
          { footer }
        </div>
      ) }

      { selectable && selection.length > 0 && (
        <div className="fk-bulk-bar" role="status" data-testid="fk-bulk-bar">
          <span>
            <span className="fk-mono">{ selection.length }</span>
            { ` ${ bulkNoun }${ 1 === selection.length ? '' : 's' } selected` }
          </span>
          <span className="fk-bulk-actions">{ bulkActions }</span>
          <button type="button" className="fk-bulk-close" aria-label="Clear selection" onClick={ () => onSelectionChange?.( [] ) }>
            ×
          </button>
        </div>
      ) }
    </div>
  );
}
