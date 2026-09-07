import React from 'react';
import { Icon } from '../core/Icon.jsx';

export function EmptyState({ icon = 'archive', title, children, actions, className = '' }) {
  return (
    <div className={`bw-empty ${className}`}>
      <Icon name={icon} size={28} className="bw-empty__icon" />
      <h3 className="bw-empty__title">{title}</h3>
      {children ? <p className="bw-empty__text">{children}</p> : null}
      {actions ? <div className="bw-empty__actions">{actions}</div> : null}
    </div>
  );
}
