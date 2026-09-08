import React from 'react';

export function Spinner({ size = 16, className = '', label }) {
  const el = <span className={`bw-spinner ${className}`} style={{ width: size, height: size }} role={label ? undefined : 'presentation'} />;
  if (!label) return el;
  return <span className="bw-loading" role="status">{el}{label}</span>;
}
