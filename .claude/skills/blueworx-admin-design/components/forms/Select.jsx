import React from 'react';
import { Icon } from '../core/Icon.jsx';

export function Select({ options = [], placeholder, invalid = false, className = '', children, ...rest }) {
  return (
    <span className={`bw-select ${className}`}>
      <select className={['bw-select__el', invalid ? 'bw-select__el--invalid' : ''].filter(Boolean).join(' ')} {...rest}>
        {placeholder ? <option value="">{placeholder}</option> : null}
        {children || options.map((o) => {
          const opt = typeof o === 'string' ? { value: o, label: o } : o;
          return <option key={opt.value} value={opt.value} disabled={opt.disabled}>{opt.label}</option>;
        })}
      </select>
      <Icon name="chevron-down" size={14} className="bw-select__arrow" />
    </span>
  );
}
