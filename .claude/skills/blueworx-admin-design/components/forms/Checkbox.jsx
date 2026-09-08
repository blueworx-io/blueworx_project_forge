import React from 'react';

export function Checkbox({ label, help, disabled = false, className = '', ...rest }) {
  return (
    <label className={`bw-check ${className}`} data-disabled={disabled || undefined}>
      <input type="checkbox" disabled={disabled} {...rest} />
      <span className="bw-check__text">
        <span>{label}</span>
        {help ? <span className="bw-check__help">{help}</span> : null}
      </span>
    </label>
  );
}
