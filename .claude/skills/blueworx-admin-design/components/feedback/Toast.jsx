import React from 'react';
import { Icon } from '../core/Icon.jsx';

export function Toast({ children, action, actionLabel, onDismiss, icon, className = '' }) {
  return (
    <div className={`bw-toast ${className}`} role="status">
      {icon ? <Icon name={icon} size={16} /> : null}
      <span className="bw-toast__text">{children}</span>
      {action ? <button type="button" className="bw-toast__action" onClick={action}>{actionLabel || 'Undo'}</button> : null}
      {onDismiss ? <button type="button" className="bw-toast__close" aria-label="Dismiss" onClick={onDismiss}><Icon name="x" size={16} /></button> : null}
    </div>
  );
}

/** Bottom-right stack. Render once per screen. */
export function ToastStack({ children, className = '' }) {
  return <div className={`bw-toasts ${className}`}>{children}</div>;
}
