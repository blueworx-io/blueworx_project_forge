import React from 'react';

export function Badge({ children, tone = 'neutral', dot = false, className = '', ...rest }) {
  return (
    <span className={`bw-badge bw-badge--${tone} ${className}`} {...rest}>
      {dot ? <span className="bw-badge__dot" /> : null}
      {children}
    </span>
  );
}
