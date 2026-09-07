import React from 'react';
import { Icon } from '../core/Icon.jsx';
import { IconButton } from '../core/IconButton.jsx';

const ICONS = { info: 'info', success: 'circle-check', warning: 'triangle-alert', danger: 'circle-alert', accent: 'lightbulb' };
const COLORS = {
  info: 'var(--bw-info-deep)', success: 'var(--bw-success-deep)', warning: 'var(--bw-warning-deep)',
  danger: 'var(--bw-danger-deep)', accent: 'var(--bw-text-accent)',
};

export function Notice({ tone = 'info', title, children, actions, icon, onDismiss, className = '' }) {
  return (
    <div className={`bw-notice bw-notice--${tone} ${className}`} role={tone === 'danger' ? 'alert' : 'status'}>
      <Icon name={icon || ICONS[tone]} size={18} color={COLORS[tone]} className="bw-notice__icon" />
      <div className="bw-notice__body">
        {title ? <p className="bw-notice__title">{title}</p> : null}
        {children ? <p className="bw-notice__text">{children}</p> : null}
        {actions ? <div className="bw-notice__actions">{actions}</div> : null}
      </div>
      {onDismiss ? <IconButton icon="x" label="Dismiss" size="sm" onClick={onDismiss} /> : null}
    </div>
  );
}
