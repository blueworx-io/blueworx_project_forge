import type { ReactNode } from 'react';

/**
 * Hours against an allocation: used, reserved, and what is left. The figures
 * arrive already worked out — nothing here does arithmetic on money except
 * the width of a bar.
 */
export function Meter( {
  allocation,
  used,
  reserved = 0,
  label,
  note,
  warn,
}: {
  allocation: number;
  used: number;
  reserved?: number;
  label: ReactNode;
  note?: ReactNode;
  warn?: boolean;
} ) {
  const pct = ( n: number ) => `${ Math.max( 0, Math.min( 100, allocation > 0 ? ( n / allocation ) * 100 : 0 ) ) }%`;
  const available = ( allocation - used - reserved ).toFixed( 1 );

  return (
    <div className="fk-meter" data-warn={ warn ? 'true' : undefined }>
      <div className="fk-meter-head">
        <span>{ label }</span>
        <span className="fk-meter-avail">{ available }h available</span>
      </div>
      <div
        className="fk-meter-track"
        role="meter"
        aria-valuemin={ 0 }
        aria-valuemax={ allocation }
        aria-valuenow={ used + reserved }
        aria-label={ `${ used }h used and ${ reserved }h reserved of ${ allocation }h` }
      >
        <span className="fk-meter-used" style={ { width: pct( used ) } } />
        <span className="fk-meter-reserved" style={ { width: pct( reserved ) } } />
      </div>
      <div className="fk-meter-legend">
        <span>
          <span className="fk-legend-swatch" style={ { background: 'var(--brand-600)' } } aria-hidden="true" />
          Used { used }h
        </span>
        <span>
          <span className="fk-legend-swatch" style={ { background: 'var(--accent-mint)' } } aria-hidden="true" />
          Reserved { reserved }h
        </span>
        <span>
          <span className="fk-legend-swatch" style={ { background: 'var(--paper-stone)' } } aria-hidden="true" />
          Allocation { allocation }h
        </span>
        { note && <span className="fk-meter-note">{ note }</span> }
      </div>
    </div>
  );
}
