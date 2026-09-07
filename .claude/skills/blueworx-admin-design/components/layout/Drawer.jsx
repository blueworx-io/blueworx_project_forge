import React from 'react';
import { IconButton } from '../core/IconButton.jsx';

export function Drawer({ open = true, title, subtitle, footer, wide = false, onClose, children, className = '' }) {
  if (!open) return null;
  return (
    <>
      <div className="bw-scrim" style={{ padding: 0 }} onClick={onClose} />
      <aside className={['bw-drawer', wide ? 'bw-drawer--wide' : '', className].filter(Boolean).join(' ')} role="dialog" aria-modal="true" aria-label={typeof title === 'string' ? title : undefined}>
        <div className="bw-drawer__head">
          <div>
            <h2 className="bw-drawer__title">{title}</h2>
            {subtitle ? <p className="bw-drawer__sub">{subtitle}</p> : null}
          </div>
          {onClose ? <IconButton icon="x" label="Close" onClick={onClose} /> : null}
        </div>
        <div className="bw-drawer__body">{children}</div>
        {footer ? <div className="bw-drawer__foot">{footer}</div> : null}
      </aside>
    </>
  );
}
