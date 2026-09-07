import React from 'react';

export function Tabs({ items = [], value, onChange, inset = false, className = '' }) {
  return (
    <div className={['bw-tabs', inset ? 'bw-tabs--inset' : '', className].filter(Boolean).join(' ')} role="tablist">
      {items.map((raw) => {
        const item = typeof raw === 'string' ? { id: raw, label: raw } : raw;
        return (
          <button
            key={item.id}
            type="button"
            role="tab"
            aria-selected={item.id === value}
            className={`bw-tab ${item.id === value ? 'is-active' : ''}`}
            onClick={() => onChange && onChange(item.id)}
          >
            {item.label}
            {item.count != null ? <span className="bw-tab__count">{item.count}</span> : null}
          </button>
        );
      })}
    </div>
  );
}
