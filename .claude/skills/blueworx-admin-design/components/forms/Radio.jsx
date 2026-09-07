import React from 'react';

export function Radio({ label, help, disabled = false, className = '', ...rest }) {
  return (
    <label className={`bw-check ${className}`} data-disabled={disabled || undefined}>
      <input type="radio" disabled={disabled} {...rest} />
      <span className="bw-check__text">
        <span>{label}</span>
        {help ? <span className="bw-check__help">{help}</span> : null}
      </span>
    </label>
  );
}

export function RadioGroup({ row = false, children, className = '' }) {
  return <div className={['bw-radiogroup', row ? 'bw-radiogroup--row' : '', className].filter(Boolean).join(' ')} role="radiogroup">{children}</div>;
}
