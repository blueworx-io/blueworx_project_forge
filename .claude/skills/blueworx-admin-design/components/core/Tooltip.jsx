import React from 'react';

export function Tooltip({ label, below = false, children, className = '' }) {
  const [show, setShow] = React.useState(false);
  return (
    <span
      className={`bw-tooltip ${className}`}
      onMouseEnter={() => setShow(true)}
      onMouseLeave={() => setShow(false)}
      onFocus={() => setShow(true)}
      onBlur={() => setShow(false)}
    >
      {children}
      {show ? <span className={`bw-tip ${below ? 'bw-tip--below' : ''}`} role="tooltip">{label}</span> : null}
    </span>
  );
}
