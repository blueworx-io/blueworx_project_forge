import React from 'react';

/** The small "?" bubble beside a label. */
export function HelpTip({ children, below = false, className = '' }) {
  const [show, setShow] = React.useState(false);
  return (
    <span className={`bw-helptip ${className}`} onMouseEnter={() => setShow(true)} onMouseLeave={() => setShow(false)}>
      <button type="button" className="bw-helptip__btn" aria-label="More information" onFocus={() => setShow(true)} onBlur={() => setShow(false)}>?</button>
      {show ? <span className={`bw-tip ${below ? 'bw-tip--below' : ''}`} role="tooltip">{children}</span> : null}
    </span>
  );
}
