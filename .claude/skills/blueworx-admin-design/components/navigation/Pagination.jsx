import React from 'react';
import { Icon } from '../core/Icon.jsx';

function pages(current, total) {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
  const out = [1];
  const from = Math.max(2, current - 1);
  const to = Math.min(total - 1, current + 1);
  if (from > 2) out.push('…');
  for (let p = from; p <= to; p += 1) out.push(p);
  if (to < total - 1) out.push('…');
  out.push(total);
  return out;
}

export function Pagination({ page = 1, totalPages = 1, totalItems, onChange, compact = false, className = '' }) {
  const go = (p) => onChange && onChange(Math.min(totalPages, Math.max(1, p)));
  return (
    <div className={`bw-pager ${className}`}>
      {totalItems != null ? <span className="bw-pager__count">{totalItems.toLocaleString()} items</span> : null}
      <div className="bw-pager__btns">
        <button type="button" className="bw-pager__btn" aria-label="Previous page" disabled={page <= 1} onClick={() => go(page - 1)}>
          <Icon name="arrow-left" size={14} />
        </button>
        {compact ? (
          <span className="bw-pager__of" style={{ padding: '0 6px', alignSelf: 'center' }}>{page} of {totalPages}</span>
        ) : pages(page, totalPages).map((p, i) => (
          p === '…'
            ? <span key={`gap${i}`} className="bw-pager__of" style={{ padding: '0 4px', alignSelf: 'center' }}>…</span>
            : <button key={p} type="button" className={`bw-pager__btn ${p === page ? 'is-current' : ''}`} aria-current={p === page || undefined} onClick={() => go(p)}>{p}</button>
        ))}
        <button type="button" className="bw-pager__btn" aria-label="Next page" disabled={page >= totalPages} onClick={() => go(page + 1)}>
          <Icon name="arrow-right" size={14} />
        </button>
      </div>
    </div>
  );
}
