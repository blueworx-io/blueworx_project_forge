import React from 'react';

/** The WordPress list-table pattern: text links under a row title, separated by pipes. */
export function RowActions({ actions = [], className = '' }) {
  return (
    <span className={`bw-rowactions ${className}`}>
      {actions.map((a, i) => (
        <React.Fragment key={a.id || a.label}>
          {i > 0 ? <span className="bw-rowactions__sep" aria-hidden="true">|</span> : null}
          {a.href
            ? <a className={`bw-rowactions__link ${a.danger ? 'bw-rowactions__link--danger' : ''}`} href={a.href}>{a.label}</a>
            : <button type="button" className={`bw-rowactions__link ${a.danger ? 'bw-rowactions__link--danger' : ''}`} onClick={a.onClick}>{a.label}</button>}
        </React.Fragment>
      ))}
    </span>
  );
}
