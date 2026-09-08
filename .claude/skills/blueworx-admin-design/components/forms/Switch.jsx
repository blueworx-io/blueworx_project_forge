import React from 'react';

export function Switch({ label, help, bare = false, disabled = false, className = '', ...rest }) {
  return (
    <label className={['bw-switch', bare ? 'bw-switch--bare' : '', className].filter(Boolean).join(' ')}>
      <input type="checkbox" role="switch" disabled={disabled} {...rest} />
      <span className="bw-switch__track"><span className="bw-switch__thumb" /></span>
      {label ? <span className="bw-switch__label">{label}{help ? <small>{help}</small> : null}</span> : null}
    </label>
  );
}
