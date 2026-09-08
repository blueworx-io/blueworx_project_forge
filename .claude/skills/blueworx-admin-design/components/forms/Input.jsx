import React from 'react';
import { Icon } from '../core/Icon.jsx';

export function Input({ icon, affix, mono = false, invalid = false, size = 'md', className = '', ...rest }) {
  const cls = ['bw-input', mono ? 'bw-input--mono' : '', invalid ? 'bw-input--invalid' : '', size === 'sm' ? 'bw-input--sm' : '', className].filter(Boolean).join(' ');
  const el = <input className={cls} aria-invalid={invalid || undefined} {...rest} />;
  if (!icon && !affix) return el;
  return (
    <span className="bw-inputwrap">
      {icon ? <Icon name={icon} size={16} className="bw-inputwrap__icon" /> : null}
      {el}
      {affix ? <span className="bw-inputwrap__affix">{affix}</span> : null}
    </span>
  );
}
