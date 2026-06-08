import { X, Plus, ExternalLink } from 'lucide-react';
import { ItemLink } from '../types';

const C = { border: '#e2e8f0', fg: '#1a1f36', mutedFg: '#64748b', white: '#ffffff', primary: '#2563eb' };

export function LinksEditor( { links, onChange }: { links: ItemLink[]; onChange: ( next: ItemLink[] ) => void } ) {
  const update = ( i: number, patch: Partial<ItemLink> ) =>
    onChange( links.map( ( l, idx ) => idx === i ? { ...l, ...patch } : l ) );
  const remove = ( i: number ) => onChange( links.filter( ( _, idx ) => idx !== i ) );
  const add = () => onChange( [ ...links, { label: '', url: '' } ] );

  const input: React.CSSProperties = { padding: '7px 9px', borderRadius: 6, border: `1px solid ${ C.border }`, fontSize: 13, color: C.fg, outline: 'none', background: C.white, boxSizing: 'border-box' };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      { links.map( ( link, i ) => (
        <div key={ i } style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
          <input type="text" placeholder="Label" value={ link.label } onChange={ e => update( i, { label: e.target.value } ) } style={{ ...input, width: 140, flexShrink: 0 }} />
          <input type="url" placeholder="https://…" value={ link.url } onChange={ e => update( i, { url: e.target.value } ) } style={{ ...input, flex: 1, minWidth: 0 }} />
          <button type="button" onClick={ () => remove( i ) } title="Remove link" style={{ background: 'none', border: 'none', cursor: 'pointer', color: C.mutedFg, display: 'flex', flexShrink: 0 }}>
            <X size={ 16 } />
          </button>
        </div>
      ) ) }
      <button type="button" onClick={ add } style={{ display: 'inline-flex', alignItems: 'center', gap: 6, alignSelf: 'flex-start', padding: '6px 12px', borderRadius: 6, fontSize: 13, fontWeight: 500, border: `1px solid ${ C.border }`, background: C.white, color: C.fg, cursor: 'pointer' }}>
        <Plus size={ 14 } /> Add link
      </button>
    </div>
  );
}

export function LinksDisplay( { links }: { links: ItemLink[] } ) {
  if ( ! links || links.length === 0 ) return null;
  return (
    <div className="flex flex-col gap-2">
      { links.map( ( link, i ) => (
        <a key={ i } href={ link.url } target="_blank" rel="noopener noreferrer"
          className="flex items-center gap-2 p-2.5 rounded-lg border border-border hover:bg-accent transition-colors text-sm text-blue-700">
          <ExternalLink className="w-4 h-4 flex-shrink-0" />
          <span className="truncate">{ link.label?.trim() || link.url }</span>
        </a>
      ) ) }
    </div>
  );
}
