import React from 'react';

export function SectionNav({ items = [], value, onChange, className = '' }) {
  return (
    <nav className={`bw-secnav ${className}`} aria-label="Sections">
      {items.map((raw) => {
        const item = typeof raw === 'string' ? { id: raw, label: raw } : raw;
        return (
          <button
            key={item.id}
            type="button"
            className={`bw-secnav__item ${item.id === value ? 'is-active' : ''}`}
            aria-current={item.id === value || undefined}
            onClick={() => onChange && onChange(item.id)}
          >
            <span>{item.label}</span>
            {item.meta != null ? <span className="bw-secnav__meta">{item.meta}</span> : null}
          </button>
        );
      })}
    </nav>
  );
}
