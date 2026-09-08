import React from 'react';

export function Breadcrumbs({ items = [], onNavigate, className = '' }) {
  return (
    <nav className={`bw-crumbs ${className}`} aria-label="Breadcrumb">
      {items.map((raw, i) => {
        const item = typeof raw === 'string' ? { label: raw } : raw;
        const last = i === items.length - 1;
        return (
          <React.Fragment key={item.id || item.label}>
            {i > 0 ? <span className="bw-crumbs__sep" aria-hidden="true">/</span> : null}
            {last
              ? <span className="bw-crumbs__current" aria-current="page">{item.label}</span>
              : item.href
                ? <a href={item.href}>{item.label}</a>
                : <button type="button" onClick={() => onNavigate && onNavigate(item.id || item.label)}>{item.label}</button>}
          </React.Fragment>
        );
      })}
    </nav>
  );
}
