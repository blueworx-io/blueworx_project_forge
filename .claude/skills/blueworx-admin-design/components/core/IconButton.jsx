import React from 'react';
import { Icon } from './Icon.jsx';

export function IconButton({ icon, label, variant = 'ghost', size = 'md', disabled = false, className = '', ...rest }) {
  return (
    <button
      type="button"
      title={label}
      aria-label={label}
      disabled={disabled}
      className={['bw-iconbtn', `bw-iconbtn--${variant}`, size === 'sm' ? 'bw-iconbtn--sm' : '', className].filter(Boolean).join(' ')}
      {...rest}
    >
      <Icon name={icon} size={size === 'sm' ? 14 : 18} />
    </button>
  );
}
