import React from 'react';
import { IconButton } from '../core/IconButton.jsx';

export function Modal({ open = true, title, subtitle, footer, wide = false, onClose, children, className = '' }) {
  if (!open) return null;
  return (
    <div className="bw-scrim" onClick={onClose}>
      <div
        className={['bw-modal', wide ? 'bw-modal--wide' : '', className].filter(Boolean).join(' ')}
        role="dialog"
        aria-modal="true"
        aria-label={typeof title === 'string' ? title : undefined}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="bw-modal__head">
          <div>
            <h2 className="bw-modal__title">{title}</h2>
            {subtitle ? <p className="bw-modal__sub">{subtitle}</p> : null}
          </div>
          {onClose ? <IconButton icon="x" label="Close" onClick={onClose} /> : null}
        </div>
        <div className="bw-modal__body">{children}</div>
        {footer ? <div className="bw-modal__foot">{footer}</div> : null}
      </div>
    </div>
  );
}
