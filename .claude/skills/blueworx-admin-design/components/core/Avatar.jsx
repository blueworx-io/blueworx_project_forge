import React from 'react';

function initials(name = '') {
  return name.trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
}

export function Avatar({ name = '', src, size = 32, className = '' }) {
  return (
    <span className={`bw-avatar ${className}`} style={{ width: size, height: size, fontSize: Math.round(size * 0.36) }} title={name || undefined}>
      {src ? <img src={src} alt={name} /> : initials(name)}
    </span>
  );
}

/** Avatar + name + secondary line — the first cell of most WordPress user tables. */
export function Person({ name, secondary, src, size = 32, className = '' }) {
  return (
    <span className={`bw-person ${className}`}>
      <Avatar name={name} src={src} size={size} />
      <span style={{ minWidth: 0 }}>
        <span className="bw-person__name">{name}</span>
        {secondary ? <span className="bw-person__sub">{secondary}</span> : null}
      </span>
    </span>
  );
}
