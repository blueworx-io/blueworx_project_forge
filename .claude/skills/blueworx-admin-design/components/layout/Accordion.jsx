import React from 'react';
import { Icon } from '../core/Icon.jsx';

export function Accordion({ title, subtitle, aside, defaultOpen = false, open: controlled, onToggle, children, className = '' }) {
  const [local, setLocal] = React.useState(defaultOpen);
  const open = controlled != null ? controlled : local;
  const toggle = () => { if (controlled == null) setLocal(!open); if (onToggle) onToggle(!open); };
  return (
    <section className={['bw-accordion', open ? 'is-open' : '', className].filter(Boolean).join(' ')}>
      <button type="button" className="bw-accordion__head" aria-expanded={open} onClick={toggle}>
        <span className="bw-accordion__title">
          {title}
          {subtitle ? <span className="bw-accordion__sub">{subtitle}</span> : null}
        </span>
        {aside}
        <Icon name="chevron-down" size={16} className="bw-accordion__chev" />
      </button>
      {open ? <div className="bw-accordion__body">{children}</div> : null}
    </section>
  );
}
