import React from 'react';
import { Icon } from '../core/Icon.jsx';

/** items: [{ id, label, icon?, danger?, disabled? } | { separator: true } | { label, heading: true }] */
export function DropdownMenu({ trigger, items = [], align = 'right', onSelect, className = '' }) {
  const [open, setOpen] = React.useState(false);
  const ref = React.useRef(null);

  React.useEffect(() => {
    if (!open) return undefined;
    const close = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    const esc = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', esc);
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', esc); };
  }, [open]);

  return (
    <span className={`bw-menu ${className}`} ref={ref}>
      <span onClick={() => setOpen((v) => !v)} aria-haspopup="menu" aria-expanded={open}>{trigger}</span>
      {open ? (
        <div className={`bw-menu__panel bw-menu__panel--${align}`} role="menu">
          {items.map((item, i) => {
            if (item.separator) return <div key={`s${i}`} className="bw-menu__sep" />;
            if (item.heading) return <div key={`h${i}`} className="bw-menu__label">{item.label}</div>;
            return (
              <button
                key={item.id || item.label}
                type="button"
                role="menuitem"
                disabled={item.disabled}
                className={`bw-menu__item ${item.danger ? 'bw-menu__item--danger' : ''}`}
                onClick={() => { setOpen(false); onSelect && onSelect(item.id || item.label); item.onClick && item.onClick(); }}
              >
                {item.icon ? <Icon name={item.icon} size={16} /> : null}
                {item.label}
              </button>
            );
          })}
        </div>
      ) : null}
    </span>
  );
}
