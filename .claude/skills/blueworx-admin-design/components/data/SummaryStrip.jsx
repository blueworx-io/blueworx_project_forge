import React from 'react';

/** The persistent band of derived figures under an editor's page header. */
export function SummaryStrip({ cells = [], className = '' }) {
  if (cells.length === 0) return null;
  return (
    <div className={`bw-summary ${className}`}>
      {cells.map((cell) => (
        <div className="bw-summary__cell" key={cell.label}>
          <span className="bw-summary__label">{cell.label}</span>
          <span className="bw-summary__value">{cell.value}</span>
          {cell.foot ? <span className="bw-summary__foot">{cell.foot}</span> : null}
        </div>
      ))}
    </div>
  );
}
