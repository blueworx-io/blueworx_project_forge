import React from 'react';
import { Icon } from './Icon.jsx';

export function Chip({ children, tone = 'accent', onRemove, className = '', ...rest }) {
  return (
    <span className={['bw-chip', tone === 'plain' ? 'bw-chip--plain' : '', className].filter(Boolean).join(' ')} {...rest}>
      {children}
      {onRemove ? (
        <button type="button" className="bw-chip__x" aria-label="Remove" onClick={onRemove}>
          <Icon name="x" size={14} />
        </button>
      ) : null}
    </span>
  );
}
