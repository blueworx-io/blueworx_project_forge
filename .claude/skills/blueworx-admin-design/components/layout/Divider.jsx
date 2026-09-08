import React from 'react';

export function Divider({ label, tight = false, className = '' }) {
  if (label) return <div className={`bw-divider__labelled ${className}`}>{label}</div>;
  return <hr className={['bw-divider', tight ? 'bw-divider--tight' : '', className].filter(Boolean).join(' ')} />;
}
