import React from 'react';

/** items: [[term, value], …] or [{ term, value }] */
export function DescriptionList({ items = [], stack = false, className = '' }) {
  const rows = items.map((i) => (Array.isArray(i) ? { term: i[0], value: i[1] } : i));
  return (
    <dl className={['bw-dl', stack ? 'bw-dl--stack' : '', className].filter(Boolean).join(' ')}>
      {rows.map((r) => (
        <React.Fragment key={r.term}>
          <dt>{r.term}</dt>
          <dd>{r.value}</dd>
        </React.Fragment>
      ))}
    </dl>
  );
}
