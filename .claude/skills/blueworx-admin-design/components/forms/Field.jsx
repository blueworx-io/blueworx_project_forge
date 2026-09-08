import React from 'react';

export function Field({ label, htmlFor, help, error, required = false, wide = false, children, className = '' }) {
  return (
    <div className={['bw-field', wide ? 'bw-field--wide' : '', className].filter(Boolean).join(' ')}>
      {label ? (
        <label className="bw-field__label" htmlFor={htmlFor}>
          {label}{required ? <span className="bw-field__req">*</span> : null}
        </label>
      ) : null}
      {children}
      {error ? <p className="bw-field__error">{error}</p> : help ? <p className="bw-field__help">{help}</p> : null}
    </div>
  );
}
