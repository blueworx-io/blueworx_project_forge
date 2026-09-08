import React from 'react';

export function Skeleton({ variant = 'text', width, height, lines = 1, className = '' }) {
  if (variant === 'text' && lines > 1) {
    return (
      <span className={`bw-skel-stack ${className}`}>
        {Array.from({ length: lines }, (_, i) => (
          <span key={i} className="bw-skel bw-skel--text" style={{ width: i === lines - 1 ? '60%' : width || '100%' }} />
        ))}
      </span>
    );
  }
  return <span className={`bw-skel bw-skel--${variant} ${className}`} style={{ width, height }} />;
}
